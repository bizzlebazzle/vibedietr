<?php

namespace Tests\Feature\Recipes;

use App\Domain\RecipeImports\RecipeImportStatus;
use App\Domain\RecipeImports\RecipeImportType;
use App\Models\RecipeImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecipeImportGuidanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_guidance_discloses_optional_eu_fallback_and_does_not_claim_every_import_succeeds(): void
    {
        $this->actingAs(User::factory()->create());
        config(['production.ocr.google.enabled' => false]);
        $this->get(route('recipe-imports.create'))->assertOk()
            ->assertSee('Usable low-confidence text creates a warning-marked private draft')
            ->assertSee('no usable text fails without a draft')
            ->assertSee('retrying OCR requires you to upload the source again')
            ->assertSee('If a local parser finds usable recipe structure')
            ->assertSee('paste its recipe text here or create a recipe manually')
            ->assertDontSee('Google Document AI');
        config(['production.ocr.google.enabled' => true]);
        $this->get(route('recipe-imports.create'))->assertOk()
            ->assertSee('a metadata-free canonical image may be processed by Google Document AI in the EU')
            ->assertSee('Low confidence alone does not trigger this fallback.');
    }

    /** @return iterable<string, array{RecipeImportType, string}> */
    public static function failedSources(): iterable
    {
        yield 'pasted parser failure' => [RecipeImportType::PastedText, 'recipe_structure_not_found'];
        yield 'webpage provider failure' => [RecipeImportType::WebpageUrl, 'webpage_temporary_failure'];
        yield 'unsupported webpage' => [RecipeImportType::WebpageUrl, 'unsupported_content_type'];
        yield 'uploaded document' => [RecipeImportType::UploadedText, 'recipe_structure_not_found'];
        yield 'uploaded OCR' => [RecipeImportType::UploadedImage, 'tesseract_execution_failed'];
    }

    #[DataProvider('failedSources')]
    public function test_failure_recovery_offers_only_supported_retry_and_manual_alternatives_and_preserves_source(RecipeImportType $type, string $code): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $source = "Private saved source\nIngredients\n100 g oats\nInstructions\nMix gently.";
        $import = RecipeImport::factory()->for($owner, 'owner')->failed()->create([
            'type' => $type, 'failure_code' => $code, 'source_text' => $source,
            'submitted_url' => $type === RecipeImportType::WebpageUrl ? 'https://example.test/recipe' : null,
            'cleanup_completed_at' => $type === RecipeImportType::UploadedText || $type === RecipeImportType::UploadedImage ? now() : null,
        ]);
        $response = $this->actingAs($owner)->get(route('recipe-imports.show', $import))->assertOk()
            ->assertSee($source)->assertSee('No recipe was published.')
            ->assertSee('Create a recipe manually')->assertSee('Paste recipe text instead')
            ->assertSee(route('recipe-imports.create').'#pasted-text-import', false);
        if (in_array($type, [RecipeImportType::PastedText, RecipeImportType::WebpageUrl], true)) {
            $response->assertSee('Retry import')->assertSee('using the same saved source')
                ->assertSee('Retrying may still fail')->assertSee('create a recipe manually');
            $this->post(route('recipe-imports.retry', $import))->assertRedirect();
            $this->get(route('recipe-imports.show', $import))->assertOk()
                ->assertSee('Refresh this page to check progress')
                ->assertDontSee('Retry import');
            $this->assertSame(RecipeImportStatus::Pending, $import->fresh()->status);
        } else {
            $response->assertDontSee('Retry import')->assertSee('Upload source again')->assertSee('requires you to upload the source again')
                ->assertSee('recovered text shown below remains available for manual recovery');
        }
        $this->assertSame($source, $import->fresh()->source_text);
        $this->assertDatabaseCount('recipes', 0);
        $this->actingAs(User::factory()->create())->get(route('recipe-imports.show', $import))
            ->assertForbidden()->assertDontSee($source)->assertDontSee('Retry import');
    }

    public function test_retry_limit_explains_new_submission_and_manual_recovery_without_retry_control(): void
    {
        $owner = User::factory()->create();
        $import = RecipeImport::factory()->for($owner, 'owner')->failed()->create(['manual_retry_count' => 3]);
        $this->actingAs($owner)->get(route('recipe-imports.show', $import))->assertOk()
            ->assertSee('The retry limit for this import has been reached.')
            ->assertSee('edit the source and submit a new import')
            ->assertSee($import->source_text)
            ->assertDontSee('Retry import');
    }

    /** @return iterable<string, array{string, string}> */
    public static function ocrProviders(): iterable
    {
        yield 'local' => ['tesseract', 'Local Tesseract'];
        yield 'managed' => ['google_document_ai', 'Google Document AI (EU fallback)'];
    }

    #[DataProvider('ocrProviders')]
    public function test_low_confidence_draft_has_accurate_ocr_provenance_review_and_planning_guidance_on_status_and_editor(string $provider, string $label): void
    {
        config(['production.ocr.google.enabled' => true]);
        $owner = User::factory()->create();
        $import = RecipeImport::factory()->for($owner, 'owner')->withDraft()->create([
            'type' => RecipeImportType::UploadedImage,
            'extractor_identifier' => $provider,
            'provenance' => ['extractor' => $provider, 'extractor_version' => 'pinned-version'],
            'warnings' => ['low_confidence_text', 'possible_extraction_error'],
            'completion_classification' => 'reviewable_with_strong_warnings',
        ]);
        $this->actingAs($owner);
        foreach ([route('recipe-imports.show', $import), route('recipes.edit', $import->recipe)] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('OCR source: '.$label)
                ->assertSee('pinned-version')
                ->assertSee('Low-confidence OCR text was recovered into a reviewable private draft.')
                ->assertSee('Confidence does not verify correctness.')
                ->assertSee('OCR does not verify food matches or nutrition.')
                ->assertSee('Finalize the reviewed draft explicitly before using it in a meal plan.')
                ->assertSee('a metadata-free canonical image may be processed by Google Document AI in the EU')
                ->assertDontSee('Recovered locally from the image')
                ->assertDontSee('no usable text was recovered');
        }
        $this->assertSame($provider, $import->fresh()->provenance['extractor']);
    }

    public function test_no_text_failure_is_distinct_from_a_low_confidence_draft_and_requires_reupload(): void
    {
        $owner = User::factory()->create();
        $import = RecipeImport::factory()->for($owner, 'owner')->failed()->create([
            'type' => RecipeImportType::UploadedImage, 'source_text' => null,
            'failure_code' => 'no_usable_ocr_text', 'cleanup_completed_at' => now(),
        ]);
        $this->actingAs($owner)->get(route('recipe-imports.show', $import))->assertOk()
            ->assertSee('OCR failed: no usable text was recovered. No reviewable draft was created.')
            ->assertSee('After terminal cleanup, retrying OCR requires you to upload the source again.')
            ->assertDontSee('Low-confidence OCR text was recovered')
            ->assertDontSee('Recovered OCR text')->assertDontSee('Review draft')
            ->assertDontSee('Retry import')->assertSee('Upload source again')->assertSee(route('recipe-imports.create'), false);
    }
}
