<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\CatalogueItemOrigin;
use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Catalogue\CatalogueItemStatus;
use App\Domain\Catalogue\CatalogueName;
use App\Domain\Catalogue\ManualCatalogueDuplicateDetector;
use App\Domain\Catalogue\ManualCatalogueSubmissionData;
use App\Domain\Catalogue\ManualFoodClassification;
use App\Domain\Catalogue\PackageStructure;
use App\Domain\Nutrition\NutrientDerivation;
use App\Domain\Nutrition\NutrientProvenance;
use App\Domain\Recipes\RecipeIngredientMatchManager;
use App\Domain\Recipes\RecipeIngredientMatchProvenance;
use App\Models\CatalogueDuplicateCandidate;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemAlias;
use App\Models\CatalogueItemVersion;
use App\Models\Recipe;
use App\Models\RecipeIngredientLineMatch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManualCatalogueSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['catalogue.read_cutover' => true]);
    }

    public function test_authenticated_user_creates_one_atomic_pending_manual_identity_and_version(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->post(route('catalogue.manual.store'), $this->payload([
            'name' => 'Rare sea vegetable',
            'nutrition' => ['protein' => '2.50'],
        ]));

        $item = CatalogueItem::query()->sole();
        $response->assertRedirect(route('catalogue.show', $item));
        $this->assertSame(CatalogueItemOrigin::Manual, $item->origin);
        $this->assertSame(CatalogueItemSource::Manual, $item->source);
        $this->assertSame(CatalogueItemStatus::Pending, $item->status);
        $this->assertSame($user->id, $item->submitted_by_user_id);
        $this->assertNull($item->barcode);
        $this->assertNull($item->source_identifier);
        $this->assertSame('UTC', $item->introduced_at->timezoneName);
        $this->assertCount(1, $item->versions);
        $this->assertSame($item->versions->sole()->id, $item->current_catalogue_item_version_id);
        $this->assertSame('rare sea vegetable', $item->currentVersion->normalized_name);
        $this->assertSame(NutrientProvenance::ManuallySubmitted, $item->currentVersion->nutrientValues()->sole()->provenance);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'catalogue.manual_submission_created',
            'subject_identifier' => (string) $item->id,
        ]);
    }

    #[DataProvider('prohibitedCatalogueFields')]
    public function test_barcode_provider_and_server_authority_fields_are_rejected_atomically(string $field, mixed $value): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('catalogue.manual.store'), $this->payload([$field => $value]))
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('catalogue_items', 0);
        $this->assertDatabaseCount('catalogue_item_versions', 0);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function prohibitedCatalogueFields(): iterable
    {
        yield 'valid barcode' => ['barcode', '0012345678905'];
        yield 'malformed barcode text' => ['barcode', 'not-a-barcode'];
        yield 'provider code' => ['provider_code', '0012345678905'];
        yield 'source barcode' => ['source_barcode', '0012345678905'];
        yield 'provider source' => ['source', 'openfoodfacts'];
        yield 'provider identifier' => ['source_identifier', '0012345678905'];
        yield 'origin' => ['origin', 'barcode'];
        yield 'approval state' => ['status', 'approved'];
        yield 'submitter reassignment' => ['submitted_by_user_id', 999];
        yield 'provenance' => ['provenance', 'imported'];
    }

    public function test_invalid_package_pair_creates_no_partial_identity_or_version(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('catalogue.manual.store'), $this->payload(['amount_per_item' => '400']))
            ->assertSessionHasErrors('amount_per_item_unit');

        $this->assertDatabaseCount('catalogue_items', 0);
        $this->assertDatabaseCount('catalogue_item_versions', 0);
    }

    public function test_package_and_incomplete_nutrition_use_central_normalizers(): void
    {
        $this->actingAs(User::factory()->create())->post(route('catalogue.manual.store'), $this->payload([
            'package_count' => 4,
            'item_type' => 'can',
            'amount_per_item' => '400',
            'amount_per_item_unit' => 'gram',
            'servings_per_item' => '2',
            'nutrition' => ['energy_kcal' => '100', 'protein' => '7.250', 'sugars' => '0'],
        ]))->assertRedirect();

        $version = CatalogueItemVersion::query()->sole();
        $this->assertSame(4, $version->package_count);
        $this->assertSame('can', $version->item_type);
        $this->assertSame('400.000000000000000000', $version->amount_per_item);
        $this->assertSame('200.000000000000000000', $version->serving_amount);
        $this->assertSame(CatalogueItemSource::Manual, $version->package_source);
        $this->assertSame(CatalogueItemSource::Manual, $version->serving_source);
        $facts = $version->nutrientValues->keyBy(fn ($fact) => $fact->nutrient->value);
        $this->assertSame('0.000000000000000000', $facts['sugars']->value);
        $this->assertSame(3, $version->nutrientObservations()->count());
        $this->assertArrayNotHasKey('fat', $facts->all());
        $this->assertSame(NutrientProvenance::Derived, $facts['energy_kj']->provenance);
        $this->assertSame(NutrientDerivation::EnergyKjFromKcal, $facts['energy_kj']->derivation);
    }

    public function test_every_supported_manual_nutrient_is_accepted_with_source_precision(): void
    {
        $this->actingAs(User::factory()->create())->post(route('catalogue.manual.store'), $this->payload([
            'nutrition' => [
                'energy_kcal' => '100',
                'energy_kj' => '418.4',
                'fat' => '8.25',
                'saturated_fat' => '2.10',
                'carbohydrates' => '12.5',
                'sugars' => '3.75',
                'fibre' => '1.250',
                'protein' => '7.25',
                'salt' => '0.35',
                'sodium' => '140',
            ],
        ]))->assertRedirect();

        $version = CatalogueItemVersion::query()->sole();
        $this->assertCount(10, $version->nutrientObservations);
        $this->assertCount(10, $version->nutrientValues);
        $this->assertSame(
            3,
            $version->nutrientObservations()->where('nutrient', 'fibre')->sole()->source_scale,
        );
        $this->assertTrue($version->nutrientObservations->every(
            fn ($observation): bool => $observation->provenance === NutrientProvenance::ManuallySubmitted,
        ));
    }

    public function test_submitter_admin_other_user_and_guest_receive_the_approved_privacy_matrix(): void
    {
        $submitter = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->administrator()->create();
        $this->actingAs($submitter)->post(route('catalogue.manual.store'), $this->payload([
            'name' => 'My private pending kelp',
        ]))->assertRedirect();
        $item = CatalogueItem::query()->sole();

        $this->actingAs($submitter)->get(route('catalogue.show', $item))->assertOk();
        $this->actingAs($admin)->get(route('catalogue.show', $item))->assertOk();
        $this->actingAs($other)->get(route('catalogue.show', $item))->assertNotFound();
        $this->get(route('catalogue.show', $item))->assertNotFound();
        $this->actingAs($other)->get(route('catalogue.index', ['q' => 'private pending kelp']))
            ->assertOk()->assertDontSee('My private pending kelp');
        $this->get(route('catalogue.index', ['q' => 'private pending kelp']))
            ->assertOk()->assertDontSee('My private pending kelp');
    }

    public function test_submitter_deletion_nulls_provenance_without_publishing_pending_item(): void
    {
        $submitter = User::factory()->create();
        $this->actingAs($submitter)->post(route('catalogue.manual.store'), $this->payload([
            'name' => 'Orphaned pending item',
        ]));
        $item = CatalogueItem::query()->sole();
        $submitter->delete();

        $this->assertNull($item->fresh()->submitted_by_user_id);
        $this->get(route('catalogue.show', $item))->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('catalogue.show', $item))->assertNotFound();
        $this->actingAs(User::factory()->administrator()->create())->get(route('catalogue.show', $item))->assertOk();
    }

    public function test_exact_strong_match_requires_explicit_reuse_or_bounded_distinction(): void
    {
        $user = User::factory()->create();
        $approved = $this->approvedItem('Woodland mushroom', ManualFoodClassification::Generic);

        $this->actingAs($user)->post(route('catalogue.manual.store'), $this->payload([
            'name' => '  WOODLAND   MUSHROOM ',
        ]))->assertStatus(422)->assertSee('Use existing food')->assertSee('Submit as distinct food');
        $this->assertDatabaseCount('catalogue_items', 1);

        $this->actingAs($user)->post(route('catalogue.manual.store'), $this->payload([
            'name' => 'Woodland mushroom',
            'duplicate_choice' => 'reuse',
            'duplicate_item_id' => $approved->id,
        ]))->assertRedirect(route('catalogue.show', $approved));
        $this->assertDatabaseCount('catalogue_items', 1);

        $this->actingAs($user)->post(route('catalogue.manual.store'), $this->payload([
            'name' => 'Woodland mushroom',
            'duplicate_choice' => 'continue_distinct',
            'duplicate_item_id' => $approved->id,
            'distinction_explanation' => '   ',
        ]))->assertSessionHasErrors('distinction_explanation');
        $this->assertDatabaseCount('catalogue_items', 1);

        $this->actingAs($user)->post(route('catalogue.manual.store'), $this->payload([
            'name' => 'Woodland mushroom',
            'duplicate_choice' => 'continue_distinct',
            'duplicate_item_id' => $approved->id,
            'distinction_explanation' => 'Cultivated strain with a materially different preparation.',
        ]))->assertRedirect();

        $pending = CatalogueItem::query()->where('status', CatalogueItemStatus::Pending)->sole();
        $candidate = CatalogueDuplicateCandidate::query()->sole();
        $this->assertEqualsCanonicalizing(
            [$approved->id, $pending->id],
            [$candidate->first_catalogue_item_id, $candidate->second_catalogue_item_id],
        );
        $this->assertSame('Cultivated strain with a materially different preparation.', $candidate->distinction_explanation);
        $this->assertSame($user->id, $candidate->submitted_by_user_id);
        $this->assertSame('exact_primary_name', $candidate->getRawOriginal('evidence'));
        $this->actingAs($user)->get(route('catalogue.show', $pending))
            ->assertOk()
            ->assertDontSee('Cultivated strain with a materially different preparation.');
    }

    public function test_approved_alias_with_compatible_identity_is_strong_but_alias_details_are_bounded(): void
    {
        $approved = $this->approvedItem(
            'Garbanzo beans',
            ManualFoodClassification::Branded,
            brand: 'Example Foods',
        );
        CatalogueItemAlias::query()->forceCreate([
            'catalogue_item_id' => $approved->id,
            'alias' => 'Chickpeas',
            'normalized_alias' => 'ignored-by-model',
            'approved_at' => now(),
        ]);

        $user = User::factory()->create();
        $this->actingAs($user)->post(route('catalogue.manual.store'), $this->payload([
            'name' => 'chickpeas',
            'classification' => 'branded',
            'brand' => 'Example Foods',
        ]))->assertStatus(422)->assertSee('Approved alias and compatible identity details');
        $this->assertDatabaseCount('catalogue_items', 1);

        $this->actingAs($user)->post(route('catalogue.manual.store'), $this->payload([
            'name' => 'chickpeas',
            'classification' => 'branded',
            'brand' => 'Example Foods',
            'duplicate_choice' => 'continue_distinct',
            'duplicate_item_id' => $approved->id,
            'distinction_explanation' => 'Different branded formulation.',
        ]))->assertRedirect();
        $this->assertSame(
            'approved_alias',
            CatalogueDuplicateCandidate::query()->sole()->getRawOriginal('evidence'),
        );
    }

    public function test_name_or_alias_with_materially_conflicting_core_attributes_is_not_strong(): void
    {
        $this->approvedItem('Protein blend', ManualFoodClassification::Branded, brand: 'Brand A');

        $this->actingAs(User::factory()->create())->post(route('catalogue.manual.store'), $this->payload([
            'name' => 'Protein blend',
            'classification' => 'branded',
            'brand' => 'Brand B',
        ]))->assertRedirect();

        $this->assertDatabaseCount('catalogue_items', 2);
        $this->assertDatabaseCount('catalogue_duplicate_candidates', 0);
    }

    public function test_fuzzy_prefix_match_is_suggestion_only_and_does_not_create_candidate_or_reuse(): void
    {
        $approved = $this->approvedItem('Mushroom powder', ManualFoodClassification::Generic);
        $data = new ManualCatalogueSubmissionData(
            name: 'Mushroom flakes',
            classification: ManualFoodClassification::Generic,
            brand: null,
            manufacturer: null,
            foodForm: null,
            preparation: null,
            treatment: null,
            composition: null,
            package: PackageStructure::make(),
            nutrition: [],
        );
        $detector = app(ManualCatalogueDuplicateDetector::class);

        $this->assertSame([], $detector->strongMatches($data));
        $this->assertSame([$approved->id], collect($detector->fuzzySuggestions($data))->pluck('itemId')->all());

        $response = $this->actingAs(User::factory()->create())->post(route('catalogue.manual.store'), $this->payload([
            'name' => 'Mushroom flakes',
        ]))->assertRedirect();
        $this->assertDatabaseCount('catalogue_items', 2);
        $this->assertDatabaseCount('catalogue_duplicate_candidates', 0);
        $response->assertSessionHas('fuzzySuggestions', fn (array $suggestions): bool => (
            $suggestions[0]['item_id'] ?? null
        ) === $approved->id);
    }

    public function test_two_pending_submissions_with_same_name_are_not_collapsed_by_name(): void
    {
        foreach ([User::factory()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)->post(route('catalogue.manual.store'), $this->payload([
                'name' => 'Same legitimate name',
            ]))->assertRedirect();
        }

        $this->assertDatabaseCount('catalogue_items', 2);
        $this->assertSame(2, CatalogueItemVersion::query()->where('normalized_name', 'same legitimate name')->count());
    }

    public function test_rejected_tombstone_is_retained_private_and_never_substitutes_recipe_match(): void
    {
        $owner = User::factory()->create();
        $replacement = $this->approvedItem('Approved replacement', ManualFoodClassification::Generic);
        $this->actingAs($owner)->post(route('catalogue.manual.store'), $this->payload([
            'name' => 'Pending recipe food',
        ]));
        $pending = CatalogueItem::query()->where('status', CatalogueItemStatus::Pending)->sole();
        $recipe = Recipe::factory()->for($owner, 'owner')->withIngredientLine()->create();
        $line = $recipe->ingredientLines()->sole();
        app(RecipeIngredientMatchManager::class)->select(
            $recipe->id,
            $line->id,
            $pending->id,
            $pending->current_catalogue_item_version_id,
            $owner,
        );
        $original = $line->original_text;

        $pending->forceFill([
            'status' => CatalogueItemStatus::Rejected,
            'suggested_replacement_catalogue_item_id' => $replacement->id,
        ])->save();

        $this->assertDatabaseHas('recipe_ingredient_line_matches', [
            'recipe_ingredient_line_id' => $line->id,
            'catalogue_item_version_id' => $pending->current_catalogue_item_version_id,
        ]);
        $this->assertSame($original, $line->fresh()->original_text);
        $this->actingAs($owner)->get(route('catalogue.show', $pending))
            ->assertOk()->assertSee('no longer selectable')->assertSee('Approved replacement');
        $this->actingAs(User::factory()->create())->get(route('catalogue.show', $pending))->assertNotFound();
        $this->actingAs($owner)->get(route('catalogue.index', ['q' => 'Pending recipe food']))
            ->assertOk()->assertDontSeeText('Pending recipe food');
    }

    public function test_owner_confirmed_replacement_changes_only_editable_match_and_preserves_text(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $replacement = $this->approvedItem('Canonical food', ManualFoodClassification::Generic);
        $pending = CatalogueItem::factory()->submittedBy($owner)->rejected()->create([
            'suggested_replacement_catalogue_item_id' => $replacement->id,
        ]);
        $pendingVersion = CatalogueItemVersion::factory()->for($pending)->current()->create([
            'name' => 'Rejected food',
            'manual_food_classification' => ManualFoodClassification::Generic,
        ]);
        $recipe = Recipe::factory()->for($owner, 'owner')->withIngredientLine()->create();
        $line = $recipe->ingredientLines()->sole();
        $match = new RecipeIngredientLineMatch;
        $match->forceFill([
            'catalogue_item_version_id' => $pendingVersion->id,
            'selected_by_user_id' => $owner->id,
            'provenance' => 'manually_selected_by_creator',
            'review_state' => 'confirmed',
        ]);
        $match->ingredientLine()->associate($line);
        $match->save();
        $original = $line->original_text;

        $caught = null;
        try {
            app(RecipeIngredientMatchManager::class)->confirmRejectedReplacement($recipe->id, $line->id, $other);
            $this->fail('A non-owner replaced an editable recipe match.');
        } catch (AuthorizationException $exception) {
            $caught = $exception;
        }
        $this->assertInstanceOf(AuthorizationException::class, $caught);

        $replacement->forceFill(['status' => CatalogueItemStatus::Pending])->save();
        $caught = null;
        try {
            app(RecipeIngredientMatchManager::class)
                ->confirmRejectedReplacement($recipe->id, $line->id, $owner);
            $this->fail('A non-approved suggested replacement was accepted.');
        } catch (ValidationException $exception) {
            $caught = $exception;
        }
        $this->assertInstanceOf(ValidationException::class, $caught);
        $replacement->forceFill(['status' => CatalogueItemStatus::Approved])->save();

        $match = app(RecipeIngredientMatchManager::class)
            ->confirmRejectedReplacement($recipe->id, $line->id, $owner);
        $this->assertSame($replacement->current_catalogue_item_version_id, $match->catalogue_item_version_id);
        $this->assertSame(RecipeIngredientMatchProvenance::OwnerConfirmedReplacement, $match->provenance);
        $this->assertSame($original, $line->fresh()->original_text);
        $this->assertTrue($pending->versions()->whereKey($pendingVersion->id)->exists());
    }

    public function test_guest_cannot_open_or_submit_manual_catalogue_form(): void
    {
        $this->get(route('catalogue.manual.create'))->assertRedirect(route('login'));
        $this->post(route('catalogue.manual.store'), $this->payload())->assertRedirect(route('login'));
        $this->assertDatabaseCount('catalogue_items', 0);
    }

    private function approvedItem(
        string $name,
        ManualFoodClassification $classification,
        ?string $brand = null,
    ): CatalogueItem {
        $item = CatalogueItem::factory()->approved()->create();
        CatalogueItemVersion::factory()->for($item)->current()->create([
            'name' => $name,
            'normalized_name' => CatalogueName::normalize($name),
            'manual_food_classification' => $classification,
            'brand' => $brand,
        ]);

        return $item->fresh('currentVersion');
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Rare food',
            'classification' => 'generic',
            'nutrition_basis' => 'per_100g',
        ], $overrides);
    }
}
