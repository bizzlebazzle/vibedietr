<?php

namespace Tests\Feature\AccountDeletion;

use App\Domain\AccountDeletion\AccountLifecycle;
use App\Domain\AccountDeletion\ErasureJournalState;
use App\Domain\AccountDeletion\ProtectedErasureJournal;
use App\Domain\AccountDeletion\PurgeAccount;
use App\Domain\RecipeImports\Images\CanonicalImage;
use App\Domain\RecipeImports\Images\ImageCanonicalizerContract;
use App\Domain\RecipeImports\RecipeImportInputCleaner;
use App\Domain\RecipeImports\RecipeImportType;
use App\Domain\RecipeImports\UploadedRecipeImportProcessor;
use App\Jobs\ProcessUploadedRecipeImport;
use App\Models\AccountExport;
use App\Models\RecipeImport;
use App\Models\User;
use App\Queue\Exceptions\RetryableJobException;
use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Mockery\Expectation;
use Mockery\LegacyMockInterface;
use RuntimeException;
use Tests\Concerns\UsesErasureJournal;
use Tests\TestCase;

class InFlightPurgeBoundaryTest extends TestCase
{
    use RefreshDatabase, UsesErasureJournal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();
        Queue::fake();
        Storage::fake('local');
        config(['filesystems.disks.local.serve' => false]);
    }

    public function test_preprocessing_finishing_after_purge_cannot_leave_a_late_canonical_object(): void
    {
        $owner = User::factory()->create();
        $import = $this->image($owner);
        $this->app->instance(ImageCanonicalizerContract::class, new class(function () use ($owner): void {
            $request = app(AccountLifecycle::class)->request($owner, waive: true);
            $this->travel(76)->seconds();
            app(PurgeAccount::class)->purge($request->id);
        }) implements ImageCanonicalizerContract
        {

            public function __construct(private readonly Closure $duringPreprocessing) {}

            public function canonicalize(string $bytes, string $extension, string $detectedMime): CanonicalImage
            {
                ($this->duringPreprocessing)();

                return new CanonicalImage('synthetic canonical bytes', 'image/png', 1, 1, 'synthetic');
            }
        });

        (new ProcessUploadedRecipeImport($import->id))->handle(app(UploadedRecipeImportProcessor::class), app(RecipeImportInputCleaner::class));
        $this->assertModelMissing($owner);
        $this->assertModelMissing($import);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('recipes', 0);
    }

    public function test_failed_canonical_writes_keep_one_durable_cleanup_reservation_across_retries(): void
    {
        $owner = User::factory()->create();
        $import = $this->image($owner);
        $this->app->instance(ImageCanonicalizerContract::class, new class implements ImageCanonicalizerContract
        {
            public function canonicalize(string $bytes, string $extension, string $detectedMime): CanonicalImage
            {
                return new CanonicalImage('synthetic canonical bytes', 'image/png', 1, 1, 'synthetic');
            }
        });
        $real = Storage::disk('local');
        $disk = Mockery::mock(FilesystemAdapter::class, [$real->getDriver(), $real->getAdapter(), $real->getConfig()])->makePartial();
        $this->expectCall($disk, 'put')->twice()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $key = null;
        foreach ([1, 2] as $attempt) {
            try {
                app(UploadedRecipeImportProcessor::class)->process($import->id);
                $this->fail('A failed canonical write completed processing.');
            } catch (RetryableJobException $error) {
                $this->assertSame('canonical_storage_failed', $error->safeErrorCode);
            }
            $current = $import->fresh();
            $this->assertNotNull($current->canonical_key);
            if ($attempt === 1) {
                $key = $current->canonical_key;
            }
            $this->assertSame($key, $current->canonical_key);
            $this->assertFalse($real->exists($key));
        }
        $this->travel(76)->seconds();
        $request = app(AccountLifecycle::class)->request($owner, waive: true);
        app(PurgeAccount::class)->purge($request->id);
        $this->assertModelMissing($owner);
        $this->assertSame([], $real->allFiles());
    }

    public function test_final_purge_waits_for_live_processing_lease_without_extending_erasure_clock(): void
    {
        $owner = User::factory()->create();
        $import = $this->image($owner);
        $import->forceFill(['processing_lease_until' => now()->addSeconds(75)])->save();
        $request = app(AccountLifecycle::class)->request($owner, waive: true);
        $deadline = $request->deadline;
        try {
            app(PurgeAccount::class)->purge($request->id);
            $this->fail('Purge bypassed an active processing lease.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('processing must drain', $error->getMessage());
        }
        $this->assertModelExists($owner);
        $this->assertFalse($owner->isActive());
        $this->assertEquals($deadline, $request->fresh()->deadline);
        Storage::disk('local')->assertExists('inputs/synthetic-image');
        $this->travel(76)->seconds();
        app(PurgeAccount::class)->purge($request->id);
        $this->assertModelMissing($owner);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_successful_delete_response_without_object_absence_cannot_acknowledge_import_purge(): void
    {
        $owner = User::factory()->create();
        $import = $this->image($owner);
        $request = app(AccountLifecycle::class)->request($owner, waive: true);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $this->expectCall($disk, 'exists')->with('inputs/synthetic-image')->twice()->andReturn(true);
        $this->expectCall($disk, 'delete')->with('inputs/synthetic-image')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try {
            app(PurgeAccount::class)->purge($request->id);
            $this->fail('An unverified source deletion was acknowledged.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('cleanup must complete', $error->getMessage());
        }
        $this->assertModelExists($owner);
        $this->assertSame('inputs/synthetic-image', $import->fresh()->source_key);
        $state = app(ErasureJournalState::class);
        $latest = $state->latest($state->at(app(ProtectedErasureJournal::class)->checkpoint()));
        $this->assertSame('purge_started', $latest[$owner->account_generation]['state']);
    }

    public function test_successful_delete_response_without_object_absence_cannot_acknowledge_export_purge(): void
    {
        $owner = User::factory()->create();
        $export = new AccountExport;
        $export->forceFill(['user_id' => $owner->id, 'state' => 'ready', 'disk' => 'local',
            'expires_at' => now()->addDays(7), 'correlation_id' => (string) Str::ulid()])->save();
        $request = app(AccountLifecycle::class)->request($owner, waive: true);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $this->expectCall($disk, 'exists')->with($export->storageKey())->twice()->andReturn(true);
        $this->expectCall($disk, 'delete')->with($export->storageKey())->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try {
            app(PurgeAccount::class)->purge($request->id);
            $this->fail('An unverified archive deletion was acknowledged.');
        } catch (RuntimeException $error) {
            $this->assertSame('export_cleanup_failed', $error->getMessage());
        }
        $this->assertModelExists($owner);
        $this->assertSame('expired', $export->fresh()->state);
    }

    private function expectCall(LegacyMockInterface $mock, string $method): Expectation
    {
        $mock->shouldReceive($method);
        $expectation = $mock->mockery_getExpectationsFor($method)->getExpectations()[0];
        $this->assertInstanceOf(Expectation::class, $expectation);

        return $expectation;
    }

    private function image(User $owner): RecipeImport
    {
        Storage::disk('local')->put('inputs/synthetic-image', 'synthetic image bytes');

        return RecipeImport::factory()->for($owner, 'owner')->create([
            'type' => RecipeImportType::UploadedImage, 'source_text' => null,
            'source_disk' => 'local', 'source_key' => 'inputs/synthetic-image',
            'source_bytes' => strlen('synthetic image bytes'), 'source_mime' => 'image/png',
            'source_extension' => 'png', 'source_stored_at' => now(),
        ]);
    }
}
