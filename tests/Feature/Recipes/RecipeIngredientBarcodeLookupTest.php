<?php

namespace Tests\Feature\Recipes;

use App\Domain\Catalogue\CatalogueItemStatus;
use App\Livewire\Recipes\Form;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecipeIngredientBarcodeLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openfoodfacts.attempts' => 1, 'catalogue.read_cutover' => true]);
        Http::preventStrayRequests();
    }

    public function test_existing_approved_barcode_is_offered_without_provider_call_or_automatic_match_and_pending_edits_survive(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        $line = $recipe->ingredientLines()->sole();
        $item = $this->item();
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe]);
        $fingerprint = $component->get('baselineFingerprint');
        $component->set('title', 'Pending title')->set('ingredients.0.original_text', 'Pending original')
            ->call('lookupIngredientBarcode', $line->id, ' 0012345678905 ')
            ->assertHasNoErrors()->assertSet('title', 'Pending title')
            ->assertSet('ingredients.0.original_text', 'Pending original')
            ->assertSet('unsaved', true)->assertSet('baselineFingerprint', $fingerprint)
            ->assertSet('catalogueResults.'.$line->id.'.0.item_id', $item->id)
            ->assertSee('Product found. Select the food below');
        Http::assertNothingSent();
        $this->assertDatabaseCount('recipe_ingredient_line_matches', 0);
        $component->call('selectCatalogueMatch', 0, $item->id, $item->current_catalogue_item_version_id)
            ->assertHasNoErrors()->assertSet('title', 'Pending title')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('Pending original', $line->fresh()->original_text);
        $this->assertDatabaseHas('recipe_ingredient_line_matches', ['recipe_ingredient_line_id' => $line->id]);
    }

    public function test_successful_lookup_imports_once_and_keeps_clean_editor_clean_until_explicit_selection(): void
    {
        Http::fake(['*' => Http::response($this->response())]);
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        $lineId = $recipe->ingredientLines()->sole()->id;
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->call('lookupIngredientBarcode', $lineId, '0012345678905')
            ->assertHasNoErrors()->assertSet('unsaved', false)->assertSee('Scanned oats')
            ->call('lookupIngredientBarcode', $lineId, '0012345678905')->assertHasNoErrors();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('catalogue_items', 1);
        $this->assertDatabaseCount('catalogue_item_versions', 1);
        $this->assertDatabaseCount('recipe_ingredient_line_matches', 0);
        $item = CatalogueItem::query()->sole();
        $component->call('selectCatalogueMatch', 0, $item->id, $item->current_catalogue_item_version_id)
            ->assertHasNoErrors();
    }

    /** @return iterable<string, array{int, array, string}> */
    public static function failures(): iterable
    {
        yield 'missing' => [404, [], 'No product found'];
        yield 'rate limit' => [429, [], 'Too many lookups'];
        yield 'unavailable' => [500, [], 'temporarily unavailable'];
        yield 'invalid' => [200, ['status' => 'success'], 'Product data could not be read'];
        yield 'mismatched' => [200, ['status' => 'success', 'result' => ['id' => 'product_found'],
            'product' => ['code' => '9999999999999', 'product_name' => 'Wrong code', 'nutriments' => ['proteins_100g' => '0']]], 'barcode'];
    }

    #[DataProvider('failures')]
    public function test_provider_failures_keep_recipe_and_catalogue_unchanged(int $status, array $body, string $message): void
    {
        Http::fake(['*' => Http::response($body, $status)]);
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        $lineId = $recipe->ingredientLines()->sole()->id;
        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->set('title', 'Keep pending input')->call('lookupIngredientBarcode', $lineId, '0012345678905')
            ->assertHasErrors('barcodeLookups.'.$lineId)->assertSee($message)
            ->assertSet('title', 'Keep pending input')->assertSet('unsaved', true);
        $this->assertDatabaseCount('catalogue_items', 0);
        $this->assertDatabaseCount('recipe_ingredient_line_matches', 0);
    }

    public function test_invalid_codes_and_unavailable_existing_identity_never_call_provider(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        $lineId = $recipe->ingredientLines()->sole()->id;
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe]);
        foreach (['', str_repeat('1', 65), "123\n456"] as $code) {
            $component->call('lookupIngredientBarcode', $lineId, $code)->assertHasErrors('barcodeLookups.'.$lineId);
        }
        $item = $this->item();
        $item->forceFill(['status' => CatalogueItemStatus::Rejected])->save();
        $component->call('lookupIngredientBarcode', $lineId, $item->barcode)
            ->assertHasErrors('barcodeLookups.'.$lineId)->assertDontSee('Scanned oats');
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{string}> */
    public static function limiters(): iterable
    {
        yield 'user' => ['barcode_user'];
        yield 'global' => ['barcode_global'];
    }

    #[DataProvider('limiters')]
    public function test_lookup_limits_are_local_errors_and_prevent_provider_work(string $limiter): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        config(['security.throttles.'.$limiter.'.attempts' => 1]);
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->call('lookupIngredientBarcode', $recipe->ingredientLines()->sole()->id, '0012345678905')
            ->assertHasErrors()
            ->call('lookupIngredientBarcode', $recipe->ingredientLines()->sole()->id, '0012345678905')
            ->assertHasErrors()->assertSee('Too many lookups');
        Http::assertSentCount(1);
    }

    public function test_lookup_rechecks_owner_recipe_and_saved_line_before_provider_work(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        $foreign = Recipe::factory()->validDraft()->create();
        $lineId = $recipe->ingredientLines()->sole()->id;
        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->call('lookupIngredientBarcode', $foreign->ingredientLines()->sole()->id, '0012345678905')->assertNotFound();
        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->call('removeIngredient', 0)->call('lookupIngredientBarcode', $lineId, '0012345678905')->assertNotFound();
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe]);
        $recipe->forceFill(['user_id' => $foreign->user_id])->save();
        $component->call('lookupIngredientBarcode', $lineId, '0012345678905')->assertForbidden();
        Livewire::test(Form::class, ['recipe' => $recipe])->assertForbidden();
        Http::assertNothingSent();
    }

    private function item(): CatalogueItem
    {
        $item = CatalogueItem::factory()->barcodeBacked()->create(['barcode' => '0012345678905']);
        CatalogueItemVersion::factory()->current()->completeNutrition()->create([
            'catalogue_item_id' => $item->id, 'name' => 'Scanned oats',
        ]);

        return $item->fresh();
    }

    private function response(): array
    {
        return ['status' => 'success', 'result' => ['id' => 'product_found'],
            'product' => ['code' => '0012345678905', 'product_name' => 'Scanned oats',
                'nutriments' => ['proteins_100g' => '0']]];
    }
}
