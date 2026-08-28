<?php

namespace Tests\Feature;

use App\Security\Parsing\ParsingBudget;
use App\Security\Parsing\ResourceGuard;
use App\Security\Parsing\ResourceLimitException;
use App\Security\Uploads\CleanupOutcome;
use App\Security\Uploads\ContentTypeInspector;
use App\Security\Uploads\TransientInputHandle;
use App\Security\Uploads\TransientInputStore;
use App\Security\Uploads\UploadValidationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class TransientInputSecurityTest extends TestCase
{
    public function test_valid_upload_uses_private_generated_name_and_html_remains_inert_data(): void
    {
        Storage::fake('transient');
        config(['security.uploads.transient_disk' => 'transient']);
        $source = '<script>window.distinctiveSecret = true</script><p>Recipe</p>';
        $file = UploadedFile::fake()->createWithContent('../../private-name.html', $source);

        $handle = app(TransientInputStore::class)->store($file, allowedMimes: ['text/html']);

        $this->assertSame('transient', $handle->disk);
        $this->assertMatchesRegularExpression('~^inputs/[0-9a-z]{26}$~', $handle->key);
        $this->assertStringNotContainsString('private-name', $handle->key);
        $this->assertStringNotContainsString('..', $handle->key);
        $this->assertSame($source, Storage::disk('transient')->get($handle->key));
        $this->assertFalse((bool) config('filesystems.disks.transient.serve'));
    }

    public function test_generic_upload_size_boundary_rejects_before_storage(): void
    {
        Storage::fake('transient');
        config(['security.uploads.transient_disk' => 'transient']);
        $file = UploadedFile::fake()->createWithContent('recipe.txt', '12345678901');

        try {
            app(TransientInputStore::class)->store($file, maxBytes: 10);
            $this->fail('Oversized upload was accepted.');
        } catch (UploadValidationException $exception) {
            $this->assertSame('The uploaded input exceeds the configured size limit.', $exception->getMessage());
        }

        Storage::disk('transient')->assertDirectoryEmpty('/');
    }

    public function test_content_detection_accepts_matching_text_and_rejects_spoofed_binary_claim(): void
    {
        $inspector = app(ContentTypeInspector::class);
        $text = UploadedFile::fake()->createWithContent('recipe.txt', "Ingredients\nRice");
        $inspection = $inspector->inspect($text->getRealPath(), $text->getClientMimeType(), 'txt');
        $this->assertSame('text/plain', $inspection->detectedMime);
        $this->assertTrue($inspection->compatible);
        $inspector->assertAccepted($inspection, ['text/plain']);

        $spoofed = UploadedFile::fake()->create('payload.jpg', 1, 'image/jpeg');
        $spoofedInspection = $inspector->inspect($spoofed->getRealPath(), 'image/jpeg', 'jpg');
        $this->assertFalse($spoofedInspection->compatible);
        $this->expectException(UploadValidationException::class);
        $inspector->assertAccepted($spoofedInspection);
    }

    public function test_cleanup_is_successful_idempotent_and_missing_safe(): void
    {
        Storage::fake('transient');
        config(['security.uploads.transient_disk' => 'transient']);
        $handle = app(TransientInputStore::class)->store(
            UploadedFile::fake()->createWithContent('recipe.txt', 'Recipe'),
        );
        $store = app(TransientInputStore::class);

        $this->assertSame(CleanupOutcome::Deleted, $store->cleanup($handle));
        $this->assertSame(CleanupOutcome::Missing, $store->cleanup($handle));
        $this->assertSame(CleanupOutcome::Missing, $store->cleanup(new TransientInputHandle('transient', 'inputs/missing', 'text/plain', 0)));
    }

    public function test_cleanup_failure_is_safe_and_does_not_throw_path_or_content(): void
    {
        $privatePath = 'inputs/distinctive-private-path';
        Storage::shouldReceive('disk')->once()->with('broken')->andThrow(new RuntimeException('provider detail '.$privatePath));

        $outcome = app(TransientInputStore::class)->cleanup(
            new TransientInputHandle('broken', $privatePath, 'text/plain', 9),
        );

        $this->assertSame(CleanupOutcome::Failed, $outcome);
    }

    public function test_bounded_resource_guard_accepts_normal_input_and_rejects_each_budget(): void
    {
        $guard = app(ResourceGuard::class);
        $budget = new ParsingBudget(10, 10, 2, 2, 100);
        $guard->assertInput('Recipe', $budget);
        $guard->assertItems(2, $budget);
        $guard->assertDepth(2, $budget);
        $guard->assertElapsed(hrtime(true), $budget);

        $this->expectException(ResourceLimitException::class);
        $this->expectExceptionMessage('The input exceeds a configured processing limit.');
        $guard->assertInput('Recipe input is too large', $budget);
    }
}
