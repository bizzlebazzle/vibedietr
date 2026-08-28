<?php

namespace Tests\Feature\Recipes;

use App\Domain\RecipeImports\Images\ImageCanonicalizer;
use App\Domain\RecipeImports\Ocr\GoogleDocumentAiOcrExtractor;
use App\Domain\RecipeImports\Ocr\OcrExtractor;
use App\Domain\RecipeImports\Ocr\OcrResult;
use App\Domain\RecipeImports\Ocr\OcrTextLine;
use App\Domain\RecipeImports\Ocr\TesseractOcrExtractor;
use App\Domain\RecipeImports\RecipeImportStatus;
use App\Domain\RecipeImports\RecipeImportType;
use App\Domain\RecipeImports\UploadedRecipeImportProcessor;
use App\Jobs\ProcessUploadedRecipeImport;
use App\Models\RecipeImport;
use App\Models\User;
use App\Queue\Exceptions\NonRetryableJobException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use ImagickDraw;
use Tests\TestCase;

class UploadedRecipeImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('transient');
        config([
            'security.uploads.transient_disk' => 'transient',
            'production.ocr.preprocessing_version' => 'rec17-test-v1',
            'production.imports.extractor_version' => 'rec17-test-v1',
            'production.imports.parser_version' => 'rec17-test-parser-v1',
        ]);
    }

    public function test_guest_cannot_upload_and_supported_document_uses_generated_private_identifier_only_work(): void
    {
        Queue::fake();
        $source = "Synthetic Soup\nIngredients\n1 cup water\nInstructions\nBoil water.";
        $this->post(route('recipe-imports.upload.store'), [
            'recipe_file' => UploadedFile::fake()->createWithContent('private recipe.txt', $source),
        ])->assertRedirect(route('login'));

        $user = User::factory()->create(['email' => 'private-owner@example.test']);
        $response = $this->actingAs($user)->post(route('recipe-imports.upload.store'), [
            'recipe_file' => UploadedFile::fake()->createWithContent('private-recipe.txt', $source),
        ]);
        $import = RecipeImport::query()->sole();
        $response->assertRedirect(route('recipe-imports.show', $import));
        $this->assertSame(RecipeImportType::UploadedText, $import->type);
        $this->assertMatchesRegularExpression('~^inputs/[0-9a-z]{26}$~', $import->source_key);
        $this->assertStringNotContainsString('private', $import->source_key);
        Storage::disk('transient')->assertExists($import->source_key);
        Queue::assertPushed(ProcessUploadedRecipeImport::class);

        $job = new ProcessUploadedRecipeImport($import->id, $import->correlation_id);
        $payload = serialize($job);
        $this->assertStringNotContainsString($source, $payload);
        $this->assertStringNotContainsString('private recipe.txt', $payload);
        $this->assertStringNotContainsString((string) $import->source_key, $payload);
        $this->assertStringNotContainsString($user->email, $payload);
    }

    public function test_txt_markdown_and_inert_html_create_one_private_draft_and_cleanup_source(): void
    {
        foreach (['txt', 'md', 'html'] as $extension) {
            Queue::fake();
            $user = User::factory()->create();
            $source = $extension === 'html'
                ? '<script>window.privateSecret="do-not-run"</script><style>.x{}</style><img src="https://never.example/image"><h1>Dish</h1><h2>Ingredients</h2><p>1 cup rice</p><h2>Instructions</h2><p>Cook rice.</p>'
                : "Dish\nIngredients\n1 cup rice\nInstructions\nCook rice.";
            $this->actingAs($user)->post(route('recipe-imports.upload.store'), [
                'recipe_file' => UploadedFile::fake()->createWithContent("recipe.{$extension}", $source),
            ])->assertRedirect();
            $import = RecipeImport::query()->where('user_id', $user->id)->sole();
            $job = new ProcessUploadedRecipeImport($import->id, $import->correlation_id);
            app()->call([$job, 'handle']);

            $import->refresh();
            $this->assertSame(RecipeImportStatus::ReviewReady, $import->status, $import->failure_code.'|'.json_encode([$import->source_extension, $import->source_text]));
            $this->assertNull($import->source_key);
            $this->assertNotNull($import->cleanup_completed_at);
            $this->assertSame('private', $import->recipe->visibility->value);
            $this->assertSame('draft', $import->recipe->lifecycle->value);
            $this->assertStringNotContainsString('window.privateSecret', (string) $import->source_text);
            $this->assertStringNotContainsString('never.example', (string) $import->source_text);
            app()->call([$job, 'handle']);
            $this->assertDatabaseCount('recipes', $extension === 'txt' ? 1 : ($extension === 'md' ? 2 : 3));
        }
    }

    public function test_unsupported_mismatched_multiple_and_oversized_documents_are_rejected_before_queueing(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        foreach (['pdf', 'docx', 'rtf', 'doc', 'docm', 'webp', 'tiff'] as $extension) {
            $this->actingAs($user)->post(route('recipe-imports.upload.store'), [
                'recipe_file' => UploadedFile::fake()->createWithContent("recipe.{$extension}", 'unsupported'),
            ])->assertSessionHasErrors('recipe_file');
        }
        $this->actingAs($user)->post(route('recipe-imports.upload.store'), [
            'recipe_file' => UploadedFile::fake()->createWithContent('recipe.txt', "\0binary"),
        ])->assertSessionHasErrors('recipe_file');
        $this->actingAs($user)->post(route('recipe-imports.upload.store'), [
            'recipe_file' => UploadedFile::fake()->createWithContent('recipe.txt', str_repeat('a', 2_097_153)),
        ])->assertSessionHasErrors('recipe_file');
        $this->actingAs($user)->post(route('recipe-imports.upload.store'), [
            'recipe_file' => UploadedFile::fake()->createWithContent('recipe.txt', 'recipe'),
            'second_file' => UploadedFile::fake()->createWithContent('second.txt', 'recipe'),
        ])->assertSessionHasErrors('recipe_file');
        $this->assertDatabaseCount('recipe_imports', 0);
        Queue::assertNothingPushed();
    }

    public function test_image_uses_canonical_private_png_applies_quality_and_never_duplicates_draft(): void
    {
        Queue::fake();
        $this->app->instance(OcrExtractor::class, new class implements OcrExtractor
        {
            public function extract(string $canonicalBytes, string $correlationId): OcrResult
            {
                return new OcrResult(
                    "Photo Dish\nIngredients\n1 cup rice\nInstructions\nCook rice.",
                    [
                        new OcrTextLine('Photo Dish', 'reliable', false, 95),
                        new OcrTextLine('Ingredients', 'reliable', false, 96),
                        new OcrTextLine('1 cup rice', 'uncertain', true, 75),
                        new OcrTextLine('Instructions', 'reliable', false, 97),
                        new OcrTextLine('Cook rice.', 'uncertain', false, 78),
                    ],
                    ['low_confidence_text'], 'fake_tesseract', '5-test', 'eng',
                );
            }
        });
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('recipe-imports.upload.store'), [
            'recipe_file' => UploadedFile::fake()->image('private-photo.png', 640, 480),
        ])->assertRedirect();
        $import = RecipeImport::query()->sole();
        $job = new ProcessUploadedRecipeImport($import->id, $import->correlation_id);
        app(UploadedRecipeImportProcessor::class)->process($import->id);
        app()->call([$job, 'handle']);

        $import->refresh();
        $this->assertSame(RecipeImportStatus::ReviewReady, $import->status);
        $this->assertSame('reviewable_with_strong_warnings', $import->completion_classification);
        $this->assertContains('low_confidence_text', (array) $import->warnings);
        $this->assertNull($import->source_key);
        $this->assertNull($import->canonical_key);
        $this->assertSame(640, $import->image_width);
        $this->assertSame(480, $import->image_height);
        $this->assertDatabaseCount('recipes', 1);
        $this->assertDatabaseCount('recipe_ingredient_lines', 1);
        $this->assertDatabaseCount('recipe_instruction_steps', 1);
        $this->assertTrue($import->recipe->ingredientLines()->sole()->requires_review);
        $this->assertTrue($import->recipe->instructionSteps()->sole()->requires_review);
        Storage::disk('transient')->assertDirectoryEmpty('/');
    }

    public function test_png_animation_marker_and_corruption_are_rejected_safely(): void
    {
        $canonicalizer = app(ImageCanonicalizer::class);
        $png = UploadedFile::fake()->image('still.png', 10, 10)->getContent();
        foreach ([substr($png, 0, 20).'acTL'.substr($png, 20), "\x89PNG\r\n\x1A\ncorrupt"] as $bad) {
            try {
                $canonicalizer->canonicalize($bad, 'png', 'image/png');
                $this->fail('Invalid image was accepted.');
            } catch (\Throwable $exception) {
                $this->assertContains($exception->getMessage(), [
                    'The queued operation failed permanently.',
                ]);
            }
        }
    }

    public function test_heic_is_locally_converted_and_metadata_is_removed_when_runtime_supports_it(): void
    {
        $blob = base64_decode(
            'AAAAHGZ0eXBoZWljAAAAAG1pZjFoZWljbWlhZgAAAVZtZXRhAAAAAAAAACFoZGxyAAAAAAAAAABwaWN0AAAAAAAAAAAAAAAAAAAAAA5waXRtAAAAAAABAAAAImlsb2MAAAAAREAAAQABAAAAAAF6AAEAAAAAAAAAFgAAACNpaW5mAAAAAAABAAAAFWluZmUCAAAAAAEAAGh2YzEAAAAA1mlwcnAAAAC3aXBjbwAAAHhodmNDAQNwAAAAAAAAAAAAHvAA/P34+AAADwMgAAEAGEABDAH//wNwAAADAJAAAAMAAAMAHroCQCEAAQArQgEBA3AAAAMAkAAAAwAAAwAeoCCBBZbqSSmubgIaDAgAAAMACAAAAwAIQCIAAQAHRAHBcrAiQAAAABRpc3BlAAAAAAAAAEAAAABAAAAAE2NvbHJuY2x4AAEADQAGgAAAABBwaXhpAAAAAAMICAgAAAAXaXBtYQAAAAAAAAABAAEEgQIDBAAAAB5tZGF0AAAAEigBrwY4W4jl4r/wc01VmsJGfA==',
            true,
        );
        $this->assertIsString($blob);
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($blob);
        $this->assertContains($detected, ['image/heic', 'image/heif'], $detected.'|'.bin2hex(substr($blob, 4, 8)));
        $canonical = app(ImageCanonicalizer::class)->canonicalize($blob, 'heic', (string) $detected);
        $image = new Imagick;
        $image->readImageBlob($canonical->bytes);
        $this->assertSame('PNG', $image->getImageFormat());
        $this->assertSame([], $image->getImageProfiles('*', false));
        $this->assertSame([], array_values(array_filter(
            $image->getImageProperties('*', false),
            fn (string $property): bool => str_starts_with(strtolower($property), 'exif:')
                || str_contains(strtolower($property), 'gps'),
        )));
    }

    public function test_pinned_local_tesseract_reads_a_generated_english_recipe_image(): void
    {
        $image = new Imagick;
        $image->newImage(1200, 500, 'white');
        $image->setImageFormat('png');
        $draw = new ImagickDraw;
        $draw->setFillColor('black');
        $draw->setFont('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf');
        $draw->setFontSize(52);
        foreach ([
            80 => 'Photo Soup',
            170 => 'Ingredients',
            260 => '1 cup water',
            350 => 'Instructions',
            440 => 'Boil water',
        ] as $y => $text) {
            $image->annotateImage($draw, 50, $y, 0, $text);
        }

        $result = app(TesseractOcrExtractor::class)->extract($image->getImageBlob(), (string) Str::ulid());

        $this->assertSame('tesseract', $result->provider);
        $this->assertStringContainsString('ingredients', strtolower($result->text));
        $this->assertTrue($result->usable());
    }

    public function test_managed_fallback_rejects_non_eu_and_enforces_budget_before_network_access(): void
    {
        $extractor = app(GoogleDocumentAiOcrExtractor::class);
        config([
            'production.ocr.google.enabled' => true,
            'production.ocr.google.location' => 'eu',
            'production.ocr.google.endpoint' => 'https://documentai.googleapis.com',
            'production.ocr.google.monthly_page_quota' => 100,
            'production.ocr.google.monthly_budget_minor' => 5,
            'production.ocr.google.page_cost_minor' => 2,
        ]);

        try {
            $extractor->extract('canonical png', (string) Str::ulid());
            $this->fail('A non-EU endpoint was accepted.');
        } catch (NonRetryableJobException $exception) {
            $this->assertSame('ocr_fallback_unavailable', $exception->safeErrorCode);
        }

        config(['production.ocr.google.endpoint' => 'https://eu-documentai.googleapis.com']);
        Cache::put('ocr:google:pages:'.now()->utc()->format('Ym'), 2, now()->addMonth());

        try {
            $extractor->extract('canonical png', (string) Str::ulid());
            $this->fail('The monthly budget-derived page ceiling was exceeded.');
        } catch (NonRetryableJobException $exception) {
            $this->assertSame('ocr_fallback_quota_exhausted', $exception->safeErrorCode);
        }
    }

    public function test_abandoned_cleanup_retains_pre_boundary_and_active_inputs_then_expires_old_input(): void
    {
        Storage::disk('transient')->put('inputs/old', 'recipe');
        Storage::disk('transient')->put('inputs/new', 'recipe');
        Storage::disk('transient')->put('inputs/active', 'recipe');
        $old = $this->storedImport('inputs/old', now()->subDays(8));
        $new = $this->storedImport('inputs/new', now()->subDays(6));
        $active = $this->storedImport('inputs/active', now()->subDays(8), now()->addMinute());

        $this->artisan('recipe-imports:cleanup-transient')->assertSuccessful();

        $this->assertSame(RecipeImportStatus::Failed, $old->fresh()->status);
        $this->assertNull($old->fresh()->source_key);
        Storage::disk('transient')->assertMissing('inputs/old');
        Storage::disk('transient')->assertExists('inputs/new');
        Storage::disk('transient')->assertExists('inputs/active');
        $this->assertSame(RecipeImportStatus::Pending, $new->fresh()->status);
        $this->assertSame(RecipeImportStatus::Pending, $active->fresh()->status);
    }

    private function storedImport(string $key, $storedAt, $lease = null): RecipeImport
    {
        return RecipeImport::factory()->create([
            'type' => RecipeImportType::UploadedText,
            'source_format' => 'plain_text', 'source_text' => null,
            'source_disk' => 'transient', 'source_key' => $key,
            'source_mime' => 'text/plain', 'source_bytes' => 6,
            'source_extension' => 'txt', 'source_stored_at' => $storedAt,
            'processing_lease_until' => $lease,
            'status' => RecipeImportStatus::Pending,
            'idempotency_key' => 'recipe_upload_import.process|'.Str::ulid(),
        ]);
    }
}
