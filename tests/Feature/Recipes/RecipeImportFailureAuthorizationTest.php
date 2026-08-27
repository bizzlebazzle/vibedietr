<?php

namespace Tests\Feature\Recipes;

use App\Domain\RecipeImports\RecipeImportStatus;
use App\Jobs\ProcessPastedRecipeImport;
use App\Models\RecipeImport;
use App\Models\User;
use App\Queue\Exceptions\NonRetryableJobException;
use App\Queue\Exceptions\RetryableJobException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecipeImportFailureAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_permanent_parser_rejection_marks_failed_without_any_draft_or_children(): void
    {
        $import = RecipeImport::factory()->create(['source_text' => $this->fixture('malformed.txt')]);
        $job = (new ProcessPastedRecipeImport($import->id, $import->correlation_id))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        $job->assertFailedWith(NonRetryableJobException::class);
        $this->assertSame(RecipeImportStatus::Failed, $import->fresh()->status);
        $this->assertSame('recipe_structure_not_found', $import->fresh()->failure_code);
        $this->assertDatabaseCount('recipes', 0);
        $this->assertDatabaseCount('recipe_ingredient_lines', 0);
        $this->assertDatabaseCount('recipe_instruction_steps', 0);
    }

    public function test_owner_only_access_retry_and_imported_draft_authorization(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $import = RecipeImport::factory()->for($owner, 'owner')->failed()->create();

        $this->actingAs($owner)->get(route('recipe-imports.show', $import))->assertOk()->assertSee($import->source_text);
        $this->actingAs($other)->get(route('recipe-imports.show', $import))->assertForbidden()->assertDontSee($import->source_text);
        auth()->logout();
        $this->get(route('recipe-imports.show', $import))->assertRedirect(route('login'));

        $this->actingAs($other)->post(route('recipe-imports.retry', $import))->assertForbidden();
        $this->actingAs($owner)->post(route('recipe-imports.retry', $import))->assertRedirect();
        $import->refresh();
        $this->assertSame(RecipeImportStatus::Pending, $import->status);
        $this->assertSame(1, $import->manual_retry_count);
        Queue::assertPushed(ProcessPastedRecipeImport::class, fn (ProcessPastedRecipeImport $job): bool => $job->importId === $import->id && $job->correlationId === $import->correlation_id);
    }

    public function test_retry_exhaustion_failure_log_contains_safe_identifiers_not_source_text(): void
    {
        Log::spy();
        $source = 'PRIVATE INGREDIENT AND INSTRUCTION SOURCE';
        $import = RecipeImport::factory()->processing()->create(['source_text' => $source]);
        $job = new ProcessPastedRecipeImport($import->id, $import->correlation_id);

        $job->failed(new RetryableJobException('recipe_import_unexpected'));

        /** @phpstan-ignore-next-line Laravel facade forwards this assertion to the Mockery spy. */
        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) use ($import, $source): bool {
            $encoded = json_encode($context, JSON_THROW_ON_ERROR);

            return $message === 'queued_job_failed'
                && $context['correlation_id'] === $import->correlation_id
                && $context['resource_identifier'] === $import->id
                && $context['idempotency_fingerprint'] === hash('sha256', ProcessPastedRecipeImport::OPERATION_TYPE.'|'.$import->id)
                && ! str_contains($encoded, $source);
        })->once();
        $this->assertSame(RecipeImportStatus::Failed, $import->fresh()->status);
    }

    public function test_factory_states_cover_pending_processing_ready_failed_ambiguous_draft_and_other_owner(): void
    {
        $other = User::factory()->create();
        $pending = RecipeImport::factory()->create();
        $processing = RecipeImport::factory()->processing()->create();
        $ready = RecipeImport::factory()->reviewReady()->create();
        $failed = RecipeImport::factory()->failed()->create();
        $ambiguous = RecipeImport::factory()->ambiguous()->create();
        $withDraft = RecipeImport::factory()->withDraft()->create();
        $otherOwned = RecipeImport::factory()->for($other, 'owner')->create();

        $this->assertSame(RecipeImportStatus::Pending, $pending->status);
        $this->assertSame(RecipeImportStatus::Processing, $processing->status);
        $this->assertSame(RecipeImportStatus::ReviewReady, $ready->status);
        $this->assertSame(RecipeImportStatus::Failed, $failed->status);
        $this->assertNotEmpty($ambiguous->warnings);
        $this->assertNotNull($withDraft->fresh()->recipe_id);
        $this->assertSame($other->id, $otherOwned->user_id);
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path('tests/Fixtures/RecipeImports/'.$name)) ?: '';
    }
}
