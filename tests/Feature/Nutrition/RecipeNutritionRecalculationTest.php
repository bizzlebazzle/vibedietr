<?php

namespace Tests\Feature\Nutrition;

use App\Audit\Enums\AuditAction;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Nutrition\CatalogueNutrientObservation;
use App\Domain\Nutrition\CatalogueNutritionNormalizer;
use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientBasis;
use App\Domain\Nutrition\NutrientProvenance;
use App\Domain\Nutrition\NutrientUnit;
use App\Domain\Nutrition\ProcessRecipeNutritionRecalculation;
use App\Domain\Nutrition\RecipeNutritionOverrideManager;
use App\Domain\Nutrition\RecipeNutritionPresenter;
use App\Domain\Nutrition\RecipeNutritionRecalculationDispatcher;
use App\Domain\Nutrition\RecipeNutritionRecalculationState;
use App\Domain\Nutrition\RecipeNutritionValueNormalizer;
use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVersionContent;
use App\Domain\Recipes\RecipeVisibility;
use App\Jobs\RecalculateRecipeNutrition;
use App\Models\AuditEvent;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Models\RecipeIngredientLineMatch;
use App\Models\RecipeNutritionRecalculation;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Queue\Exceptions\RetryableJobException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class RecipeNutritionRecalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_recipe_versions_with_a_current_dependency_on_the_approved_item_are_queued_once(): void
    {
        [$item, $old, $approved] = $this->catalogueVersions('5', '11');
        $affected = $this->recipeVersion($old);
        $unaffected = $this->recipeVersion($this->catalogueVersions('3', '4')[1]);
        $alreadyCurrent = $this->recipeVersion($approved);
        Queue::fake();
        $dispatcher = app(RecipeNutritionRecalculationDispatcher::class);

        $this->assertSame(1, $dispatcher->dispatchForApprovedVersion($approved, '01NUT18DEPENDENCYSELECTION00'));
        $this->assertSame(0, $dispatcher->dispatchForApprovedVersion($approved, '01NUT18DEPENDENCYSELECTION00'));

        $operation = RecipeNutritionRecalculation::query()->sole();
        $this->assertSame($affected->id, $operation->recipe_version_id);
        $this->assertSame($approved->id, $operation->approved_catalogue_item_version_id);
        $this->assertSame(RecipeNutritionRecalculationState::Queued, $operation->state);
        $this->assertDatabaseMissing('recipe_nutrition_recalculations', ['recipe_version_id' => $unaffected->id]);
        $this->assertDatabaseMissing('recipe_nutrition_recalculations', ['recipe_version_id' => $alreadyCurrent->id]);
        Queue::assertPushed(RecalculateRecipeNutrition::class, 1);
        Queue::assertPushed(fn (RecalculateRecipeNutrition $job): bool => $job->recalculationId === $operation->id
            && $job->recipeVersionId === $affected->id
            && $job->queue === 'default'
            && $job->afterCommit === true);
        $this->assertSame($item->id, $approved->catalogue_item_id);
    }

    public function test_processing_is_idempotent_updates_the_estimate_and_never_rewrites_the_recipe_snapshot(): void
    {
        [, $old, $approved] = $this->catalogueVersions('5', '11');
        $version = $this->recipeVersion($old);
        $historicalSnapshot = $version->snapshot;
        Queue::fake();
        app(RecipeNutritionRecalculationDispatcher::class)->dispatchForApprovedVersion($approved);
        $operation = RecipeNutritionRecalculation::query()->sole();
        $processor = app(ProcessRecipeNutritionRecalculation::class);

        $processor->process($operation->id);
        $processor->process($operation->id);

        $operation = $operation->fresh();
        $this->assertSame(RecipeNutritionRecalculationState::Completed, $operation->state);
        $this->assertSame('11.000000000000000000', $operation->estimate['whole_recipe']['protein']['value']);
        $this->assertSame($approved->id, $operation->estimate['inputs'][0]['catalogue_item_version_id']);
        $this->assertSame($historicalSnapshot, $version->fresh()->snapshot);
        $this->assertSame('11.0 g', $this->protein(app(RecipeNutritionPresenter::class)->present($version->fresh())));
        $this->assertDatabaseCount('recipe_nutrition_recalculations', 1);
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::RecipeNutritionRecalculated)->count());
        $audit = AuditEvent::findOrFail($operation->audit_event_id);
        $this->assertTrue($audit->hasValidIntegrityHash());
        $this->assertSame($operation->correlation_id, $audit->correlation_id);
        $this->assertSame($operation->id, $audit->evidence_reference);
    }

    public function test_a_failed_delivery_can_be_replayed_to_the_same_single_intended_result(): void
    {
        [, $old, $approved] = $this->catalogueVersions('5', '11');
        $version = $this->recipeVersion($old);
        Queue::fake();
        app(RecipeNutritionRecalculationDispatcher::class)->dispatchForApprovedVersion($approved);
        $operation = RecipeNutritionRecalculation::query()->sole();
        $job = new RecalculateRecipeNutrition($operation->id, $version->id, $operation->correlation_id);

        $job->failed(new RetryableJobException('database_unavailable'));
        $this->assertSame(RecipeNutritionRecalculationState::Failed, $operation->fresh()->state);
        $this->assertSame('database_unavailable', $operation->fresh()->failure_code);

        app(ProcessRecipeNutritionRecalculation::class)->process($operation->id);
        app(ProcessRecipeNutritionRecalculation::class)->process($operation->id);

        $this->assertSame(RecipeNutritionRecalculationState::Completed, $operation->fresh()->state);
        $this->assertSame('11.000000000000000000', $operation->fresh()->estimate['whole_recipe']['protein']['value']);
        $this->assertDatabaseCount('recipe_nutrition_recalculations', 1);
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::RecipeNutritionRecalculated)->count());
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60], $job->backoff());
        $this->assertSame(60, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame($job->idempotencyFingerprint(), $job->uniqueId());
        $this->assertCount(1, $job->middleware());
    }

    public function test_imported_and_creator_override_primary_values_stay_stable_while_comparison_estimates_update(): void
    {
        [, $old, $approved] = $this->catalogueVersions('5', '11');
        $imported = $this->recipeVersion($old, importedProtein: '8');
        $overridden = $this->recipeVersion($old, importedProtein: '9');
        $owner = User::query()->findOrFail($overridden->recipe->user_id);
        app(RecipeNutritionOverrideManager::class)->save(
            $overridden->recipe_id,
            $overridden->id,
            ['protein' => '14'],
            null,
            $owner,
        );
        Queue::fake();
        app(RecipeNutritionRecalculationDispatcher::class)->dispatchForApprovedVersion($approved);

        foreach (RecipeNutritionRecalculation::all() as $operation) {
            app(ProcessRecipeNutritionRecalculation::class)->process($operation->id);
        }

        $presenter = app(RecipeNutritionPresenter::class);
        $importedNutrition = $presenter->present($imported->fresh());
        $overrideNutrition = $presenter->present($overridden->fresh());
        $this->assertSame('imported_source', $importedNutrition['source']);
        $this->assertSame('8.0 g', $this->protein($importedNutrition));
        $this->assertSame('11.0 g', $this->protein($importedNutrition['comparisons'][0]));
        $this->assertSame('creator_override', $overrideNutrition['source']);
        $this->assertSame('14.0 g', $this->protein($overrideNutrition));
        $this->assertSame('9.0 g', $this->protein($overrideNutrition['comparisons'][0]));
        $this->assertSame('11.0 g', $this->protein($overrideNutrition['comparisons'][1]));
    }

    public function test_rollback_refuses_to_remove_schema_while_minimized_audit_history_remains(): void
    {
        [, $old, $approved] = $this->catalogueVersions('5', '11');
        $this->recipeVersion($old);
        Queue::fake();
        app(RecipeNutritionRecalculationDispatcher::class)->dispatchForApprovedVersion($approved);
        $operation = RecipeNutritionRecalculation::query()->sole();
        app(ProcessRecipeNutritionRecalculation::class)->process($operation->id);
        DB::table('recipe_nutrition_recalculations')->delete();
        $migration = require database_path('migrations/2026_09_12_000001_add_recipe_nutrition_recalculations.php');

        $this->expectException(RuntimeException::class);

        $migration->down();
    }

    /** @return array{CatalogueItem, CatalogueItemVersion, CatalogueItemVersion} */
    private function catalogueVersions(string $oldProtein, string $newProtein): array
    {
        $item = CatalogueItem::factory()->approved()->create();
        $old = CatalogueItemVersion::factory()->for($item, 'catalogueItem')->create([
            'version_number' => 1,
        ]);
        $this->storeProtein($old, $oldProtein);
        $item->setCurrentVersion($old);
        $approved = CatalogueItemVersion::factory()->for($item, 'catalogueItem')->create([
            'version_number' => 2,
        ]);
        $this->storeProtein($approved, $newProtein);
        $item->setCurrentVersion($approved);

        return [$item->fresh(), $old->fresh(), $approved->fresh()];
    }

    private function storeProtein(CatalogueItemVersion $version, string $value): void
    {
        app(CatalogueNutritionNormalizer::class)->store($version, [
            new CatalogueNutrientObservation(
                Nutrient::Protein,
                NutrientBasis::Per100Gram,
                $value,
                NutrientUnit::Gram,
                NutrientProvenance::ManuallySubmitted,
            ),
        ]);
    }

    private function recipeVersion(CatalogueItemVersion $catalogueVersion, ?string $importedProtein = null): RecipeVersion
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create([
            'servings' => '1',
            'lifecycle' => RecipeLifecycle::Finalized,
            'visibility' => RecipeVisibility::Public,
            'finalized_at' => now()->utc(),
        ]);
        $line = RecipeIngredientLine::factory()->for($recipe)->create([
            'position' => 0,
            'original_text' => '100 g protein food',
            'quantity' => '100',
            'standard_unit' => StandardUnit::Gram,
            'custom_unit' => null,
        ]);
        RecipeIngredientLineMatch::factory()->for($line, 'ingredientLine')->create([
            'catalogue_item_version_id' => $catalogueVersion->id,
            'selected_by_user_id' => $owner->id,
        ]);
        $snapshot = app(RecipeVersionContent::class)->snapshot($recipe);
        if ($importedProtein !== null) {
            $snapshot['imported_nutrition'] = [
                'per_serving' => app(RecipeNutritionValueNormalizer::class)->normalize(
                    ['protein' => $importedProtein],
                    'imported_recipe_source',
                ),
                'provenance' => ['recipe_import_id' => 'import:01NUT18'],
            ];
        }
        $version = RecipeVersion::factory()->for($recipe)->create(['snapshot' => $snapshot]);
        $recipe->forceFill(['current_recipe_version_id' => $version->id])->save();

        return $version->fresh(['recipe.owner']);
    }

    /** @param array<string, mixed> $nutrition */
    private function protein(array $nutrition): string
    {
        return collect($nutrition['per_serving'])->firstWhere('label', 'Protein')['value'];
    }
}
