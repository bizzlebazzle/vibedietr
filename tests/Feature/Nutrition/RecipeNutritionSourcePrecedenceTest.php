<?php

namespace Tests\Feature\Nutrition;

use App\Audit\Enums\AuditAction;
use App\Domain\Nutrition\RecipeNutritionPresenter;
use App\Domain\Nutrition\RecipeNutritionSource;
use App\Domain\Nutrition\RecipeNutritionSourceSelector;
use App\Domain\Nutrition\RecipeNutritionValueNormalizer;
use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Models\AuditEvent;
use App\Models\Recipe;
use App\Models\RecipeNutritionOverrideEvent;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class RecipeNutritionSourcePrecedenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_estimate_is_primary_when_no_higher_precedence_source_exists(): void
    {
        $recipe = $this->recipe(User::factory()->create(), $this->snapshot());
        $nutrition = app(RecipeNutritionPresenter::class)->present($recipe->currentVersion);

        $this->assertSame('ingredient_estimate', $nutrition['source']);
        $this->assertSame('5.0 g', $this->row($nutrition['per_serving'], 'Protein')['value']);
        $this->assertSame([], $nutrition['comparisons']);
    }

    public function test_imported_source_beats_estimate_and_keeps_provenance_and_collapsed_comparison(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->recipe($owner, $this->snapshot(importedProtein: '8'));
        $nutrition = app(RecipeNutritionPresenter::class)->present($recipe->currentVersion);

        $this->assertSame('imported_source', $nutrition['source']);
        $this->assertSame('8.0 g', $this->row($nutrition['per_serving'], 'Protein')['value']);
        $this->assertSame('import:01NUT17', $nutrition['provenance']['recipe_import_id']);
        $this->assertSame('ingredient_estimate', $nutrition['comparisons'][0]['source']);
        $this->assertSame('5.0 g', $this->row($nutrition['comparisons'][0]['per_serving'], 'Protein')['value']);

        $this->actingAs($owner)->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('Primary source:')
            ->assertSee('Imported recipe source')
            ->assertSee('Compare with ingredient estimate')
            ->assertSee('<details', false)
            ->assertSee('lower-precedence ingredient estimate');
    }

    public function test_same_values_can_still_change_the_primary_source_to_a_creator_override(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->recipe($owner, $this->snapshot(importedProtein: '8'));
        $version = $recipe->currentVersion;

        $this->actingAs($owner)->put(route('recipes.nutrition-override.update', $recipe), [
            'source_version_id' => $version->id,
            'nutrients' => ['protein' => '8'],
            'note' => 'Creator-confirmed value.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $effective = app(RecipeNutritionSourceSelector::class)->effective($version);
        $this->assertSame(RecipeNutritionSource::CreatorOverride, $effective['source']);
        $event = RecipeNutritionOverrideEvent::query()->sole();
        $this->assertSame(RecipeNutritionSource::ImportedSource, $event->prior_source);
        $this->assertSame(['protein'], AuditEvent::findOrFail($event->audit_event_id)->payload['changed_nutrients']);
    }

    public function test_override_beats_import_and_estimate_then_change_and_remove_restore_import(): void
    {
        Date::setTestNow('2026-09-12 09:00:00');
        $owner = User::factory()->create(['name' => 'Nutrition Creator']);
        $recipe = $this->recipe($owner, $this->snapshot(importedProtein: '8'));
        $version = $recipe->currentVersion;

        $this->actingAs($owner)->put(route('recipes.nutrition-override.update', $recipe), [
            'source_version_id' => $version->id,
            'nutrients' => ['protein' => '12.345'],
            'note' => 'Package source was wrong.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $selector = app(RecipeNutritionSourceSelector::class);
        $this->assertSame(RecipeNutritionSource::CreatorOverride, $selector->effective($version)['source']);
        $first = RecipeNutritionOverrideEvent::query()->sole();
        $this->assertSame('added', $first->event);
        $this->assertSame(RecipeNutritionSource::ImportedSource, $first->prior_source);
        $this->assertSame('8.000000000000000000', $first->prior_values['protein']['value']);
        $this->assertSame($owner->id, $first->actor_user_id);
        $this->assertSame('Package source was wrong.', $first->note);
        $this->assertSame('2026-09-12T09:00:00+00:00', $first->occurred_at->toIso8601String());

        Date::setTestNow('2026-09-12 10:00:00');
        $this->actingAs($owner)->put(route('recipes.nutrition-override.update', $recipe), [
            'source_version_id' => $version->id,
            'nutrients' => ['protein' => '13'],
            'note' => 'Retested.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $changed = RecipeNutritionOverrideEvent::query()->latest('occurred_at')->firstOrFail();
        $this->assertSame('changed', $changed->event);
        $this->assertSame(RecipeNutritionSource::CreatorOverride, $changed->prior_source);
        $this->assertSame('12.345000000000000000', $changed->prior_values['protein']['value']);

        $this->actingAs($owner)->get(route('recipes.show', $recipe))
            ->assertOk()->assertSee('Creator override')->assertSee('13.0 g')
            ->assertSee('Compare with imported recipe source')->assertSee('Compare with ingredient estimate')
            ->assertSee('Override history')->assertSee('Retested.');

        Date::setTestNow('2026-09-12 11:00:00');
        $this->actingAs($owner)->delete(route('recipes.nutrition-override.destroy', $recipe), [
            'source_version_id' => $version->id,
            'note' => 'Use imported source again.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(RecipeNutritionSource::ImportedSource, $selector->effective($version)['source']);
        $removed = RecipeNutritionOverrideEvent::query()->latest('occurred_at')->firstOrFail();
        $this->assertSame('removed', $removed->event);
        $this->assertSame(RecipeNutritionSource::CreatorOverride, $removed->prior_source);
        $this->assertSame(RecipeNutritionSource::ImportedSource, $removed->resulting_source);
        $this->assertNull($removed->resulting_values);
        $this->assertDatabaseCount('recipe_nutrition_override_events', 3);
        $this->assertSame(3, AuditEvent::query()->where('action', AuditAction::RecipeNutritionOverrideApplied)->count());
        foreach (RecipeNutritionOverrideEvent::all() as $history) {
            $audit = AuditEvent::findOrFail($history->audit_event_id);
            $this->assertTrue($audit->hasValidIntegrityHash());
            $this->assertStringNotContainsString('Package source', json_encode($audit->payload, JSON_THROW_ON_ERROR));
        }
    }

    public function test_override_without_import_restores_estimate_on_removal(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->recipe($owner, $this->snapshot());
        $version = $recipe->currentVersion;
        $this->actingAs($owner)->put(route('recipes.nutrition-override.update', $recipe), [
            'source_version_id' => $version->id, 'nutrients' => ['fat' => '2'],
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->delete(route('recipes.nutrition-override.destroy', $recipe), [
            'source_version_id' => $version->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(RecipeNutritionSource::IngredientEstimate,
            app(RecipeNutritionSourceSelector::class)->effective($version)['source']);
    }

    public function test_override_mutations_enforce_owner_current_version_and_valid_values(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipe = $this->recipe($owner, $this->snapshot());
        $payload = ['source_version_id' => $recipe->current_recipe_version_id, 'nutrients' => ['protein' => '4']];

        $this->put(route('recipes.nutrition-override.update', $recipe), $payload)->assertRedirect(route('login'));
        $this->actingAs($other)->put(route('recipes.nutrition-override.update', $recipe), $payload)->assertForbidden();
        $this->actingAs($owner)->put(route('recipes.nutrition-override.update', $recipe), [
            'source_version_id' => (string) Str::ulid(), 'nutrients' => ['protein' => '4'],
        ])->assertSessionHasErrors('source_version_id');
        $this->actingAs($owner)->put(route('recipes.nutrition-override.update', $recipe), [
            'source_version_id' => $recipe->current_recipe_version_id, 'nutrients' => ['protein' => '-1'],
        ])->assertSessionHasErrors('nutrients.protein');
        $this->assertDatabaseCount('recipe_nutrition_override_events', 0);
    }

    public function test_recipe_value_normalization_covers_every_supported_nutrient_without_float_rounding(): void
    {
        $values = app(RecipeNutritionValueNormalizer::class)->normalize([
            'energy_kcal' => '100.123456789012345678',
            'energy_kj' => '999',
            'fat' => '1.1',
            'saturated_fat' => '2.2',
            'carbohydrates' => '3.3',
            'sugars' => '4.4',
            'fibre' => '5.5',
            'protein' => '6.6',
            'salt' => '0',
            'sodium' => '125',
        ], 'creator_override');

        $this->assertSame([
            'energy_kcal', 'energy_kj', 'fat', 'saturated_fat', 'carbohydrates',
            'sugars', 'fibre', 'protein', 'salt', 'sodium',
        ], array_keys($values));
        $this->assertSame('100.123456789012345678', $values['energy_kcal']['value']);
        $this->assertSame($values['energy_kcal']['value'], $values['energy_kj']['value']);
        $this->assertSame('0.000000000000000000', $values['salt']['value']);
        $this->assertSame('0.125000000000000000', $values['sodium']['value']);
        $this->assertSame('per_serving', $values['protein']['basis']);
        $this->assertFalse($values['protein']['is_estimate']);

        $this->expectException(InvalidArgumentException::class);
        app(RecipeNutritionValueNormalizer::class)->normalize([], 'creator_override');
    }

    public function test_override_and_history_are_isolated_to_the_exact_recipe_version(): void
    {
        $owner = User::factory()->create();
        $recipe = $this->recipe($owner, $this->snapshot(importedProtein: '8'));
        $versionOne = $recipe->currentVersion;
        $this->actingAs($owner)->put(route('recipes.nutrition-override.update', $recipe), [
            'source_version_id' => $versionOne->id, 'nutrients' => ['protein' => '20'],
        ])->assertSessionHasNoErrors();

        $versionTwo = RecipeVersion::factory()->for($recipe)->create([
            'version_number' => 2,
            'snapshot' => $this->snapshot(importedProtein: '9'),
        ]);
        $recipe->forceFill(['current_recipe_version_id' => $versionTwo->id])->save();
        $selector = app(RecipeNutritionSourceSelector::class);

        $this->assertSame(RecipeNutritionSource::CreatorOverride, $selector->effective($versionOne)['source']);
        $this->assertSame(RecipeNutritionSource::ImportedSource, $selector->effective($versionTwo)['source']);
        $this->assertCount(1, $versionOne->nutritionOverrideEvents);
        $this->assertCount(0, $versionTwo->nutritionOverrideEvents);
        $this->actingAs($owner)->put(route('recipes.nutrition-override.update', $recipe), [
            'source_version_id' => $versionOne->id, 'nutrients' => ['protein' => '21'],
        ])->assertSessionHasErrors('source_version_id');
    }

    /** @return array<string, mixed> */
    private function snapshot(?string $importedProtein = null): array
    {
        $estimate = ['protein' => ['value' => '5', 'status' => 'approximate', 'is_estimate' => true]];
        $imported = $importedProtein === null ? null : [
            'type' => 'imported_source', 'is_estimate' => false,
            'per_serving' => app(RecipeNutritionValueNormalizer::class)->normalize(['protein' => $importedProtein], 'imported_recipe_source'),
            'observations' => ['protein' => ['source_field' => 'proteinContent', 'source_value' => $importedProtein, 'source_unit' => 'g']],
            'provenance' => ['recipe_import_id' => 'import:01NUT17', 'extractor_version' => 'rec16-v1', 'parser_version' => 'rec15-v1'],
        ];

        return ['title' => 'Source precedence recipe', 'servings' => '2.00',
            'visibility' => RecipeVisibility::Public->value, 'ingredients' => [], 'sections' => [], 'steps' => [],
            'nutrition_estimate' => ['type' => 'estimate', 'is_estimate' => true, 'calculation_policy_version' => 1,
                'whole_recipe' => ['protein' => ['value' => '10', 'status' => 'approximate', 'is_estimate' => true]],
                'per_serving' => $estimate, 'inputs' => []],
            'imported_nutrition' => $imported];
    }

    private function recipe(User $owner, array $snapshot): Recipe
    {
        $recipe = Recipe::factory()->for($owner, 'owner')->create(['servings' => '2.00',
            'lifecycle' => RecipeLifecycle::Finalized, 'visibility' => RecipeVisibility::Public, 'finalized_at' => now()]);
        $version = RecipeVersion::factory()->for($recipe)->create(['snapshot' => $snapshot]);
        $recipe->forceFill(['current_recipe_version_id' => $version->id])->save();

        return $recipe->fresh('currentVersion');
    }

    private function row(array $rows, string $label): array
    {
        return collect($rows)->firstOrFail(fn (array $row): bool => $row['label'] === $label);
    }
}
