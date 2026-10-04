<?php

namespace Tests\Feature\Recipes;

use App\Domain\Catalogue\CatalogueItemStatus;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientRegistry;
use App\Domain\Nutrition\RecipeNutritionEstimatePresenter;
use App\Domain\Recipes\RecipeIngredientMatchManager;
use App\Domain\Recipes\RecipeIngredientMatchPresenter;
use App\Domain\Recipes\RecipeRevisionManager;
use App\Domain\Recipes\RecipeVersionContent;
use App\Livewire\Recipes\Form;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecipeMatchReviewUxTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{?array<string, mixed>, string, string}> */
    public static function matchLabels(): iterable
    {
        yield 'unmatched' => [null, 'No food selected — excluded from estimate', 'No food selected — excluded from estimate'];
        yield 'review needed' => [['review_state' => 'needs_review'], 'Selected — needs review', 'Selected — needs review'];
        yield 'automatic high' => [['provenance' => 'automatically_selected', 'confidence_band' => 'high', 'review_state' => 'confirmed'], 'Automatically selected', 'Automatically selected'];
        yield 'kept automatic' => [['provenance' => 'automatically_selected', 'confidence_band' => 'reviewable', 'review_state' => 'confirmed'], 'Reviewed by you', 'Reviewed by creator'];
        yield 'manual' => [['provenance' => 'manually_selected_by_creator', 'review_state' => 'confirmed'], 'Selected by you', 'Selected by creator'];
        yield 'moderation replacement' => [['provenance' => 'owner_confirmed_replacement', 'review_state' => 'confirmed'], 'Selected by you', 'Selected by creator'];
        yield 'unavailable' => [['unavailable' => true, 'review_state' => 'needs_review'], 'Selected food unavailable — choose an approved replacement or clear match', 'Selected food unavailable — choose an approved replacement or clear match'];
    }

    /** @param array<string, mixed>|null $match */
    #[DataProvider('matchLabels')]
    public function test_status_copy_distinguishes_recorded_actions_for_creator_and_reader(?array $match, string $creator, string $reader): void
    {
        $presenter = app(RecipeIngredientMatchPresenter::class);
        $this->assertSame($creator, $presenter->label($match, true));
        $this->assertSame($reader, $presenter->label($match));
    }

    public function test_keep_preserves_evidence_wording_unsaved_edits_and_other_limitations(): void
    {
        [$owner, $recipe, $line, $item] = $this->reviewableRecipe();
        $evidence = $line->catalogueMatch()->sole()->only(['candidate_score', 'confidence_band', 'threshold_version', 'provenance', 'catalogue_item_version_id']);
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->assertSee('Selected — needs review')
            ->assertDontSee('confirmed selection')
            ->assertSee('weaker matching evidence')
            ->assertSee('Ingredients needing attention: 1')
            ->assertSee('aria-label="Keep this food for ingredient 1:', false)
            ->assertSee('aria-describedby="ingredient-review-1"', false)
            ->assertSee('aria-describedby="ingredient-limitations-1"', false)
            ->assertDontSee('aria-invalid="true"', false)
            ->set('title', 'Unsaved title')
            ->set('ingredients.0.notes', 'Unsaved note')
            ->call('keepCatalogueMatch', 0, $item->current_catalogue_item_version_id)
            ->assertHasNoErrors()
            ->assertSet('title', 'Unsaved title')
            ->assertSet('ingredients.0.notes', 'Unsaved note')
            ->assertSet('unsaved', true)
            ->assertSee('Reviewed by you')
            ->assertDontSee('Selected — needs review')
            ->assertSee('Ingredients needing attention: 1')
            ->assertSee('The custom unit cannot be converted reliably.')
            ->assertSee('Food kept and reviewed.')
            ->assertDispatched('ingredient-match-updated', target: 'ingredient-review-1');

        $match = $line->catalogueMatch()->sole();
        $this->assertSame($evidence, $match->only(array_keys($evidence)));
        $this->assertSame('confirmed', $match->getRawOriginal('review_state'));
        $this->assertSame($owner->id, $match->selected_by_user_id);
        $this->assertSame('Original handful of oats', $line->fresh()->original_text);
        $component->call('clearCatalogueMatch', 0)->assertHasNoErrors()
            ->assertSee('No food selected — excluded from estimate')
            ->assertSee('Original wording kept; this ingredient is excluded')
            ->assertSee('Ingredients needing attention: 1');
        $this->assertFalse($line->catalogueMatch()->exists());
    }

    public function test_review_is_optional_and_published_history_stays_unchanged_until_revision_is_published(): void
    {
        [$owner, $recipe, $line, $item] = $this->reviewableRecipe();
        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])->call('finalize')->assertHasNoErrors();
        $published = $recipe->fresh()->currentVersion;
        $this->assertSame('needs_review', $published->snapshot['ingredients'][0]['catalogue_match']['review_state']);
        $this->get(route('recipes.show', $recipe))->assertOk()->assertSee('Selected — needs review')
            ->assertSee('Selected food:')->assertSee('Oats')->assertDontSee('Keep this food');
        try {
            app(RecipeIngredientMatchManager::class)->keep($recipe->id, $line->id, $item->current_catalogue_item_version_id, $owner);
            $this->fail('Published content requires an active revision before review.');
        } catch (AuthorizationException) {
            // Published matches cannot be changed in place.
        }
        app(RecipeRevisionManager::class)->startOrResume($recipe->id, $owner);
        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe->fresh()])
            ->call('keepCatalogueMatch', 0, $item->current_catalogue_item_version_id)->assertHasNoErrors();
        $this->assertSame($published->snapshot, $published->fresh()->snapshot);
        $this->actingAs($owner)->get(route('recipes.show', [$recipe, 'preview' => 'draft']))
            ->assertOk()->assertSee('Reviewed by you')->assertSee('Estimate limitations')
            ->assertSee('Ingredients needing attention: 1')->assertSee('Estimated nutrition — per serving');
        $this->get(route('recipes.show', $recipe))->assertSee('Selected — needs review');
        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe->fresh()])->call('finalize')->assertHasNoErrors();
        $this->assertSame('confirmed', $recipe->fresh()->currentVersion->snapshot['ingredients'][0]['catalogue_match']['review_state']);
        $this->assertSame('Oats', $recipe->fresh()->currentVersion->snapshot['ingredients'][0]['catalogue_match']['name']);
        app(RecipeRevisionManager::class)->startOrResume($recipe->id, $owner);
        $this->assertSame($owner->id, $recipe->fresh()->ingredientLines()->sole()->catalogueMatch()->sole()->selected_by_user_id);
    }

    public function test_keep_rejects_nonowners_foreign_lines_stale_selections_and_unavailable_food(): void
    {
        [$owner, $recipe, $line, $item] = $this->reviewableRecipe();
        Livewire::actingAs(User::factory()->create())->test(Form::class, ['recipe' => $recipe])->assertForbidden();
        try {
            app(RecipeIngredientMatchManager::class)->keep($recipe->id, $line->id, $item->current_catalogue_item_version_id, User::factory()->create());
            $this->fail('The review mutation must independently reauthorize its actor.');
        } catch (AuthorizationException) {
            // Hiding or denying the editor is not the mutation authorization boundary.
        }
        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->call('keepCatalogueMatch', 0, 'stale-version')->assertHasErrors('catalogue_match');
        $foreign = RecipeIngredientLine::factory()->for(Recipe::factory()->for($owner, 'owner'))->create();
        try {
            app(RecipeIngredientMatchManager::class)->keep($recipe->id, $foreign->id, $item->current_catalogue_item_version_id, $owner);
            $this->fail('A foreign ingredient must not be reviewed through this recipe.');
        } catch (ModelNotFoundException) {
            // The mutation reloads the line through the authorized recipe.
        }
        $item->forceFill(['status' => CatalogueItemStatus::Rejected])->save();
        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->assertSee('Selected food unavailable')->assertDontSee('Keep this food')
            ->assertDontSee('keep it, search to replace')
            ->call('keepCatalogueMatch', 0, $item->current_catalogue_item_version_id)->assertHasErrors('catalogue_match');
        $this->assertSame('needs_review', $line->catalogueMatch()->sole()->getRawOriginal('review_state'));
    }

    public function test_attention_counts_lines_once_retains_every_nutrient_reason_and_remedy(): void
    {
        [$owner, $recipe] = $this->reviewableRecipe();
        $snapshot = app(RecipeVersionContent::class)->snapshot($recipe);
        $presented = app(RecipeNutritionEstimatePresenter::class)->present($snapshot);
        $this->assertCount(1, $presented['issues']);
        $issue = $presented['issues'][0];
        $this->assertSame('Oats', $issue['selected_food']);
        $this->assertCount(2, $issue['reasons']);
        $this->assertStringContainsString('creator review', $issue['reasons'][0]);
        $this->assertStringContainsString('custom unit cannot be converted', $issue['reasons'][1]);
        foreach (Nutrient::cases() as $nutrient) {
            $this->assertStringContainsString(NutrientRegistry::definition($nutrient)->label, $issue['reasons'][1]);
        }
        $this->assertCount(2, $issue['remedies']);
        $this->actingAs($owner)->get(route('recipes.show', $recipe))->assertOk()
            ->assertSee('Ingredients needing attention: 1')->assertSee('Original handful of oats')
            ->assertSee('Use a supported unit only if you know the equivalent amount');
    }

    public function test_keep_removes_only_review_attention_and_repeat_review_preserves_the_recorded_action(): void
    {
        [$owner, $recipe, $line, $item] = $this->reviewableRecipe();
        $line->forceFill(['standard_unit' => StandardUnit::Gram, 'custom_unit' => null])->save();
        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->assertSee('Ingredients needing attention: 1')
            ->call('keepCatalogueMatch', 0, $item->current_catalogue_item_version_id)
            ->assertHasNoErrors()->assertSee('Ingredients needing attention: 0')->assertSee('Reviewed by you');
        $match = $line->catalogueMatch()->sole();
        app(RecipeIngredientMatchManager::class)->keep($recipe->id, $line->id, $item->current_catalogue_item_version_id, $owner);
        $this->assertSame($match->getAttributes(), $match->fresh()->getAttributes());
    }

    /** @return array{User, Recipe, RecipeIngredientLine, CatalogueItem} */
    private function reviewableRecipe(): array
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        $line = $recipe->ingredientLines()->sole();
        $line->forceFill(['original_text' => 'Original handful of oats', 'quantity' => '1', 'standard_unit' => null, 'custom_unit' => 'handful'])->save();
        $item = CatalogueItem::factory()->approved()->create();
        $version = CatalogueItemVersion::factory()->for($item, 'catalogueItem')->completeNutrition()->create(['name' => 'Oats']);
        $item->setCurrentVersion($version);
        app(RecipeIngredientMatchManager::class)->selectAutomatically($recipe->id, $line->id, $item->id, (string) $version->id, '0.975123456789012345', $owner);

        return [$owner, $recipe->fresh(), $line, $item->fresh()];
    }
}
