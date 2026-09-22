<?php

namespace Tests\Feature\MealPlans;

use App\Domain\MealPlans\MealPlanVisibility;
use App\Domain\Recipes\RecipeVisibility;
use App\Models\MealPlan;
use App\Models\MealPlanBookmark;
use App\Models\MealPlanDay;
use App\Models\MealPlanItemEntry;
use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanShare;
use App\Models\MealPlanSlot;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MealPlanSharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_user_has_read_only_access_while_every_other_viewer_and_editor_boundary_is_denied(): void
    {
        $owner = User::factory()->create();
        $selected = User::factory()->create();
        $other = User::factory()->create();
        $administrator = User::factory()->create(['is_administrator' => true]);
        [$plan, $slot] = $this->planSlot($owner, 'Owner plan');
        MealPlanRecipeEntry::factory()->for($slot, 'slot')->create([
            'recipe_snapshot' => $this->snapshot('Public supper', RecipeVisibility::Public),
        ]);

        $this->actingAs($owner)->post(route('meal-plans.shares.store', $plan), [
            'recipient_email' => $selected->email,
        ])->assertSessionHasNoErrors();

        $this->actingAs($selected)->get(route('meal-plans.show', $plan))
            ->assertOk()
            ->assertSee('Public supper')
            ->assertSee('Read-only plan shared with you')
            ->assertDontSee('Edit meal plan')
            ->assertDontSee('Add recipe');
        $this->get(route('meal-plans.index'))->assertOk()->assertSee('Owner plan');
        $this->get(route('meal-plans.edit', $plan))->assertNotFound();
        $this->patch(route('meal-plans.update', $plan), [
            'name' => 'Forged',
            'type' => 'reusable',
        ])->assertNotFound();
        $this->post(route('meal-plans.days.store', $plan), ['day_index' => 1])->assertNotFound();

        $this->actingAs($other)->get(route('meal-plans.show', $plan))->assertNotFound();
        $this->actingAs($administrator)->get(route('meal-plans.show', $plan))->assertNotFound();
        $this->get(route('meal-plans.show', $plan))->assertNotFound();
        $this->assertSame('Owner plan', $plan->fresh()->name);
    }

    public function test_private_recipe_snapshot_share_requires_explicit_acknowledgement_and_does_not_publish_live_recipe(): void
    {
        $owner = User::factory()->create();
        $selected = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->finalizedPrivate()->create(['title' => 'Live secret recipe']);
        [$plan, $slot] = $this->planSlot($owner);
        MealPlanRecipeEntry::factory()->for($slot, 'slot')->create([
            'recipe_id' => $recipe->id,
            'recipe_version_id' => $recipe->current_recipe_version_id,
            'recipe_snapshot' => $this->snapshot('Pinned private recipe', RecipeVisibility::Private),
        ]);

        $this->actingAs($owner)->post(route('meal-plans.shares.store', $plan), [
            'recipient_email' => $selected->email,
        ])->assertSessionHasErrors('acknowledge_private_recipe_snapshots');
        $this->assertDatabaseCount('meal_plan_shares', 0);

        $this->post(route('meal-plans.shares.store', $plan), [
            'recipient_email' => $selected->email,
            'acknowledge_private_recipe_snapshots' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertNotNull(MealPlanShare::query()->sole()->private_recipe_snapshots_acknowledged_at);

        $this->actingAs($selected)->get(route('meal-plans.show', $plan))
            ->assertOk()
            ->assertSee('Pinned private recipe')
            ->assertDontSee('Live secret recipe');
        $this->get(route('recipes.show', $recipe))->assertNotFound();
    }

    public function test_unacknowledged_share_fails_closed_if_private_snapshot_is_added_later(): void
    {
        $owner = User::factory()->create();
        $selected = User::factory()->create();
        [$plan, $slot] = $this->planSlot($owner);
        $share = MealPlanShare::factory()->for($plan, 'mealPlan')->for($selected, 'recipient')->create();

        $this->actingAs($selected)->get(route('meal-plans.show', $plan))->assertOk();

        MealPlanRecipeEntry::factory()->for($slot, 'slot')->create([
            'recipe_snapshot' => $this->snapshot('Later private snapshot', RecipeVisibility::Private),
        ]);

        $this->get(route('meal-plans.show', $plan))->assertNotFound();
        $this->assertNull($share->fresh()->private_recipe_snapshots_acknowledged_at);
    }

    public function test_revocation_takes_effect_immediately_and_cannot_be_performed_by_recipient(): void
    {
        $owner = User::factory()->create();
        $selected = User::factory()->create();
        $plan = MealPlan::factory()->for($owner, 'owner')->create();
        $share = MealPlanShare::factory()->for($plan, 'mealPlan')->for($selected, 'recipient')->create();

        $this->actingAs($selected)->delete(route('meal-plans.shares.destroy', [$plan, $share]))->assertNotFound();
        $this->get(route('meal-plans.show', $plan))->assertOk();

        $this->actingAs($owner)->delete(route('meal-plans.shares.destroy', [$plan, $share]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('meal_plan_shares', 0);
        $this->actingAs($selected)->get(route('meal-plans.show', $plan))->assertNotFound();
    }

    public function test_publication_rejects_the_whole_plan_for_any_private_recipe_or_one_off_entry(): void
    {
        $owner = User::factory()->create();
        [$plan, $slot] = $this->planSlot($owner);
        MealPlanRecipeEntry::factory()->for($slot, 'slot')->create([
            'recipe_snapshot' => $this->snapshot('Safe public snapshot', RecipeVisibility::Public),
        ]);
        MealPlanRecipeEntry::factory()->for($slot, 'slot')->create([
            'recipe_snapshot' => $this->snapshot('Unsafe private snapshot', RecipeVisibility::Private),
        ]);
        MealPlanItemEntry::factory()->for($slot, 'slot')->create(['one_off_wording' => 'Private health note']);

        $this->actingAs($owner)->post(route('meal-plans.public.store', $plan))
            ->assertSessionHasErrors('visibility');

        $this->assertSame(MealPlanVisibility::Private, $plan->fresh()->visibility);
        $this->get(route('meal-plans.show', $plan))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('meal-plans.show', $plan))->assertNotFound();
        $this->assertDatabaseMissing('audit_events', ['action' => 'plan.sharing_changed']);
    }

    public function test_public_plan_is_logged_out_read_only_and_unsafe_entries_cannot_be_added_after_publication(): void
    {
        $owner = User::factory()->create();
        [$plan, $slot] = $this->planSlot($owner, 'Public week');
        MealPlanRecipeEntry::factory()->for($slot, 'slot')->create([
            'recipe_snapshot' => $this->snapshot('Public recipe snapshot', RecipeVisibility::Public),
        ]);

        $this->actingAs($owner)->post(route('meal-plans.public.store', $plan))->assertSessionHasNoErrors();
        $this->app['auth']->guard()->logout();

        $this->get(route('meal-plans.show', $plan))
            ->assertOk()
            ->assertSee('Public week')
            ->assertSee('Public recipe snapshot')
            ->assertSee('Read-only public plan')
            ->assertDontSee('Edit meal plan')
            ->assertDontSee('Add recipe');

        $privateRecipe = Recipe::factory()->for($owner, 'owner')->finalizedPrivate()->create();
        $this->actingAs($owner)->post(route('meal-plans.recipe-entries.store', $plan), [
            'slot_id' => $slot->id,
            'recipe_id' => $privateRecipe->id,
            'planned_servings' => '1',
        ])->assertSessionHasErrors('recipe_id');
        $this->assertDatabaseCount('meal_plan_recipe_entries', 1);
    }

    public function test_bookmarks_are_private_removable_and_do_not_create_or_copy_a_plan(): void
    {
        $owner = User::factory()->create(['email' => 'owner-private@example.test']);
        $bookmarker = User::factory()->create(['email' => 'bookmark-private@example.test']);
        $observer = User::factory()->create();
        $plan = MealPlan::factory()->for($owner, 'owner')->public()->create(['name' => 'Source public plan']);
        $planCount = MealPlan::query()->count();

        $this->actingAs($bookmarker)->post(route('meal-plans.bookmarks.store', $plan))
            ->assertSessionHasNoErrors();

        $bookmark = MealPlanBookmark::query()->sole();
        $this->assertSame($planCount, MealPlan::query()->count());
        $this->assertSame($plan->id, $bookmark->meal_plan_id);
        $this->assertSame($bookmarker->id, $bookmark->user_id);

        $this->get(route('meal-plans.index'))->assertOk()->assertSee('Source public plan');
        $this->actingAs($owner)->get(route('meal-plans.index'))->assertDontSee($bookmarker->email);
        $this->actingAs($observer)->get(route('meal-plans.index'))->assertSee('You have no public plan bookmarks.');
        $this->get(route('meal-plans.show', $plan))
            ->assertDontSee($bookmarker->email);

        $this->actingAs($bookmarker)->delete(route('meal-plans.bookmarks.destroy', $bookmark))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('meal_plan_bookmarks', 0);
        $this->assertDatabaseHas('meal_plans', ['id' => $plan->id]);
    }

    public function test_non_owner_cannot_remove_another_users_private_bookmark(): void
    {
        $plan = MealPlan::factory()->public()->create();
        $owner = User::factory()->create();
        $bookmark = $this->bookmark($owner, $plan);

        $this->actingAs(User::factory()->create())
            ->delete(route('meal-plans.bookmarks.destroy', $bookmark))
            ->assertNotFound();

        $this->assertDatabaseHas('meal_plan_bookmarks', ['id' => $bookmark->id]);
    }

    public function test_retained_unlisted_plan_rejects_new_bookmarks_and_is_deleted_after_final_existing_bookmark(): void
    {
        $plan = MealPlan::factory()->retainedUnlisted()->create(['name' => 'Retained plan']);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $firstBookmark = $this->bookmark($first, $plan);
        $secondBookmark = $this->bookmark($second, $plan);

        $this->get(route('meal-plans.show', $plan))
            ->assertOk()
            ->assertSee('Former VibeDietr user')
            ->assertSee('unlisted public plan')
            ->assertDontSee($first->email)
            ->assertDontSee($second->email);

        $this->actingAs(User::factory()->create())->post(route('meal-plans.bookmarks.store', $plan))
            ->assertSessionHasErrors('bookmark');

        $this->actingAs($first)->delete(route('meal-plans.bookmarks.destroy', $firstBookmark))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('meal_plans', ['id' => $plan->id]);

        $this->actingAs($second)->delete(route('meal-plans.bookmarks.destroy', $secondBookmark))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('meal_plans', ['id' => $plan->id]);
        $this->get(route('meal-plans.show', $plan->id))->assertNotFound();
    }

    public function test_read_only_projection_minimizes_account_share_bookmark_and_snapshot_metadata(): void
    {
        $owner = User::factory()->create([
            'name' => 'Private Legal Name',
            'email' => 'private-owner@example.test',
        ]);
        $bookmarker = User::factory()->create(['email' => 'private-bookmarker@example.test']);
        [$plan, $slot] = $this->planSlot($owner, 'Minimized plan');
        MealPlanRecipeEntry::factory()->for($slot, 'slot')->create([
            'recipe_snapshot' => [
                ...$this->snapshot('Allowed title', RecipeVisibility::Public),
                'owner_email' => $owner->email,
                'private_account_note' => 'Never render this',
            ],
            'nutrition_snapshot' => [
                'source' => 'ingredient_estimate',
                'values' => [],
                'provenance' => ['private_provider_reference' => 'secret-reference'],
                'ingredient_estimate' => [],
            ],
        ]);
        $plan->forceFill(['visibility' => MealPlanVisibility::Public, 'published_at' => now()->utc()])->save();
        $this->bookmark($bookmarker, $plan);

        $this->get(route('meal-plans.show', $plan))
            ->assertOk()
            ->assertSee('Allowed title')
            ->assertDontSee($owner->name)
            ->assertDontSee($owner->email)
            ->assertDontSee($bookmarker->email)
            ->assertDontSee('Never render this')
            ->assertDontSee('secret-reference')
            ->assertDontSee('bookmark owner')
            ->assertDontSee('consumption');
    }

    private function snapshot(string $title, RecipeVisibility $visibility): array
    {
        return [
            'title' => $title,
            'servings' => '2.00',
            'visibility' => $visibility->value,
            'ingredients' => [],
            'sections' => [],
            'steps' => [],
        ];
    }

    /** @return array{MealPlan, MealPlanSlot} */
    private function planSlot(User $owner, string $name = 'Shared plan'): array
    {
        $plan = MealPlan::factory()->for($owner, 'owner')->reusable()->create(['name' => $name]);
        $day = new MealPlanDay;
        $day->forceFill(['day_index' => 0, 'date' => null]);
        $day->mealPlan()->associate($plan);
        $day->save();
        $slot = new MealPlanSlot;
        $slot->forceFill(['standard_key' => null, 'name' => 'Dinner', 'position' => 0]);
        $slot->day()->associate($day);
        $slot->save();

        return [$plan, $slot];
    }

    private function bookmark(User $owner, MealPlan $plan): MealPlanBookmark
    {
        $bookmark = new MealPlanBookmark;
        $bookmark->forceFill(['user_id' => $owner->id, 'meal_plan_id' => $plan->id]);
        $bookmark->save();

        return $bookmark;
    }
}
