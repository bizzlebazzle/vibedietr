<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\CatalogueItemOrigin;
use App\Domain\Catalogue\CatalogueItemStatus;
use App\Domain\Catalogue\CatalogueReadQuery;
use App\Domain\Catalogue\LegacyIngredientClassification;
use App\Domain\Catalogue\LegacyIngredientReviewReason;
use App\Livewire\Ingredients\Form;
use App\Models\CatalogueItem;
use App\Models\Ingredient;
use App\Models\LegacyIngredientCatalogueMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogueReadCutoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['catalogue.read_cutover' => true]);
    }

    #[DataProvider('visibilityMatrix')]
    public function test_direct_visibility_matrix(
        string $actor,
        CatalogueItemStatus $status,
        CatalogueItemOrigin $origin,
        bool $own,
        int $expectedStatus,
    ): void {
        $submitter = User::factory()->create();
        $item = $this->mappedItem($submitter, $status, $origin, 'Matrix item');
        $viewer = match ($actor) {
            'guest' => null,
            'submitter' => $submitter,
            'administrator' => User::factory()->administrator()->create(),
            default => User::factory()->create(),
        };

        if ($own && $viewer !== null && ! $viewer->is($submitter)) {
            $item->forceFill(['submitted_by_user_id' => $viewer->getKey()])->save();
        }

        $request = $viewer === null ? $this : $this->actingAs($viewer);

        $request->get(route('catalogue.show', $item))->assertStatus($expectedStatus);
    }

    /** @return array<string, array{string, CatalogueItemStatus, CatalogueItemOrigin, bool, int}> */
    public static function visibilityMatrix(): array
    {
        return [
            'guest approved manual' => ['guest', CatalogueItemStatus::Approved, CatalogueItemOrigin::Manual, false, 200],
            'guest approved imported' => ['guest', CatalogueItemStatus::Approved, CatalogueItemOrigin::Barcode, false, 200],
            'guest pending manual' => ['guest', CatalogueItemStatus::Pending, CatalogueItemOrigin::Manual, false, 404],
            'ordinary approved other' => ['user', CatalogueItemStatus::Approved, CatalogueItemOrigin::Manual, false, 200],
            'ordinary pending other' => ['user', CatalogueItemStatus::Pending, CatalogueItemOrigin::Manual, false, 404],
            'submitter own pending manual' => ['submitter', CatalogueItemStatus::Pending, CatalogueItemOrigin::Manual, true, 200],
            'submitter pending imported' => ['submitter', CatalogueItemStatus::Pending, CatalogueItemOrigin::Barcode, true, 404],
            'administrator pending manual' => ['administrator', CatalogueItemStatus::Pending, CatalogueItemOrigin::Manual, false, 200],
            'administrator pending imported' => ['administrator', CatalogueItemStatus::Pending, CatalogueItemOrigin::Barcode, false, 200],
        ];
    }

    public function test_browse_and_search_apply_visibility_before_counts_and_pagination(): void
    {
        $viewer = User::factory()->create();
        $other = User::factory()->create();

        foreach (range(1, 13) as $number) {
            $this->mappedItem($other, CatalogueItemStatus::Approved, CatalogueItemOrigin::Manual, "Visible apple {$number}");
        }

        $ownPending = $this->mappedItem($viewer, CatalogueItemStatus::Pending, CatalogueItemOrigin::Manual, 'My private apple');
        $this->mappedItem($other, CatalogueItemStatus::Pending, CatalogueItemOrigin::Manual, 'Hidden private apple');

        $catalogue = app(CatalogueReadQuery::class);
        $page = $catalogue->paginate($viewer, 'apple');
        $guestPage = $catalogue->paginate(null, 'apple');

        $this->assertSame(14, $page->total());
        $this->assertSame(13, $guestPage->total());
        $this->assertCount(12, $page->items());
        $this->assertSame(2, $catalogue->paginate($viewer, 'apple')->lastPage());
        $this->assertTrue(collect($page->items())->contains(fn ($item) => $item->id === $ownPending->id));

        $this->actingAs($viewer)
            ->get(route('catalogue.index', ['q' => 'private apple']))
            ->assertOk()
            ->assertSee('My private apple')
            ->assertDontSee('Hidden private apple');
        $this->get(route('catalogue.index', ['q' => 'Visible apple 13']))
            ->assertOk()
            ->assertSee('Visible apple 13');
    }

    public function test_barcode_search_returns_approved_import_without_private_pending_leakage(): void
    {
        $owner = User::factory()->create();
        $approved = $this->mappedItem(
            $owner,
            CatalogueItemStatus::Approved,
            CatalogueItemOrigin::Barcode,
            'Approved barcode product',
            '0012345678905',
        );
        $this->mappedItem(
            $owner,
            CatalogueItemStatus::Pending,
            CatalogueItemOrigin::Barcode,
            'Pending barcode product',
            '0099999999999',
        );

        $page = app(CatalogueReadQuery::class)->paginate(null, '0012345678905');
        $hidden = app(CatalogueReadQuery::class)->paginate(null, '0099999999999');

        $this->assertSame([$approved->id], collect($page->items())->pluck('id')->all());
        $this->assertSame(0, $hidden->total());
    }

    public function test_barcode_lookup_uses_visible_local_catalogue_before_provider(): void
    {
        $submitter = User::factory()->create();
        $viewer = User::factory()->create();
        $item = $this->mappedItem(
            $submitter,
            CatalogueItemStatus::Approved,
            CatalogueItemOrigin::Barcode,
            'Existing imported product',
            '0012345678905',
        );
        Http::fake();

        Livewire::actingAs($viewer)->test(Form::class)
            ->set('barcode', '0012345678905')
            ->call('fetchFromOff')
            ->assertRedirect(route('catalogue.show', $item));

        Http::assertNothingSent();
    }

    public function test_barcode_lookup_does_not_disclose_hidden_pending_import(): void
    {
        $submitter = User::factory()->create();
        $viewer = User::factory()->create();
        $this->mappedItem(
            $submitter,
            CatalogueItemStatus::Pending,
            CatalogueItemOrigin::Barcode,
            'Hidden imported candidate',
            '0099999999999',
        );
        Http::fake();

        Livewire::actingAs($viewer)->test(Form::class)
            ->set('barcode', '0099999999999')
            ->call('fetchFromOff')
            ->assertNoRedirect()
            ->assertSet('barcode', '0099999999999');

        Http::assertNothingSent();
    }

    public function test_public_projection_excludes_submitter_and_private_migration_fields(): void
    {
        $submitter = User::factory()->create(['email' => 'private-submitter@example.test']);
        $item = $this->mappedItem(
            $submitter,
            CatalogueItemStatus::Approved,
            CatalogueItemOrigin::Manual,
            'Public oats',
            snapshot: [
                'moderation_notes' => 'private moderation text',
                'internal_source_payload' => 'private provider data',
            ],
        );

        $response = $this->get(route('catalogue.show', $item))->assertOk()->assertSee('Public oats');
        $serialized = $response->getContent();

        $this->assertStringNotContainsString('private-submitter@example.test', $serialized);
        $this->assertStringNotContainsString('private moderation text', $serialized);
        $this->assertStringNotContainsString('private provider data', $serialized);
        $this->assertStringNotContainsString('submitted_by_user_id', $serialized);
    }

    public function test_pending_submitter_has_read_but_no_edit_delete_or_moderation_rights(): void
    {
        $submitter = User::factory()->create();
        $item = $this->mappedItem(
            $submitter,
            CatalogueItemStatus::Pending,
            CatalogueItemOrigin::Manual,
            'Pending lentils',
        );
        $legacy = $this->legacyIngredient($item);

        $this->actingAs($submitter)->get(route('catalogue.show', $item))->assertOk();
        $this->assertFalse(Gate::forUser($submitter)->allows('update', $item));
        $this->assertFalse(Gate::forUser($submitter)->allows('delete', $item));
        $this->assertFalse(Gate::forUser($submitter)->allows('moderate', $item));
        $this->actingAs($submitter)->put(route('ingredients.update', $legacy), $this->ingredientPayload())
            ->assertForbidden();
        $this->actingAs($submitter)->delete(route('ingredients.destroy', $legacy))
            ->assertForbidden();
        $this->assertDatabaseHas('ingredients', ['id' => $legacy->id]);
    }

    public function test_approved_manual_submitter_retains_no_owner_mutation_rights(): void
    {
        $submitter = User::factory()->create();
        $item = $this->mappedItem(
            $submitter,
            CatalogueItemStatus::Approved,
            CatalogueItemOrigin::Manual,
            'Approved lentils',
        );
        $legacy = $this->legacyIngredient($item);

        $this->actingAs($submitter)->put(route('ingredients.update', $legacy), $this->ingredientPayload())
            ->assertForbidden();
        $this->actingAs($submitter)->delete(route('ingredients.destroy', $legacy))
            ->assertForbidden();
    }

    public function test_imported_record_cannot_be_mutated_by_ordinary_user_even_without_mapping(): void
    {
        $owner = User::factory()->create();
        $ingredient = Ingredient::factory()->for($owner)->barcodeImported()->create();

        $this->actingAs($owner)
            ->get(route('ingredients.show', $ingredient))
            ->assertOk()
            ->assertDontSee('Edit')
            ->assertDontSee('Delete');
        $this->actingAs($owner)->put(route('ingredients.update', $ingredient), $this->ingredientPayload())
            ->assertForbidden();
        $this->actingAs($owner)->delete(route('ingredients.destroy', $ingredient))
            ->assertForbidden();
        $this->assertDatabaseHas('ingredients', ['id' => $ingredient->id]);
    }

    public function test_administrator_can_read_and_moderate_but_not_directly_update_or_delete(): void
    {
        $submitter = User::factory()->create();
        $administrator = User::factory()->administrator()->create();
        $item = $this->mappedItem(
            $submitter,
            CatalogueItemStatus::Pending,
            CatalogueItemOrigin::Manual,
            'Admin review item',
        );

        $this->actingAs($administrator)->get(route('catalogue.show', $item))->assertOk();
        $this->assertTrue(Gate::forUser($administrator)->allows('moderate', $item));
        $this->assertFalse(Gate::forUser($administrator)->allows('update', $item));
        $this->assertFalse(Gate::forUser($administrator)->allows('delete', $item));
    }

    public function test_submitter_deletion_never_changes_visibility_state(): void
    {
        $pendingSubmitter = User::factory()->create();
        $approvedSubmitter = User::factory()->create();
        $pending = $this->mappedItem(
            $pendingSubmitter,
            CatalogueItemStatus::Pending,
            CatalogueItemOrigin::Manual,
            'Orphan pending',
        );
        $approved = $this->mappedItem(
            $approvedSubmitter,
            CatalogueItemStatus::Approved,
            CatalogueItemOrigin::Manual,
            'Orphan approved',
        );
        $pendingSubmitter->delete();
        $approvedSubmitter->delete();

        $this->assertNull($pending->refresh()->submitted_by_user_id);
        $this->assertNull($approved->refresh()->submitted_by_user_id);
        $this->get(route('catalogue.show', $pending))->assertNotFound();
        $this->get(route('catalogue.show', $approved))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('catalogue.show', $pending))->assertNotFound();
        $this->actingAs(User::factory()->administrator()->create())->get(route('catalogue.show', $pending))->assertOk();
    }

    public function test_legacy_routes_resolve_only_explicit_mappings_and_missing_targets_stay_private(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        CatalogueItem::factory()->manual()->create(['id' => 1000000]);
        $approved = $this->mappedItem(
            $owner,
            CatalogueItemStatus::Approved,
            CatalogueItemOrigin::Manual,
            'Mapped route item',
        );
        $mappedLegacy = $this->legacyIngredient($approved);
        $unmapped = Ingredient::factory()->for($owner)->manual()->create(['name' => 'Unmapped private item']);
        $nullTarget = Ingredient::factory()->for($owner)->legacyBarcode()->create(['name' => 'Ambiguous private item']);
        LegacyIngredientCatalogueMapping::factory()->create([
            'ingredient_id' => $nullTarget->id,
            'legacy_user_id' => $owner->id,
            'catalogue_item_id' => null,
            'classification' => LegacyIngredientClassification::AmbiguousBarcode,
            'review_reason' => LegacyIngredientReviewReason::UnverifiedLegacyBarcode,
        ]);
        $mappingCount = LegacyIngredientCatalogueMapping::query()->count();

        $this->assertNotSame($mappedLegacy->id, $approved->id);
        $this->actingAs($other)
            ->get(route('ingredients.show', $mappedLegacy))
            ->assertRedirect(route('catalogue.show', $approved));
        $this->actingAs($owner)->get(route('ingredients.show', $unmapped))->assertOk();
        $this->actingAs($other)->get(route('ingredients.show', $unmapped))->assertForbidden();
        $this->actingAs($owner)->get(route('ingredients.show', $nullTarget))->assertOk();
        $this->actingAs($other)->get(route('ingredients.show', $nullTarget))->assertForbidden();
        $this->assertSame($mappingCount, LegacyIngredientCatalogueMapping::query()->count());
    }

    public function test_legacy_pending_mapping_does_not_expose_another_users_record(): void
    {
        $submitter = User::factory()->create();
        $other = User::factory()->create();
        $pending = $this->mappedItem(
            $submitter,
            CatalogueItemStatus::Pending,
            CatalogueItemOrigin::Manual,
            'Private mapped route item',
        );
        $legacy = $this->legacyIngredient($pending);

        $this->actingAs($other)->get(route('ingredients.show', $legacy))->assertNotFound();
        $this->actingAs($submitter)
            ->get(route('ingredients.show', $legacy))
            ->assertRedirect(route('catalogue.show', $pending));
    }

    public function test_legacy_index_redirect_preserves_safe_query_parameters_without_loop(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('ingredients.index', [
            'q' => 'oats',
            'page' => 2,
        ]));

        $response->assertRedirect(route('catalogue.index', ['q' => 'oats', 'page' => 2]));
        $this->actingAs($user)->get($response->headers->get('Location'))->assertOk();
    }

    public function test_rollback_flag_restores_legacy_reads_and_disables_catalogue_routes(): void
    {
        config(['catalogue.read_cutover' => false]);
        $owner = User::factory()->create();
        $legacy = Ingredient::factory()->for($owner)->manual()->create();

        $this->actingAs($owner)->get(route('ingredients.index'))->assertOk();
        $this->actingAs($owner)->get(route('ingredients.show', $legacy))->assertOk();
        $this->get(route('catalogue.index'))->assertNotFound();
        $this->get(route('catalogue.show', ['catalogueItem' => 999]))->assertNotFound();
    }

    public function test_guest_cannot_use_legacy_mutation_routes(): void
    {
        $ingredient = Ingredient::factory()->manual()->create();

        $this->put(route('ingredients.update', $ingredient), $this->ingredientPayload())
            ->assertRedirect(route('login'));
        $this->delete(route('ingredients.destroy', $ingredient))
            ->assertRedirect(route('login'));
    }

    private function mappedItem(
        User $submitter,
        CatalogueItemStatus $status,
        CatalogueItemOrigin $origin,
        string $name,
        ?string $barcode = null,
        array $snapshot = [],
    ): CatalogueItem {
        $barcode ??= $origin === CatalogueItemOrigin::Barcode
            ? fake()->unique()->numerify('0############')
            : null;
        $ingredientFactory = Ingredient::factory()->for($submitter);
        $ingredient = $origin === CatalogueItemOrigin::Barcode
            ? $ingredientFactory->barcodeImported()->create(['name' => $name, 'barcode' => $barcode])
            : $ingredientFactory->manual()->create(['name' => $name]);
        $catalogueFactory = CatalogueItem::factory()->submittedBy($submitter);
        $item = $origin === CatalogueItemOrigin::Barcode
            ? $catalogueFactory->barcodeBacked($barcode)->create(['status' => $status])
            : $catalogueFactory->manual()->create(['status' => $status]);
        $legacySnapshot = array_merge([
            'name' => $name,
            'barcode' => $barcode,
            'quantity' => $ingredient->quantity,
            'quantity_unit' => $ingredient->quantity_unit,
            'serving_quantity' => $ingredient->serving_quantity,
            'serving_quantity_unit' => $ingredient->serving_quantity_unit,
            'recommended_servings' => $ingredient->recommended_servings,
            'image_url' => $ingredient->image_url,
            'keywords' => $ingredient->keywords,
            'categories' => $ingredient->categories,
            'nutriments' => $ingredient->nutriments,
        ], $snapshot);

        LegacyIngredientCatalogueMapping::factory()->create([
            'ingredient_id' => $ingredient->id,
            'legacy_user_id' => $submitter->id,
            'catalogue_item_id' => $item->id,
            'classification' => $origin === CatalogueItemOrigin::Barcode
                ? LegacyIngredientClassification::VerifiedImported
                : LegacyIngredientClassification::LegacyManual,
            'legacy_snapshot' => $legacySnapshot,
            'legacy_checksum' => hash('sha256', json_encode($legacySnapshot, JSON_THROW_ON_ERROR)),
        ]);

        return $item;
    }

    private function legacyIngredient(CatalogueItem $item): Ingredient
    {
        $legacyId = LegacyIngredientCatalogueMapping::query()
            ->where('catalogue_item_id', $item->id)
            ->value('ingredient_id');

        return Ingredient::query()->findOrFail($legacyId);
    }

    /** @return array<string, mixed> */
    private function ingredientPayload(): array
    {
        return [
            'name' => 'Attempted mutation',
            'barcode' => null,
            'quantity' => 1,
            'quantity_unit' => 'g',
            'submitted_by_user_id' => 999999,
        ];
    }
}
