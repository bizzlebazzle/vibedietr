<?php

namespace Tests\Feature\Recipes;

use App\Domain\Catalogue\CatalogueItemOrigin;
use App\Domain\Catalogue\CatalogueItemStatus;
use App\Domain\Recipes\RecipeIngredientMatchConfidenceBand;
use App\Domain\Recipes\RecipeIngredientMatchManager;
use App\Domain\Recipes\RecipeIngredientMatchProvenance;
use App\Domain\Recipes\RecipeIngredientMatchReviewState;
use App\Domain\Recipes\RecipeIngredientMatchThresholdPolicy;
use App\Livewire\Recipes\Form;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeIngredientAutomaticMatchEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_threshold_boundaries_leave_below_minimum_unmatched_and_persist_low_and_high_evidence(): void
    {
        $creator = User::factory()->create();
        $recipe = Recipe::factory()->for($creator, 'owner')->withIngredientLines(3)->create();
        [$belowLine, $lowLine, $highLine] = $recipe->ingredientLines()->get()->all();
        $item = $this->catalogueItem($creator, 'Brown rice');
        $manager = app(RecipeIngredientMatchManager::class);

        $this->assertNull($manager->selectAutomatically(
            $recipe->id,
            $belowLine->id,
            $item->id,
            $item->current_catalogue_item_version_id,
            '0.949999999999999999',
            $creator,
        ));
        $this->assertFalse($belowLine->catalogueMatch()->exists());

        $low = $manager->selectAutomatically(
            $recipe->id,
            $lowLine->id,
            $item->id,
            $item->current_catalogue_item_version_id,
            '0.9500',
            $creator,
        );
        $high = $manager->selectAutomatically(
            $recipe->id,
            $highLine->id,
            $item->id,
            $item->current_catalogue_item_version_id,
            '0.9900',
            $creator,
        );

        $this->assertNotNull($low);
        $this->assertSame('0.950000000000000000', $low->candidate_score);
        $this->assertSame(RecipeIngredientMatchConfidenceBand::Reviewable, $low->confidence_band);
        $this->assertSame(RecipeIngredientMatchReviewState::NeedsReview, $low->review_state);
        $this->assertSame(RecipeIngredientMatchThresholdPolicy::VERSION, $low->threshold_version);
        $this->assertSame(RecipeIngredientMatchProvenance::AutomaticallySelected, $low->provenance);
        $this->assertNull($low->selected_by_user_id);

        $this->assertNotNull($high);
        $this->assertSame('0.990000000000000000', $high->candidate_score);
        $this->assertSame(RecipeIngredientMatchConfidenceBand::High, $high->confidence_band);
        $this->assertSame(RecipeIngredientMatchReviewState::Confirmed, $high->review_state);
    }

    public function test_schema_rejects_sub_threshold_or_incoherent_automatic_evidence(): void
    {
        $creator = User::factory()->create();
        $recipe = Recipe::factory()->for($creator, 'owner')->withIngredientLine()->create();
        $line = $recipe->ingredientLines()->sole();
        $item = $this->catalogueItem($creator, 'Oats');
        $match = app(RecipeIngredientMatchManager::class)->selectAutomatically(
            $recipe->id,
            $line->id,
            $item->id,
            $item->current_catalogue_item_version_id,
            '0.995',
            $creator,
        );

        $this->assertNotNull($match);
        $this->assertTrue(Schema::hasColumns('recipe_ingredient_line_matches', [
            'candidate_score',
            'confidence_band',
            'threshold_version',
        ]));

        foreach ([
            ['candidate_score' => null],
            ['candidate_score' => '0.949999999999999999'],
            ['confidence_band' => null],
            ['confidence_band' => 'reviewable'],
            ['threshold_version' => 0],
            ['review_state' => 'needs_review'],
        ] as $invalid) {
            try {
                DB::table('recipe_ingredient_line_matches')->where('id', $match->id)->update($invalid);
                $this->fail('The database must reject incoherent automatic match evidence.');
            } catch (QueryException) {
                // The database constraint is the final persistence boundary.
            }
        }
    }

    public function test_manual_selection_replaces_automatic_evidence_and_match_can_be_cleared(): void
    {
        $creator = User::factory()->create();
        $recipe = Recipe::factory()->for($creator, 'owner')->withIngredientLine()->create();
        $line = $recipe->ingredientLines()->sole();
        $automaticItem = $this->catalogueItem($creator, 'Automatic choice');
        $manualItem = $this->catalogueItem($creator, 'Creator choice');
        $manager = app(RecipeIngredientMatchManager::class);
        $automatic = $manager->selectAutomatically(
            $recipe->id,
            $line->id,
            $automaticItem->id,
            $automaticItem->current_catalogue_item_version_id,
            '0.975',
            $creator,
        );

        $this->assertNotNull($automatic);
        $manual = $manager->select(
            $recipe->id,
            $line->id,
            $manualItem->id,
            $manualItem->current_catalogue_item_version_id,
            $creator,
        );

        $this->assertSame($automatic->id, $manual->id);
        $this->assertSame(RecipeIngredientMatchProvenance::ManuallySelectedByCreator, $manual->provenance);
        $this->assertSame($creator->id, $manual->selected_by_user_id);
        $this->assertNull($manual->candidate_score);
        $this->assertNull($manual->confidence_band);
        $this->assertNull($manual->threshold_version);
        $this->assertSame(RecipeIngredientMatchReviewState::Confirmed, $manual->review_state);

        $manager->clear($recipe->id, $line->id, $creator);

        $this->assertFalse($line->catalogueMatch()->exists());
        $this->assertDatabaseHas('recipe_ingredient_lines', ['id' => $line->id]);
    }

    public function test_automatic_match_keeps_catalogue_version_and_evidence_across_catalogue_and_recipe_versions(): void
    {
        $creator = User::factory()->create();
        $recipe = Recipe::factory()->for($creator, 'owner')->validDraft()->create();
        $line = $recipe->ingredientLines()->sole();
        $item = $this->catalogueItem($creator, 'Milk');
        $selectedVersionId = $item->current_catalogue_item_version_id;
        app(RecipeIngredientMatchManager::class)->selectAutomatically(
            $recipe->id,
            $line->id,
            $item->id,
            $selectedVersionId,
            '0.975123456789012345',
            $creator,
        );
        $newVersion = CatalogueItemVersion::factory()->for($item, 'catalogueItem')->create([
            'version_number' => 2,
            'name' => 'Whole milk',
        ]);
        $item->setCurrentVersion($newVersion);

        $this->assertSame($selectedVersionId, $line->catalogueMatch()->sole()->catalogue_item_version_id);

        Livewire::actingAs($creator)->test(Form::class, ['recipe' => $recipe])
            ->call('finalize')
            ->assertHasNoErrors();
        $snapshot = $recipe->fresh()->currentVersion->snapshot['ingredients'][0]['catalogue_match'];

        $this->assertSame($selectedVersionId, $snapshot['catalogue_item_version_id']);
        $this->assertSame('0.975123456789012345', $snapshot['candidate_score']);
        $this->assertSame('reviewable', $snapshot['confidence_band']);
        $this->assertSame(RecipeIngredientMatchThresholdPolicy::VERSION, $snapshot['threshold_version']);
        $this->assertSame('automatically_selected', $snapshot['provenance']);

        $this->actingAs($creator)->get(route('recipes.edit', $recipe->fresh()))->assertOk();
        $restored = $recipe->fresh()->ingredientLines()->sole()->catalogueMatch()->sole();
        $this->assertSame($selectedVersionId, $restored->catalogue_item_version_id);
        $this->assertSame('0.975123456789012345', $restored->candidate_score);
        $this->assertSame(RecipeIngredientMatchProvenance::AutomaticallySelected, $restored->provenance);
        $this->assertNull($restored->selected_by_user_id);
    }

    public function test_automatic_selection_reauthorizes_owner_and_requires_an_approved_candidate(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withIngredientLine()->create();
        $line = $recipe->ingredientLines()->sole();
        $approved = $this->catalogueItem($owner, 'Lentils');
        $pending = $this->catalogueItem($owner, 'Pending lentils', CatalogueItemStatus::Pending);
        $manager = app(RecipeIngredientMatchManager::class);

        try {
            $manager->selectAutomatically(
                $recipe->id,
                $line->id,
                $approved->id,
                $approved->current_catalogue_item_version_id,
                '0.995',
                $other,
            );
            $this->fail('A non-owner must not select an automatic match.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $this->expectException(ValidationException::class);
        $manager->selectAutomatically(
            $recipe->id,
            $line->id,
            $pending->id,
            $pending->current_catalogue_item_version_id,
            '0.995',
            $owner,
        );
    }

    private function catalogueItem(
        User $submitter,
        string $name,
        CatalogueItemStatus $status = CatalogueItemStatus::Approved,
    ): CatalogueItem {
        $item = CatalogueItem::factory()->submittedBy($submitter)->create([
            'status' => $status,
            'origin' => CatalogueItemOrigin::Manual,
        ]);
        $version = CatalogueItemVersion::factory()->for($item, 'catalogueItem')->create([
            'version_number' => 1,
            'name' => $name,
        ]);
        $item->setCurrentVersion($version);

        return $item->fresh('currentVersion');
    }
}
