<?php

namespace Tests\Feature\MealPlans;

use App\Domain\Catalogue\CatalogueItemStatus;
use App\Domain\MealPlans\MealPlanItemEntryKind;
use App\Domain\Measurements\StandardUnit;
use App\Models\AuditEvent;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\MealPlanItemEntry;
use App\Models\MealPlanSlot;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class MealPlanItemEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_adds_approved_catalogue_item_with_current_version_amount_and_nutrition_pinned(): void
    {
        [$owner, $plan, , $slot] = $this->planWithSlots();
        $item = CatalogueItem::factory()->approved()->create();
        $version = CatalogueItemVersion::factory()
            ->for($item)
            ->current()
            ->completeNutrition()
            ->create(['name' => 'Pinned porridge', 'version_number' => 1]);

        $this->actingAs($owner)
            ->post(route('meal-plans.item-entries.store', $plan), [
                'slot_id' => $slot->id,
                'kind' => 'catalogue',
                'catalogue_item_id' => $item->id,
                'planned_amount' => '125.5',
                'planned_unit' => StandardUnit::Gram->value,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $entry = MealPlanItemEntry::query()->sole();
        $this->assertSame(MealPlanItemEntryKind::Catalogue, $entry->kind);
        $this->assertSame($item->id, $entry->catalogue_item_id);
        $this->assertSame($version->id, $entry->catalogue_item_version_id);
        $this->assertSame(1, $entry->catalogue_item_version_number);
        $this->assertSame('125.500000000000000000', $entry->planned_amount);
        $this->assertSame(StandardUnit::Gram, $entry->planned_unit);
        $this->assertSame('Pinned porridge', $entry->catalogue_snapshot['name']);
        $this->assertCount(10, $entry->catalogue_nutrition_snapshot);
        $this->assertNull($entry->one_off_wording);
        $this->assertNull($entry->one_off_nutrition);

        $this->get(route('meal-plans.show', $plan))
            ->assertOk()
            ->assertSee('Pinned porridge');
    }

    public function test_catalogue_entry_rejects_pending_items_and_client_selected_versions(): void
    {
        [$owner, $plan, , $slot] = $this->planWithSlots();
        $pending = CatalogueItem::factory()->create(['status' => CatalogueItemStatus::Pending]);
        $version = CatalogueItemVersion::factory()->for($pending)->current()->create();
        $this->actingAs($owner);

        $this->post(route('meal-plans.item-entries.store', $plan), [
            'slot_id' => $slot->id,
            'kind' => 'catalogue',
            'catalogue_item_id' => $pending->id,
            'planned_amount' => '1',
            'planned_unit' => StandardUnit::Item->value,
        ])->assertRedirect()->assertSessionHasErrors('catalogue_item_id');

        $pending->forceFill(['status' => CatalogueItemStatus::Approved])->save();
        $this->post(route('meal-plans.item-entries.store', $plan), [
            'slot_id' => $slot->id,
            'kind' => 'catalogue',
            'catalogue_item_id' => $pending->id,
            'catalogue_item_version_id' => $version->id,
            'planned_amount' => '1',
            'planned_unit' => StandardUnit::Item->value,
        ])->assertRedirect()->assertSessionHasErrors('catalogue_item_version_id');

        $this->assertDatabaseCount('meal_plan_item_entries', 0);
    }

    public function test_later_catalogue_version_does_not_substitute_or_rewrite_pinned_entry(): void
    {
        [$owner, $plan, , $slot] = $this->planWithSlots();
        $item = CatalogueItem::factory()->approved()->create();
        $versionOne = CatalogueItemVersion::factory()
            ->for($item)
            ->current()
            ->incompleteNutrition()
            ->create(['name' => 'Original yoghurt', 'version_number' => 1]);
        $this->actingAs($owner)->post(route('meal-plans.item-entries.store', $plan), [
            'slot_id' => $slot->id,
            'kind' => 'catalogue',
            'catalogue_item_id' => $item->id,
            'planned_amount' => '200',
            'planned_unit' => StandardUnit::Gram->value,
        ])->assertSessionHasNoErrors();
        $entry = MealPlanItemEntry::query()->sole();
        $snapshot = $entry->only([
            'catalogue_item_version_id', 'catalogue_item_version_number', 'catalogue_snapshot',
            'catalogue_nutrition_snapshot', 'planned_amount', 'planned_unit',
        ]);

        $versionTwo = CatalogueItemVersion::factory()
            ->for($item)
            ->completeNutrition()
            ->create(['name' => 'Replacement yoghurt', 'version_number' => 2]);
        $item->setCurrentVersion($versionTwo);

        $entry->refresh();
        $this->assertSame($versionOne->id, $entry->catalogue_item_version_id);
        $this->assertSame($snapshot, $entry->only(array_keys($snapshot)));
        $this->get(route('meal-plans.show', $plan))
            ->assertSee('Original yoghurt')
            ->assertDontSee('Replacement yoghurt');
    }

    public function test_owner_adds_private_one_off_wording_and_optional_nutrition_without_catalogue_submission(): void
    {
        [$owner, $plan, , $slot] = $this->planWithSlots();

        $this->actingAs($owner)
            ->post(route('meal-plans.item-entries.store', $plan), [
                'slot_id' => $slot->id,
                'kind' => 'one_off',
                'one_off_wording' => 'Homemade recovery shake',
                'planned_amount' => '1',
                'planned_unit' => StandardUnit::Bottle->value,
                'one_off_nutrition_basis' => 'per_item',
                'one_off_nutrition' => [
                    'energy_kcal' => '315',
                    'energy_kj' => '999',
                    'fat' => '8',
                    'saturated_fat' => '2',
                    'carbohydrates' => '35',
                    'sugars' => '12',
                    'fibre' => '4',
                    'protein' => '24.5',
                    'salt' => '0.4',
                    'sodium' => '160',
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $entry = MealPlanItemEntry::query()->sole();
        $this->assertSame(MealPlanItemEntryKind::OneOff, $entry->kind);
        $this->assertSame('Homemade recovery shake', $entry->one_off_wording);
        $this->assertSame('one_off_user_entry', $entry->one_off_nutrition['source']);
        $this->assertSame('315', $entry->one_off_nutrition['entered_values']['energy_kcal']);
        $this->assertSame('999', $entry->one_off_nutrition['entered_values']['energy_kj']);
        $this->assertSame('315.000000000000000000', $entry->one_off_nutrition['values']['energy_kcal']['value']);
        $this->assertSame('kcal', $entry->one_off_nutrition['values']['energy_kcal']['unit']);
        $this->assertSame('1317.960000000000000000', $entry->one_off_nutrition['values']['energy_kj']['value']);
        $this->assertSame('kj', $entry->one_off_nutrition['values']['energy_kj']['unit']);
        $this->assertSame('derived_from_one_off_energy_kcal', $entry->one_off_nutrition['values']['energy_kj']['source']);
        $this->assertSame('g', $entry->one_off_nutrition['values']['protein']['unit']);
        $this->assertSame('mg', $entry->one_off_nutrition['values']['sodium']['unit']);
        $this->assertCount(10, $entry->one_off_nutrition['values']);
        $this->assertNull($entry->catalogue_item_id);
        $this->assertDatabaseCount('catalogue_items', 0);
        $this->assertDatabaseCount('catalogue_item_versions', 0);
        $this->assertDatabaseCount('catalogue_nutrient_values', 0);
        $this->assertDatabaseCount('catalogue_moderation_decisions', 0);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertSame(
            ['outcome' => 'recorded', 'snapshot_kind' => 'planned'],
            AuditEvent::query()->sole()->payload,
        );

        $this->get(route('meal-plans.show', $plan))
            ->assertOk()
            ->assertSee('Homemade recovery shake');
    }

    public function test_entry_kinds_reject_mixed_and_incomplete_payloads(): void
    {
        [$owner, $plan, , $slot] = $this->planWithSlots();
        $item = CatalogueItem::factory()->approved()->create();
        CatalogueItemVersion::factory()->for($item)->current()->create(['name' => 'Oats']);
        $this->actingAs($owner);
        $common = [
            'slot_id' => $slot->id,
            'planned_amount' => '1',
            'planned_unit' => StandardUnit::Serving->value,
        ];

        $this->post(route('meal-plans.item-entries.store', $plan), $common + [
            'kind' => 'catalogue',
            'catalogue_item_id' => $item->id,
            'one_off_wording' => 'Mixed content',
        ])->assertSessionHasErrors('one_off_wording');

        $this->post(route('meal-plans.item-entries.store', $plan), $common + [
            'kind' => 'one_off',
            'one_off_wording' => 'Mixed content',
            'catalogue_item_id' => $item->id,
        ])->assertSessionHasErrors('catalogue_item_id');

        $this->post(route('meal-plans.item-entries.store', $plan), $common + [
            'kind' => 'one_off',
            'one_off_nutrition' => ['protein' => '5'],
        ])->assertSessionHasErrors(['one_off_wording', 'one_off_nutrition_basis']);

        $this->post(route('meal-plans.item-entries.store', $plan), $common + [
            'kind' => 'recipe',
        ])->assertSessionHasErrors('kind');

        $this->assertDatabaseCount('meal_plan_item_entries', 0);
    }

    public function test_one_off_form_can_omit_optional_nutrition_with_blank_inputs(): void
    {
        [$owner, $plan, , $slot] = $this->planWithSlots();

        $this->actingAs($owner)->post(route('meal-plans.item-entries.store', $plan), [
            'slot_id' => $slot->id,
            'kind' => 'one_off',
            'one_off_wording' => 'Plain tea',
            'planned_amount' => '1',
            'planned_unit' => StandardUnit::Cup->value,
            'one_off_nutrition_basis' => '',
            'one_off_nutrition' => ['energy_kcal' => '', 'protein' => ''],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $entry = MealPlanItemEntry::query()->sole();
        $this->assertSame('Plain tea', $entry->one_off_wording);
        $this->assertNull($entry->one_off_nutrition);
    }

    public function test_database_rejects_invalid_mixed_payload_and_zero_amount(): void
    {
        [, , , $slot] = $this->planWithSlots();

        try {
            MealPlanItemEntry::factory()->for($slot, 'slot')->create([
                'catalogue_item_id' => 123,
            ]);
            $this->fail('The mixed entry payload should be rejected by the database.');
        } catch (QueryException) {
            $this->assertDatabaseCount('meal_plan_item_entries', 0);
        }

        $this->expectException(QueryException::class);
        MealPlanItemEntry::factory()->for($slot, 'slot')->create(['planned_amount' => '0']);
    }

    public function test_one_off_data_and_all_nested_mutations_are_owner_scoped(): void
    {
        [$owner, $plan, , $slot, $target] = $this->planWithSlots();
        $other = User::factory()->create();
        $this->actingAs($owner)->post(route('meal-plans.item-entries.store', $plan), [
            'slot_id' => $slot->id,
            'kind' => 'one_off',
            'one_off_wording' => 'Private snack notes',
            'planned_amount' => '2',
            'planned_unit' => StandardUnit::Piece->value,
        ])->assertSessionHasNoErrors();
        $entry = MealPlanItemEntry::query()->sole();

        $this->actingAs($other)
            ->get(route('meal-plans.show', $plan))
            ->assertNotFound()
            ->assertDontSee('Private snack notes');
        $this->patch(route('meal-plans.item-entries.update', [$plan, $entry]), [
            'target_slot_id' => $target->id,
        ])->assertNotFound();
        $this->delete(route('meal-plans.item-entries.destroy', [$plan, $entry]))->assertNotFound();
        $this->post(route('meal-plans.item-entries.store', $plan), [
            'slot_id' => $slot->id,
            'kind' => 'one_off',
            'one_off_wording' => 'Injected',
            'planned_amount' => '1',
            'planned_unit' => StandardUnit::Item->value,
        ])->assertNotFound();

        $this->assertDatabaseCount('meal_plan_item_entries', 1);
        $this->assertTrue($entry->fresh()->slot->is($slot));
    }

    public function test_owner_moves_and_removes_entry_without_changing_pinned_data(): void
    {
        [$owner, $plan, , $source, $target] = $this->planWithSlots();
        $entry = MealPlanItemEntry::factory()->for($source, 'slot')->create();
        $entry->refresh();
        $pinned = $entry->getRawOriginal();
        $this->actingAs($owner)
            ->patch(route('meal-plans.item-entries.update', [$plan, $entry]), [
                'target_slot_id' => $target->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $entry->refresh();
        unset($pinned['meal_plan_slot_id'], $pinned['updated_at']);
        $current = $entry->getRawOriginal();
        unset($current['meal_plan_slot_id'], $current['updated_at']);
        $this->assertTrue($entry->slot->is($target));
        $this->assertSame($pinned, $current);

        $this->delete(route('meal-plans.item-entries.destroy', [$plan, $entry]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('meal_plan_item_entries', ['id' => $entry->id]);
    }

    public function test_pinned_item_fields_cannot_be_changed_after_creation(): void
    {
        $entry = MealPlanItemEntry::factory()->create();
        $entry->one_off_wording = 'Changed';

        $this->expectException(LogicException::class);
        $entry->save();
    }

    /** @return array{User, MealPlan, MealPlanDay, MealPlanSlot, MealPlanSlot} */
    private function planWithSlots(): array
    {
        $owner = User::factory()->create();
        $plan = MealPlan::factory()->for($owner, 'owner')->reusable()->create();
        $day = new MealPlanDay;
        $day->forceFill(['day_index' => 0, 'date' => null]);
        $day->mealPlan()->associate($plan);
        $day->save();
        $source = new MealPlanSlot;
        $source->forceFill(['standard_key' => null, 'name' => 'Breakfast', 'position' => 0]);
        $source->day()->associate($day);
        $source->save();
        $target = new MealPlanSlot;
        $target->forceFill(['standard_key' => null, 'name' => 'Dinner', 'position' => 1]);
        $target->day()->associate($day);
        $target->save();

        return [$owner, $plan, $day, $source, $target];
    }
}
