<?php

namespace Tests\Feature\Recipes;

use App\Domain\RecipeImports\RecipeImportStatus;
use App\Http\Requests\StorePastedRecipeImportRequest;
use App\Jobs\ProcessPastedRecipeImport;
use App\Models\RecipeImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecipeImportSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_submits_exact_source_and_dispatches_safe_correlated_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $source = "  Synthetic Dish  \r\nIngredients\r\n1  1/2 cups   flour\r\nInstructions\r\n1. Mix.  \r\n";

        $response = $this->actingAs($user)->withHeader('X-Correlation-ID', '01K1IMPORTCORRELATION0000000')->post(route('recipe-imports.store'), [
            'source_format' => 'plain_text',
            'source_text' => $source,
        ]);

        $import = RecipeImport::query()->sole();
        $response->assertRedirect(route('recipe-imports.show', $import));
        $this->assertSame($user->id, $import->user_id);
        $this->assertSame($source, $import->source_text);
        $this->assertSame(RecipeImportStatus::Pending, $import->status);
        $this->assertSame('01K1IMPORTCORRELATION0000000', $import->correlation_id);
        $this->assertSame('recipe_import.process|'.$import->id, $import->idempotency_key);
        Queue::assertPushed(ProcessPastedRecipeImport::class, fn (ProcessPastedRecipeImport $job): bool => $job->importId === $import->id
            && $job->correlationId === $import->correlation_id
            && $job->afterCommit === true
            && $job->queue === 'default');
    }

    public function test_guest_is_redirected_and_blank_or_oversized_source_is_rejected_without_dispatch(): void
    {
        Queue::fake();
        $this->post(route('recipe-imports.store'), ['source_format' => 'plain_text', 'source_text' => 'Recipe'])
            ->assertRedirect(route('login'));

        $user = User::factory()->create();
        $this->actingAs($user)->from(route('recipe-imports.create'))->post(route('recipe-imports.store'), [
            'source_format' => 'plain_text', 'source_text' => " \n\t ",
        ])->assertSessionHasErrors('source_text');
        $this->actingAs($user)->from(route('recipe-imports.create'))->post(route('recipe-imports.store'), [
            'source_format' => 'plain_text', 'source_text' => str_repeat('x', StorePastedRecipeImportRequest::MAX_SOURCE_BYTES + 1),
        ])->assertSessionHasErrors('source_text');

        $this->assertDatabaseCount('recipe_imports', 0);
        Queue::assertNothingPushed();
    }

    public function test_two_intentional_imports_of_identical_source_are_distinct_operations(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $payload = ['source_format' => 'plain_text', 'source_text' => "Dish\nIngredients\n1 cup rice\nInstructions\nCook rice."];

        $this->actingAs($user)->post(route('recipe-imports.store'), $payload)->assertRedirect();
        $this->actingAs($user)->post(route('recipe-imports.store'), $payload)->assertRedirect();

        $imports = RecipeImport::query()->orderBy('created_at')->get();
        $this->assertCount(2, $imports);
        $this->assertNotSame($imports[0]->id, $imports[1]->id);
        $this->assertNotSame($imports[0]->idempotency_key, $imports[1]->idempotency_key);
    }

    public function test_duplicate_dispatch_of_one_import_is_suppressed_while_pending(): void
    {
        Queue::fake();
        $import = RecipeImport::factory()->create();

        ProcessPastedRecipeImport::dispatch($import->id, $import->correlation_id);
        ProcessPastedRecipeImport::dispatch($import->id, $import->correlation_id);

        Queue::assertPushed(ProcessPastedRecipeImport::class, 1);
    }

    public function test_serialized_job_contains_identifiers_not_private_source_or_account_data(): void
    {
        $user = User::factory()->create(['email' => 'private@example.test']);
        $source = 'private source ingredient wording';
        $import = RecipeImport::factory()->for($user, 'owner')->create(['source_text' => $source]);
        $job = new ProcessPastedRecipeImport($import->id, $import->correlation_id);

        Queue::connection('database')->push($job);
        $payload = DB::table('jobs')->sole()->payload;
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $command = unserialize($decoded['data']['command']);

        $this->assertSame($import->id, $command->importId);
        $this->assertSame($import->correlation_id, $command->correlationId);
        $this->assertStringNotContainsString($source, $payload);
        $this->assertStringNotContainsString($user->email, $payload);
        $this->assertTrue(Str::isUlid($command->importId));
    }
}
