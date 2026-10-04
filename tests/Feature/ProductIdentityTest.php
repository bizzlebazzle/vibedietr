<?php

namespace Tests\Feature;

use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_landing_identifies_real_product_areas_and_auth_actions(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<title>VibeDietr — Recipes and meal planning</title>', false)
            ->assertSee('Discover recipes')
            ->assertSee('Food catalogue')
            ->assertSee('rel="icon" type="image/svg+xml"', false)
            ->assertSee('prefers-color-scheme: dark')
            ->assertSee('name="description"', false)
            ->assertSee('Create account')
            ->assertDontSee('Laravel documentation')
            ->assertDontSee('Dashboard</a>', false);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('VibeDietr')
            ->assertSee('<title>Log in — VibeDietr</title>', false);
    }

    public function test_authenticated_landing_and_empty_dashboard_offer_available_actions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertOk()->assertSee('Dashboard</a>', false)->assertDontSee('Create account');

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('You have no recipes yet.')
            ->assertSee('You have no meal plans yet.')
            ->assertSee('Create a recipe')
            ->assertSee('Import a recipe')
            ->assertSee('Bookmarks')
            ->assertSee('Collections')
            ->assertSee('Private tags')
            ->assertSee('Create a meal plan')
            ->assertSee('Browse the food catalogue')
            ->assertSee('<title>Your dashboard — VibeDietr</title>', false);
    }

    public function test_dashboard_counts_only_owners_private_records(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Recipe::factory()->create(['user_id' => $owner->id, 'title' => 'My private draft']);
        Recipe::factory()->create(['user_id' => $other->id, 'title' => 'Someone else private draft']);
        MealPlan::factory()->create(['user_id' => $owner->id, 'name' => 'My private plan']);
        MealPlan::factory()->create(['user_id' => $other->id, 'name' => 'Someone else private plan']);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('You have 1 recipe.')
            ->assertSee('You have 1 meal plan.')
            ->assertDontSee('Someone else private draft')
            ->assertDontSee('Someone else private plan');
    }

    public function test_primary_navigation_has_desktop_and_mobile_links_with_active_state(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('meal-plans.index'))
            ->assertOk()
            ->assertSee('aria-label="Toggle navigation"', false)
            ->assertSee('id="mobile-navigation"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee(route('recipes.index'), false)
            ->assertSee(route('catalogue.index'), false)
            ->assertSee(route('meal-plans.index'), false)
            ->assertSee('dark:bg-slate-900');

        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('meal-plans.index'), '/').'"[^>]*aria-current="page"/', $response->getContent());
        $recipes = $this->get(route('recipes.create'))->assertOk();
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('recipes.index'), '/').'"[^>]*aria-current="page"/', $recipes->getContent());
    }

    public function test_guest_cannot_open_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
