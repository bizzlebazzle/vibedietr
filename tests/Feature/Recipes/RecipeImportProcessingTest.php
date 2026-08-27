<?php

namespace Tests\Feature\Recipes;

use App\Domain\RecipeImports\NullRecipeImportMaterializationHook;
use App\Domain\RecipeImports\Parsing\DeterministicRecipeTextParser;
use App\Domain\RecipeImports\RecipeImportMaterializationHook;
use App\Domain\RecipeImports\RecipeImportMaterializer;
use App\Domain\RecipeImports\RecipeImportStatus;
use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Jobs\ProcessPastedRecipeImport;
use App\Models\Recipe;
use App\Models\RecipeImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RecipeImportProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_source_creates_one_private_editable_unfinalized_draft_with_exact_order_and_wording(): void
    {
        $owner = User::factory()->create();
        $source = $this->fixture('standard.txt');
        $import = RecipeImport::factory()->for($owner, 'owner')->create(['source_text' => $source]);
        $job = new ProcessPastedRecipeImport($import->id, $import->correlation_id);

        app()->call([$job, 'handle']);

        $import->refresh();
        $recipe = $import->recipe()->firstOrFail();
        $this->assertSame(RecipeImportStatus::ReviewReady, $import->status);
        $this->assertSame($owner->id, $recipe->user_id);
        $this->assertSame(RecipeLifecycle::Draft, $recipe->lifecycle);
        $this->assertSame(RecipeVisibility::Private, $recipe->visibility);
        $this->assertNull($recipe->current_recipe_version_id);
        $this->assertNull($recipe->finalized_at);
        $this->assertSame('Synthetic Lemon Loaf', $recipe->title);
        $this->assertSame('8.00', $recipe->servings);
        $this->assertSame([0, 1, 2], $recipe->ingredientLines()->pluck('position')->all());
        $this->assertSame([
            '1  1/2 cups   plain flour, sifted',
            '½ tsp salt',
            'salt to taste',
        ], $recipe->ingredientLines()->pluck('original_text')->all());
        $this->assertSame([
            '1.  Mix gently — don’t overwork.',
            '2. Bake at 180°C; cool.',
        ], $recipe->instructionSteps()->pluck('text')->all());
        $this->assertSame(DeterministicRecipeTextParser::IDENTIFIER, $import->parser_identifier);
        $this->assertSame($source, $import->source_text);

        $this->actingAs($owner)->get(route('recipes.edit', $recipe))
            ->assertOk()->assertSee('Imported — needs review')->assertSee('View import source');
        $this->get(route('recipes.index'))->assertDontSee('Synthetic Lemon Loaf');
        auth()->logout();
        $this->get(route('recipes.show', $recipe))->assertNotFound();
    }

    public function test_sectioned_and_ambiguous_source_materializes_review_metadata_without_rejecting_lines(): void
    {
        $owner = User::factory()->create();
        $sectioned = RecipeImport::factory()->for($owner, 'owner')->create(['source_text' => $this->fixture('sectioned.txt')]);
        app()->call([(new ProcessPastedRecipeImport($sectioned->id, $sectioned->correlation_id)), 'handle']);

        $recipe = $sectioned->fresh()->recipe;
        $this->assertSame(['Prepare', 'Finish'], $recipe->instructionSections()->pluck('name')->all());
        $this->assertSame($recipe->instructionSections()->pluck('id')->all(), $recipe->instructionSteps()->pluck('section_id')->all());
        $custom = $recipe->ingredientLines()->where('position', 1)->sole();
        $this->assertSame('custommeasure', $custom->custom_unit);
        $this->assertTrue($custom->requires_review);
        $this->assertIsArray($custom->parser_warnings);
        $this->assertContains('ingredient_unit_uncertain', $custom->parser_warnings);

        $ambiguous = RecipeImport::factory()->for($owner, 'owner')->create(['source_text' => $this->fixture('ambiguous.txt')]);
        app()->call([(new ProcessPastedRecipeImport($ambiguous->id, $ambiguous->correlation_id)), 'handle']);
        $salt = $ambiguous->fresh()->recipe->ingredientLines()->first();
        $this->assertSame('salt to taste', $salt->original_text);
        $this->assertNull($salt->quantity);
        $this->assertTrue($salt->requires_review);
    }

    public function test_duplicate_execution_and_dispatch_do_not_duplicate_draft_or_children(): void
    {
        $import = RecipeImport::factory()->create(['source_text' => $this->fixture('standard.txt')]);
        $job = new ProcessPastedRecipeImport($import->id, $import->correlation_id);

        app()->call([$job, 'handle']);
        $recipeId = $import->fresh()->recipe_id;
        app()->call([$job, 'handle']);

        $this->assertDatabaseCount('recipes', 1);
        $this->assertDatabaseCount('recipe_ingredient_lines', 3);
        $this->assertDatabaseCount('recipe_instruction_steps', 2);
        $this->assertSame($recipeId, $import->fresh()->recipe_id);
    }

    public function test_failure_at_final_materialization_boundary_rolls_back_every_child_and_retry_creates_one_valid_draft(): void
    {
        $import = RecipeImport::factory()->create(['source_text' => $this->fixture('standard.txt')]);
        $result = app(DeterministicRecipeTextParser::class)->parse($import->source_text);
        $this->app->instance(RecipeImportMaterializationHook::class, new class implements RecipeImportMaterializationHook
        {
            public function beforeCommit(RecipeImport $import, Recipe $recipe): void
            {
                throw new RuntimeException('Deterministic materialization failure.');
            }
        });

        try {
            app(RecipeImportMaterializer::class)->materialize($import->id, $result);
            $this->fail('The materialization hook should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Deterministic materialization failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('recipes', 0);
        $this->assertDatabaseCount('recipe_ingredient_lines', 0);
        $this->assertDatabaseCount('recipe_instruction_sections', 0);
        $this->assertDatabaseCount('recipe_instruction_steps', 0);
        $this->assertNull($import->fresh()->recipe_id);

        (new RecipeImportMaterializer(new NullRecipeImportMaterializationHook))->materialize($import->id, $result);
        $this->assertDatabaseCount('recipes', 1);
        $this->assertDatabaseCount('recipe_ingredient_lines', 3);
        $this->assertDatabaseCount('recipe_instruction_steps', 2);
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path('tests/Fixtures/RecipeImports/'.$name)) ?: '';
    }
}
