<?php

namespace Tests\Feature\Recipes;

use App\Domain\Catalogue\CatalogueItemOrigin;
use App\Domain\Catalogue\CatalogueItemStatus;
use App\Domain\Catalogue\CatalogueReadQuery;
use App\Domain\Recipes\PublicRecipe;
use App\Domain\Recipes\RecipeIngredientMatchManager;
use App\Domain\Recipes\RecipeIngredientMatchProvenance;
use App\Domain\Recipes\RecipeIngredientMatchReviewState;
use App\Livewire\Recipes\Form;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeIngredientCatalogueMatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_selectable_search_applies_visibility_before_stable_pagination_and_projects_only_choice_fields(): void
    {
        $creator = User::factory()->create();
        $other = User::factory()->create();

        foreach (range(1, 9) as $number) {
            $this->catalogueItem($other, CatalogueItemStatus::Approved, CatalogueItemOrigin::Manual, "Apple {$number}");
        }

        $ownPending = $this->catalogueItem($creator, CatalogueItemStatus::Pending, CatalogueItemOrigin::Manual, 'My pending apple');
        $hiddenPending = $this->catalogueItem($other, CatalogueItemStatus::Pending, CatalogueItemOrigin::Manual, 'Hidden pending apple');
        $rejected = $this->catalogueItem($creator, CatalogueItemStatus::Rejected, CatalogueItemOrigin::Manual, 'Rejected apple');

        $first = app(CatalogueReadQuery::class)->paginateSelectable($creator, 'apple', 1);
        $second = app(CatalogueReadQuery::class)->paginateSelectable($creator, 'apple', 2);

        $this->assertSame(10, $first->total());
        $this->assertCount(8, $first->items());
        $this->assertCount(2, $second->items());
        $ids = collect([...$first->items(), ...$second->items()])->pluck('itemId');
        $this->assertTrue($ids->contains($ownPending->id));
        $this->assertFalse($ids->contains($hiddenPending->id));
        $this->assertFalse($ids->contains($rejected->id));
        $this->assertSame(
            ['item_id', 'version_id', 'name', 'barcode', 'pending'],
            array_keys($first->items()[0]->toArray()),
        );
        $this->assertSame(
            $ids->all(),
            collect([
                ...app(CatalogueReadQuery::class)->paginateSelectable($creator, 'apple', 1)->items(),
                ...app(CatalogueReadQuery::class)->paginateSelectable($creator, 'apple', 2)->items(),
            ])->pluck('itemId')->all(),
        );
    }

    public function test_attach_replace_and_clear_preserve_every_creator_entered_line_field(): void
    {
        $creator = User::factory()->create();
        $recipe = Recipe::factory()->for($creator, 'owner')->create();
        $line = RecipeIngredientLine::factory()->for($recipe)->create([
            'original_text' => '  1 heaped tbsp dark brown sugar  ',
            'position' => 0,
            'quantity' => '1.250000000000000000',
            'standard_unit' => null,
            'custom_unit' => 'heaped tbsp',
            'generic_wording' => 'dark brown sugar',
            'notes' => 'packed firmly',
        ]);
        $first = $this->catalogueItem($creator, CatalogueItemStatus::Approved, CatalogueItemOrigin::Manual, 'Brown sugar');
        $second = $this->catalogueItem($creator, CatalogueItemStatus::Approved, CatalogueItemOrigin::Manual, 'Dark muscovado sugar');
        $before = $this->lineContent($line);
        $manager = app(RecipeIngredientMatchManager::class);

        $match = $manager->select($recipe->id, $line->id, $first->id, $first->current_catalogue_item_version_id, $creator);

        $this->assertSame($before, $this->lineContent($line->fresh()));
        $this->assertSame($first->current_catalogue_item_version_id, $match->catalogue_item_version_id);
        $this->assertSame($first->id, $match->catalogueItemVersion->catalogue_item_id);
        $this->assertSame($creator->id, $match->selected_by_user_id);
        $this->assertSame(RecipeIngredientMatchProvenance::ManuallySelectedByCreator, $match->provenance);
        $this->assertSame(RecipeIngredientMatchReviewState::Confirmed, $match->review_state);

        $replaced = $manager->select($recipe->id, $line->id, $second->id, $second->current_catalogue_item_version_id, $creator);

        $this->assertSame($match->id, $replaced->id);
        $this->assertSame($second->current_catalogue_item_version_id, $replaced->catalogue_item_version_id);
        $this->assertSame($before, $this->lineContent($line->fresh()));
        $this->assertDatabaseCount('recipe_ingredient_line_matches', 1);

        $manager->clear($recipe->id, $line->id, $creator);

        $this->assertDatabaseMissing('recipe_ingredient_line_matches', ['recipe_ingredient_line_id' => $line->id]);
        $this->assertDatabaseHas('recipe_ingredient_lines', ['id' => $line->id]);
        $this->assertSame($before, $this->lineContent($line->fresh()));
    }

    public function test_own_pending_is_selectable_but_other_pending_rejected_and_guessed_ids_are_denied(): void
    {
        $creator = User::factory()->create();
        $other = User::factory()->create();
        $recipe = Recipe::factory()->for($creator, 'owner')->withIngredientLine()->create();
        $line = $recipe->ingredientLines()->sole();
        $own = $this->catalogueItem($creator, CatalogueItemStatus::Pending, CatalogueItemOrigin::Manual, 'Own lentils');
        $hidden = $this->catalogueItem($other, CatalogueItemStatus::Pending, CatalogueItemOrigin::Manual, 'Hidden lentils');
        $manager = app(RecipeIngredientMatchManager::class);

        $manager->select($recipe->id, $line->id, $own->id, $own->current_catalogue_item_version_id, $creator);
        $this->assertSame($own->current_catalogue_item_version_id, $line->catalogueMatch()->sole()->catalogue_item_version_id);

        try {
            $manager->select($recipe->id, $line->id, $hidden->id, $hidden->current_catalogue_item_version_id, $creator);
            $this->fail('Another submitter pending item must be denied.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('catalogue_match', $exception->errors());
        }

        $this->assertSame($own->current_catalogue_item_version_id, $line->catalogueMatch()->sole()->catalogue_item_version_id);
    }

    public function test_rejected_item_and_cross_item_version_are_rejected_at_the_mutation_boundary(): void
    {
        $creator = User::factory()->create();
        $recipe = Recipe::factory()->for($creator, 'owner')->withIngredientLine()->create();
        $line = $recipe->ingredientLines()->sole();
        $approved = $this->catalogueItem($creator, CatalogueItemStatus::Approved, CatalogueItemOrigin::Manual, 'Approved rice');
        $other = $this->catalogueItem($creator, CatalogueItemStatus::Approved, CatalogueItemOrigin::Manual, 'Other rice');
        $rejected = $this->catalogueItem($creator, CatalogueItemStatus::Rejected, CatalogueItemOrigin::Manual, 'Rejected rice');
        $manager = app(RecipeIngredientMatchManager::class);

        foreach ([
            [$rejected->id, $rejected->current_catalogue_item_version_id],
            [$approved->id, $other->current_catalogue_item_version_id],
        ] as [$itemId, $versionId]) {
            try {
                $manager->select($recipe->id, $line->id, $itemId, $versionId, $creator);
                $this->fail('An ineligible item/version pair must be denied.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('catalogue_match', $exception->errors());
            }
        }

        $this->assertDatabaseMissing('recipe_ingredient_line_matches', ['recipe_ingredient_line_id' => $line->id]);
    }

    public function test_non_owner_cannot_attach_replace_or_clear_at_the_mutation_boundary(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withIngredientLine()->create();
        $line = $recipe->ingredientLines()->sole();
        $item = $this->catalogueItem($owner, CatalogueItemStatus::Approved, CatalogueItemOrigin::Manual, 'Oats');
        $manager = app(RecipeIngredientMatchManager::class);

        foreach (['select', 'clear'] as $action) {
            try {
                $action === 'select'
                    ? $manager->select($recipe->id, $line->id, $item->id, $item->current_catalogue_item_version_id, $other)
                    : $manager->clear($recipe->id, $line->id, $other);
                $this->fail("Non-owner {$action} must be denied.");
            } catch (AuthorizationException) {
                // Expected: authorization is enforced again inside the transactional boundary.
            }
        }

        $this->assertDatabaseMissing('recipe_ingredient_line_matches', ['recipe_ingredient_line_id' => $line->id]);
    }

    public function test_guest_cannot_open_the_editor_or_invoke_match_actions(): void
    {
        $recipe = Recipe::factory()->withIngredientLine()->create();

        Livewire::test(Form::class, ['recipe' => $recipe])
            ->assertForbidden();
    }

    public function test_stale_result_and_version_race_are_revalidated_without_silent_version_switching(): void
    {
        $creator = User::factory()->create();
        $recipe = Recipe::factory()->for($creator, 'owner')->withIngredientLine()->create();
        $line = $recipe->ingredientLines()->sole();
        $item = $this->catalogueItem($creator, CatalogueItemStatus::Approved, CatalogueItemOrigin::Manual, 'Rice');
        $shownVersion = $item->currentVersion;
        $newVersion = CatalogueItemVersion::factory()->for($item, 'catalogueItem')->create([
            'version_number' => 2,
            'name' => 'Long grain rice',
        ]);
        $item->setCurrentVersion($newVersion);

        $this->expectException(ValidationException::class);
        try {
            app(RecipeIngredientMatchManager::class)->select(
                $recipe->id,
                $line->id,
                $item->id,
                $shownVersion->id,
                $creator,
            );
        } finally {
            $this->assertDatabaseMissing('recipe_ingredient_line_matches', ['recipe_ingredient_line_id' => $line->id]);
        }
    }

    public function test_existing_match_remains_pinned_when_catalogue_current_version_changes(): void
    {
        $creator = User::factory()->create();
        $recipe = Recipe::factory()->for($creator, 'owner')->withIngredientLine()->create();
        $line = $recipe->ingredientLines()->sole();
        $item = $this->catalogueItem($creator, CatalogueItemStatus::Approved, CatalogueItemOrigin::Manual, 'Milk');
        $selectedVersionId = $item->current_catalogue_item_version_id;

        app(RecipeIngredientMatchManager::class)->select($recipe->id, $line->id, $item->id, $selectedVersionId, $creator);
        $newVersion = CatalogueItemVersion::factory()->for($item, 'catalogueItem')->create([
            'version_number' => 2,
            'name' => 'Whole milk',
        ]);
        $item->setCurrentVersion($newVersion);

        $this->assertSame($selectedVersionId, $line->catalogueMatch()->sole()->catalogue_item_version_id);
    }

    public function test_livewire_search_and_failed_selection_keep_unsaved_editor_input(): void
    {
        $creator = User::factory()->create();
        $other = User::factory()->create();
        $recipe = Recipe::factory()->for($creator, 'owner')->validDraft()->create();
        $line = $recipe->ingredientLines()->sole();
        $approved = $this->catalogueItem($creator, CatalogueItemStatus::Approved, CatalogueItemOrigin::Manual, 'Olive oil');
        $hidden = $this->catalogueItem($other, CatalogueItemStatus::Pending, CatalogueItemOrigin::Manual, 'Private oil');
        $component = Livewire::actingAs($creator)->test(Form::class, ['recipe' => $recipe]);
        $ingredients = $component->get('ingredients');
        $ingredients[0]['original_text'] = '  locally edited olive oil  ';

        $component->set('title', 'Local title')
            ->set('ingredients', $ingredients)
            ->set("catalogueSearches.{$line->id}", 'oil')
            ->call('searchCatalogue', 0)
            ->assertSet('title', 'Local title')
            ->assertSet('ingredients', $ingredients)
            ->assertSet('unsaved', true);

        $results = $component->get('catalogueResults')[$line->id];
        $this->assertSame([$approved->id], array_column($results, 'item_id'));
        $this->assertSame(['item_id', 'version_id', 'name', 'barcode', 'pending'], array_keys($results[0]));

        $component->call('selectCatalogueMatch', 0, $hidden->id, $hidden->current_catalogue_item_version_id)
            ->assertHasErrors(['catalogue_match'])
            ->assertSet('title', 'Local title')
            ->assertSet('ingredients', $ingredients)
            ->assertSet('unsaved', true);
        $this->assertDatabaseMissing('recipe_ingredient_line_matches', ['recipe_ingredient_line_id' => $line->id]);
    }

    public function test_finalized_snapshots_preserve_matches_across_revisions_without_public_internal_metadata(): void
    {
        $creator = User::factory()->create();
        $recipe = Recipe::factory()->for($creator, 'owner')->validDraft()->create();
        $line = $recipe->ingredientLines()->sole();
        $first = $this->catalogueItem($creator, CatalogueItemStatus::Approved, CatalogueItemOrigin::Manual, 'Tomato');
        $second = $this->catalogueItem($creator, CatalogueItemStatus::Approved, CatalogueItemOrigin::Manual, 'Plum tomato');
        app(RecipeIngredientMatchManager::class)->select($recipe->id, $line->id, $first->id, $first->current_catalogue_item_version_id, $creator);

        Livewire::actingAs($creator)->test(Form::class, ['recipe' => $recipe])->call('finalize')->assertHasNoErrors();
        $recipe->refresh();
        $oldVersion = $recipe->currentVersion;
        $this->assertSame(
            $first->current_catalogue_item_version_id,
            $oldVersion->snapshot['ingredients'][0]['catalogue_match']['catalogue_item_version_id'],
        );

        $publicJson = json_encode(PublicRecipe::fromCurrentVersion($recipe)->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('manually_selected_by_creator', $publicJson);
        $this->assertStringNotContainsString('review_state', $publicJson);
        $this->assertStringNotContainsString($first->current_catalogue_item_version_id, $publicJson);

        $this->actingAs($creator)->get(route('recipes.edit', $recipe))->assertOk();
        $draftLine = $recipe->fresh()->ingredientLines()->sole();
        $this->assertSame($first->current_catalogue_item_version_id, $draftLine->catalogueMatch()->sole()->catalogue_item_version_id);
        app(RecipeIngredientMatchManager::class)->select($recipe->id, $draftLine->id, $second->id, $second->current_catalogue_item_version_id, $creator);
        Livewire::actingAs($creator)->test(Form::class, ['recipe' => $recipe->fresh()])->call('finalize')->assertHasNoErrors();

        $recipe->refresh();
        $this->assertSame($second->current_catalogue_item_version_id, $recipe->currentVersion->snapshot['ingredients'][0]['catalogue_match']['catalogue_item_version_id']);
        $this->assertSame($first->current_catalogue_item_version_id, $oldVersion->fresh()->snapshot['ingredients'][0]['catalogue_match']['catalogue_item_version_id']);
    }

    public function test_editing_text_and_reordering_do_not_silently_change_the_explicit_match(): void
    {
        $creator = User::factory()->create();
        $recipe = Recipe::factory()->for($creator, 'owner')->withIngredientLines(2)->create();
        $line = $recipe->ingredientLines()->first();
        $item = $this->catalogueItem($creator, CatalogueItemStatus::Approved, CatalogueItemOrigin::Manual, 'Onion');
        app(RecipeIngredientMatchManager::class)->select($recipe->id, $line->id, $item->id, $item->current_catalogue_item_version_id, $creator);
        $component = Livewire::actingAs($creator)->test(Form::class, ['recipe' => $recipe]);
        $ingredients = array_reverse($component->get('ingredients'));
        $matchedIndex = array_search($line->id, array_column($ingredients, 'id'), true);
        $ingredients[$matchedIndex]['original_text'] = '2 red onions';

        $component->set('ingredients', $ingredients)->call('save')->assertHasNoErrors();

        $line->refresh();
        $this->assertSame('2 red onions', $line->original_text);
        $this->assertSame(1, $line->position);
        $this->assertSame($item->current_catalogue_item_version_id, $line->catalogueMatch()->sole()->catalogue_item_version_id);
        $this->assertSame(RecipeIngredientMatchReviewState::Confirmed, $line->catalogueMatch()->sole()->review_state);
    }

    /** @return array<string, mixed> */
    private function lineContent(RecipeIngredientLine $line): array
    {
        return $line->only([
            'original_text',
            'position',
            'quantity',
            'standard_unit',
            'custom_unit',
            'generic_wording',
            'notes',
        ]);
    }

    private function catalogueItem(
        User $submitter,
        CatalogueItemStatus $status,
        CatalogueItemOrigin $origin,
        string $name,
    ): CatalogueItem {
        $item = CatalogueItem::factory()->submittedBy($submitter)->create([
            'status' => $status,
            'origin' => $origin,
        ]);
        $version = CatalogueItemVersion::factory()->for($item, 'catalogueItem')->create([
            'version_number' => 1,
            'name' => $name,
        ]);
        $item->setCurrentVersion($version);

        return $item->fresh('currentVersion');
    }
}
