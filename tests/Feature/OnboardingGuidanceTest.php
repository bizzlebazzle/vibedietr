<?php

namespace Tests\Feature;

use App\Livewire\Catalogue\Index as CatalogueIndex;
use App\Livewire\Recipes\Form;
use App\Models\Bookmark;
use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\MealPlanSlot;
use App\Models\PrivateRecipeTag;
use App\Models\Recipe;
use App\Models\RecipeCollection;
use App\Models\RecipeIngredientLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OnboardingGuidanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_account_gets_a_truthful_path_even_when_other_accounts_have_private_work(): void
    {
        Recipe::factory()->create(['title' => 'Another account secret']);
        MealPlan::factory()->create(['name' => 'Another private plan']);
        $owner = User::factory()->create();

        $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertSee('Get started with VibeDietr')
            ->assertSee('Imports start as private drafts that need review.')
            ->assertSee('Save your ingredient lines')
            ->assertSee('missing values are not zero')
            ->assertSee('Draft recipes cannot be planned.')
            ->assertSee('not verified facts or medical advice')
            ->assertSee(route('recipes.create'), false)
            ->assertSee(route('recipe-imports.create'), false)
            ->assertSee(route('meal-plans.create'), false)
            ->assertDontSee('Another account secret')
            ->assertDontSee('Another private plan');

        Recipe::factory()->for($owner, 'owner')->create();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Get started with VibeDietr')
            ->assertSee('You have no meal plans yet.')
            ->assertSee('Add a day to get meal slots');
    }

    public function test_empty_recipe_discovery_guides_guests_without_offering_authoring_actions(): void
    {
        $this->get(route('recipes.index'))->assertOk()->assertSee('No public recipes yet.')
            ->assertSee('return later to discover public recipes')
            ->assertDontSee('Create a recipe')->assertDontSee('Import a recipe');
        $this->get(route('recipes.index', ['q' => 'missing']))->assertOk()
            ->assertSee('Try a broader title or tag, or clear your search above.')
            ->assertSee(route('recipes.index'), false);
        $this->actingAs(User::factory()->create())->get(route('recipes.index'))->assertOk()
            ->assertSee('Create a recipe')->assertSee('Import a recipe');
    }

    public function test_empty_catalogue_guidance_and_manual_submission_are_permission_specific(): void
    {
        config(['catalogue.read_cutover' => true]);
        Livewire::test(CatalogueIndex::class)->set('search', 'missing')
            ->assertSee('Try another food name or barcode, or clear the search field.')
            ->assertDontSee('Submit manual food')->assertDontSee('their submitter');
        Livewire::actingAs(User::factory()->create())->test(CatalogueIndex::class)
            ->assertSee('Submit manual food')
            ->assertSee(route('catalogue.manual.create'), false)
            ->assertSee('selectable only by their submitter until approved');
    }

    /** @return iterable<string, array{string, string}> */
    public static function emptyLists(): iterable
    {
        yield 'bookmarks' => ['bookmarks.index', 'Open a public recipe and choose Bookmark'];
        yield 'collections' => ['recipe-collections.index', 'Enter a name above to create one'];
        yield 'private tags' => ['private-recipe-tags.index', 'Enter a name above to create one'];
        yield 'plans' => ['meal-plans.index', 'Add a day for meal slots'];
    }

    #[DataProvider('emptyLists')]
    public function test_empty_private_lists_give_supported_next_steps(string $route, string $guidance): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route($route))->assertOk()->assertSee($guidance);
    }

    public function test_empty_organizations_distinguish_missing_library_from_unassigned_members(): void
    {
        $owner = User::factory()->create();
        $collection = RecipeCollection::factory()->for($owner, 'owner')->create();
        $tag = PrivateRecipeTag::factory()->for($owner, 'owner')->create();
        $this->actingAs($owner);
        foreach ([['recipe-collections.show', $collection, 'Add'], ['private-recipe-tags.show', $tag, 'Apply']] as [$route, $organization, $verb]) {
            $this->get(route($route, $organization))->assertOk()
                ->assertSee('Create or import a recipe first')
                ->assertSee('Bookmark a public recipe first')
                ->assertSee('Discover recipes to bookmark');
        }
        Recipe::factory()->for($owner, 'owner')->create();
        Bookmark::factory()->for($owner, 'owner')->create();
        foreach ([['recipe-collections.show', $collection, 'Add'], ['private-recipe-tags.show', $tag, 'Apply']] as [$route, $organization, $verb]) {
            $this->get(route($route, $organization))->assertOk()
                ->assertSee('Choose an owned recipe above and use '.$verb.'.')
                ->assertSee('Choose a bookmark above and use '.$verb.'.')
                ->assertDontSee('Create or import a recipe first');
            $this->actingAs(User::factory()->create())->get(route($route, $organization))->assertNotFound();
            $this->actingAs($owner);
        }
    }

    public function test_draft_empty_rows_and_empty_match_results_explain_how_to_continue_without_losing_wording(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();
        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->assertSee('Use Add ingredient line above')
            ->assertSee('Use Add step above');
        $line = RecipeIngredientLine::factory()->for($recipe)->create(['original_text' => 'Unusual ingredient', 'position' => 0]);
        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->set('title', 'Unsaved recipe title')
            ->set('catalogueSearches.'.$line->id, 'missing')
            ->call('searchCatalogue', 0)
            ->assertSee('No selectable catalogue records found.')
            ->assertSee('Try a simpler food name or barcode')
            ->assertSee('keep the original wording without a match')
            ->assertSee('Save draft changes before leaving.')
            ->assertSee(route('catalogue.manual.create'), false)
            ->assertSet('title', 'Unsaved recipe title');
        $this->assertSame('Unusual ingredient', $recipe->ingredientLines()->sole()->original_text);
    }

    public function test_empty_planning_days_and_slots_give_owner_actions_and_read_only_guidance_for_readers(): void
    {
        $owner = User::factory()->create();
        $plan = MealPlan::factory()->for($owner, 'owner')->reusable()->create();
        $this->actingAs($owner)->get(route('meal-plans.index'))->assertOk()
            ->assertSee('Shared plans are read-only')
            ->assertSee('choose Bookmark privately');
        $this->get(route('meal-plans.show', $plan))->assertOk()
            ->assertSee('Use Add day above to create meal slots.')
            ->assertSee('Day index starts at 0');
        $day = MealPlanDay::factory()->for($plan)->create(['date' => null, 'day_index' => 0]);
        MealPlanSlot::factory()->for($day, 'day')->create();
        $this->get(route('meal-plans.show', $plan))->assertOk()
            ->assertSee('This slot has no planned entries.')
            ->assertSee('Use the recipe ID from its page URL')
            ->assertSee('Draft recipes cannot be planned.')
            ->assertSee('later recipe corrections do not silently change it');
        $plan->forceFill(['visibility' => 'public'])->save();
        $this->actingAs(User::factory()->create())->get(route('meal-plans.show', $plan))->assertOk()
            ->assertSee('No planned entries. You are viewing a read-only plan.')
            ->assertDontSee('This slot has no planned entries.')
            ->assertDontSee('Add recipe</button>', false);
        auth()->logout();
        $empty = MealPlan::factory()->reusable()->public()->create();
        $this->get(route('meal-plans.show', $empty))->assertOk()
            ->assertSee('No days added yet. You are viewing a read-only plan.')
            ->assertDontSee('Use Add day above');
        $retained = MealPlan::factory()->reusable()->retainedUnlisted()->create(['user_id' => null]);
        $this->get(route('meal-plans.show', $retained))->assertOk()
            ->assertSee('No days added yet. You are viewing a read-only plan.')
            ->assertDontSee('by its owner')->assertDontSee('Add day</button>', false);
    }
}
