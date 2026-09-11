<?php

namespace Tests\Feature\Recipes;

use App\Domain\Catalogue\CatalogueItemOrigin;
use App\Domain\Catalogue\CatalogueItemStatus;
use App\Domain\Recipes\RecipeIngredientCandidateRanker;
use App\Domain\Recipes\RecipeIngredientCandidateSelectionOutcome;
use App\Domain\Recipes\RecipeIngredientMatchCandidateScore;
use App\Domain\Recipes\RecipeIngredientMatchConfidenceBand;
use App\Domain\Recipes\RecipeIngredientMatchManager;
use App\Domain\Recipes\RecipeIngredientMatchProvenance;
use App\Domain\Recipes\RecipeIngredientMatchReviewState;
use App\Domain\Recipes\RecipeIngredientMatchThresholdPolicy;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeIngredientCandidateRankingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranked_selection_applies_every_decision_boundary_without_rounding(): void
    {
        $creator = User::factory()->create();
        $recipe = Recipe::factory()->for($creator, 'owner')->withIngredientLines(4)->create();
        $lines = $recipe->ingredientLines()->get();
        $item = $this->catalogueItem($creator, 'Coriander');
        $manager = app(RecipeIngredientMatchManager::class);
        $scores = [
            '0.949999999999999999',
            '0.950000000000000000',
            '0.989999999999999999',
            '0.990000000000000000',
        ];

        $matches = $lines->map(fn (RecipeIngredientLine $line, int $index) => $manager->selectHighestRankedAutomatically(
            $recipe->id,
            $line->id,
            [$this->candidate($item, $scores[$index])],
            $creator,
        ));

        $this->assertNull($matches[0]);
        $this->assertFalse($lines[0]->catalogueMatch()->exists());

        foreach ([1, 2] as $index) {
            $this->assertSame($scores[$index], $matches[$index]->candidate_score);
            $this->assertSame(RecipeIngredientMatchConfidenceBand::Reviewable, $matches[$index]->confidence_band);
            $this->assertSame(RecipeIngredientMatchReviewState::NeedsReview, $matches[$index]->review_state);
        }

        $this->assertSame($scores[3], $matches[3]->candidate_score);
        $this->assertSame(RecipeIngredientMatchConfidenceBand::High, $matches[3]->confidence_band);
        $this->assertSame(RecipeIngredientMatchReviewState::Confirmed, $matches[3]->review_state);
    }

    public function test_highest_scored_spelling_variant_is_selected_and_nut_12_evidence_is_preserved(): void
    {
        $creator = User::factory()->create();
        $recipe = Recipe::factory()->for($creator, 'owner')->create();
        $line = RecipeIngredientLine::factory()->for($recipe)->create([
            'original_text' => '  2 tsp corriander leaves  ',
            'quantity' => '2.000000000000000000',
            'custom_unit' => 'tsp',
            'generic_wording' => 'corriander leaves',
            'notes' => 'roughly chopped',
        ]);
        $lower = $this->catalogueItem($creator, 'Cilantro leaves');
        $spellingVariant = $this->catalogueItem($creator, 'Coriander leaves');
        $before = $line->only(['original_text', 'quantity', 'custom_unit', 'generic_wording', 'notes']);

        $match = app(RecipeIngredientMatchManager::class)->selectHighestRankedAutomatically(
            $recipe->id,
            $line->id,
            [
                $this->candidate($lower, '0.951'),
                $this->candidate($spellingVariant, '0.975123456789012345'),
            ],
            $creator,
        );

        $this->assertNotNull($match);
        $this->assertSame($spellingVariant->current_catalogue_item_version_id, $match->catalogue_item_version_id);
        $this->assertSame('0.975123456789012345', $match->candidate_score);
        $this->assertSame(RecipeIngredientMatchThresholdPolicy::VERSION, $match->threshold_version);
        $this->assertSame(RecipeIngredientMatchProvenance::AutomaticallySelected, $match->provenance);
        $this->assertNull($match->selected_by_user_id);
        $this->assertSame($before, $line->fresh()->only(array_keys($before)));
    }

    public function test_qualifying_top_score_tie_has_stable_presentation_order_and_no_winner(): void
    {
        $creator = User::factory()->create();
        $recipe = Recipe::factory()->for($creator, 'owner')->withIngredientLine()->create();
        $line = $recipe->ingredientLines()->sole();
        $first = $this->catalogueItem($creator, 'Brown sugar');
        $second = $this->catalogueItem($creator, 'Dark muscovado sugar');
        $ranker = app(RecipeIngredientCandidateRanker::class);
        $expectedIds = [$first->id, $second->id];

        foreach (range(1, 8) as $iteration) {
            $candidates = $iteration % 2 === 0
                ? [
                    $this->candidate($second, '0.9750'),
                    $this->candidate($first, '0.975000000000000000'),
                    $this->candidate($first, '0.9700'),
                ]
                : [
                    $this->candidate($first, '0.9700'),
                    $this->candidate($first, '0.975000000000000000'),
                    $this->candidate($second, '0.9750'),
                ];
            $ranking = $ranker->rank($creator, $candidates);

            $this->assertSame($expectedIds, array_map(
                fn ($candidate): int => $candidate->catalogueItemId,
                $ranking->candidates,
            ));
            $this->assertSame(RecipeIngredientCandidateSelectionOutcome::TopScoreTie, $ranking->selectionOutcome);
            $this->assertNull($ranking->selectedCandidate);
        }

        $this->assertNull(app(RecipeIngredientMatchManager::class)->selectHighestRankedAutomatically(
            $recipe->id,
            $line->id,
            [$this->candidate($second, '0.975'), $this->candidate($first, '0.975')],
            $creator,
        ));
        $this->assertFalse($line->catalogueMatch()->exists());
    }

    public function test_pending_inaccessible_and_stale_candidates_are_excluded_before_ranking(): void
    {
        $creator = User::factory()->create();
        $other = User::factory()->create();
        $ownPending = $this->catalogueItem($creator, 'Own pending oats', CatalogueItemStatus::Pending);
        $otherPending = $this->catalogueItem($other, 'Private pending oats', CatalogueItemStatus::Pending);
        $approved = $this->catalogueItem($creator, 'Rolled oats');
        $staleVersion = $approved->current_catalogue_item_version_id;
        $current = CatalogueItemVersion::factory()->for($approved, 'catalogueItem')->create([
            'version_number' => 2,
            'name' => 'Jumbo rolled oats',
        ]);
        $approved->setCurrentVersion($current);
        $eligible = $this->catalogueItem($creator, 'Porridge oats');

        $ranking = app(RecipeIngredientCandidateRanker::class)->rank($creator, [
            $this->candidate($ownPending, '1.0'),
            $this->candidate($otherPending, '1.0'),
            new RecipeIngredientMatchCandidateScore($approved->id, $staleVersion, '1.0'),
            $this->candidate($eligible, '0.9600'),
        ]);

        $this->assertSame([$eligible->id], array_map(
            fn ($candidate): int => $candidate->catalogueItemId,
            $ranking->candidates,
        ));
        $this->assertSame($eligible->id, $ranking->selectedCandidate?->catalogueItemId);
        $this->assertSame(RecipeIngredientCandidateSelectionOutcome::Selected, $ranking->selectionOutcome);
    }

    public function test_empty_and_sub_threshold_rankings_remain_unmatched_with_explainable_outcomes(): void
    {
        $creator = User::factory()->create();
        $item = $this->catalogueItem($creator, 'Pearled barley');
        $ranker = app(RecipeIngredientCandidateRanker::class);

        $empty = $ranker->rank($creator, []);
        $below = $ranker->rank($creator, [$this->candidate($item, '0.949999999999999999')]);

        $this->assertSame(RecipeIngredientCandidateSelectionOutcome::NoEligibleCandidates, $empty->selectionOutcome);
        $this->assertSame(RecipeIngredientCandidateSelectionOutcome::BelowThreshold, $below->selectionOutcome);
        $this->assertSame('0.949999999999999999', $below->candidates[0]->candidateScore);
        $this->assertNull($below->candidates[0]->selectionEvidence);
        $this->assertNull($below->selectedCandidate);
    }

    public function test_ranked_selection_reauthorizes_the_recipe_even_when_there_is_no_result(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->withIngredientLine()->create();
        $line = $recipe->ingredientLines()->sole();

        $this->expectException(AuthorizationException::class);
        app(RecipeIngredientMatchManager::class)->selectHighestRankedAutomatically(
            $recipe->id,
            $line->id,
            [],
            $other,
        );
    }

    private function candidate(CatalogueItem $item, string $score): RecipeIngredientMatchCandidateScore
    {
        return new RecipeIngredientMatchCandidateScore(
            $item->id,
            $item->current_catalogue_item_version_id,
            $score,
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
