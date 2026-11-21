<?php

namespace Tests\Feature\Recipes;

use App\Domain\Recipes\RecipeRevisionManager;
use App\Livewire\Recipes\Form;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecipeQuantityEditorDisplayTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{?string, ?string}> */
    public static function quantities(): iterable
    {
        yield 'whole grams' => ['300.000000000000000000', '300'];
        yield 'fractional amount' => ['1.230000000000000000', '1.23'];
        yield 'half up' => ['1.234500000000000000', '1.235'];
        yield 'full precision' => ['1.234567890123456789', '1.235'];
        yield 'small positive' => ['0.000000000000000001', '<0.001'];
        yield 'zero' => ['0.000000000000000000', '0'];
        yield 'missing' => [null, null];
        yield 'large exact amount' => ['12345678901234567890.123456789012345678', '12345678901234567890.123'];
    }

    #[DataProvider('quantities')]
    public function test_editor_formats_quantity_but_saving_other_fields_preserves_exact_stored_amount(?string $original, ?string $display): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        $line = $recipe->ingredientLines()->sole();
        $line->update(['quantity' => $original, 'original_text' => '  Original imported quantity wording  ']);
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->assertSet('ingredients.0.quantity', $display)
            ->set('title', 'Other field edited')
            ->call('save')->assertHasNoErrors()
            ->assertSet('ingredients.0.quantity', $display)
            ->assertSet('unsaved', false);
        $this->assertSame($original, $line->fresh()->quantity);
        $this->assertSame('  Original imported quantity wording  ', $line->fresh()->original_text);
    }

    public function test_explicit_edit_to_the_displayed_number_replaces_full_precision_and_can_be_cleared_or_zero(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        $line = $recipe->ingredientLines()->sole();
        $line->update(['quantity' => '1.234567890123456789']);
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->assertSet('ingredients.0.quantity', '1.235')
            // The input event records deliberate editing even when its final text is unchanged.
            ->set('editedIngredientQuantities.'.$line->id, true)
            ->call('save')->assertHasNoErrors();
        $this->assertSame('1.235000000000000000', $line->fresh()->quantity);
        $component->set('ingredients.0.quantity', '0')->call('save')->assertHasNoErrors();
        $this->assertSame('0.000000000000000000', $line->fresh()->quantity);
        $component->set('ingredients.0.quantity', '')->call('save')->assertHasNoErrors();
        $this->assertNull($line->fresh()->quantity);
    }

    public function test_reordering_and_validation_failure_preserve_each_rows_precision_and_pending_input(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        $first = $recipe->ingredientLines()->sole();
        $first->update(['quantity' => '1.234567890123456789']);
        $second = RecipeIngredientLine::factory()->for($recipe)->create(['position' => 1, 'quantity' => '0.000000000000000001']);
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->call('moveIngredientDown', 0)->set('title', '')->call('save')->assertHasErrors('title')
            ->assertSet('ingredients.0.quantity', '<0.001')->assertSet('ingredients.1.quantity', '1.235')
            ->set('title', 'Recovered title')->set('ingredients.1.quantity', 'bad amount')
            ->call('save')->assertHasErrors('ingredients.1.quantity')
            ->assertSet('ingredients.1.quantity', 'bad amount')
            ->set('ingredients.1.quantity', '2.345678901234567891')->call('save')->assertHasNoErrors();
        $this->assertSame('0.000000000000000001', $second->fresh()->quantity);
        $this->assertSame('2.345678901234567891', $first->fresh()->quantity);
        $this->assertSame(1, $first->fresh()->position);
    }

    public function test_finalizing_and_publishing_a_revision_keep_untouched_exact_quantities_in_snapshots(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        $line = $recipe->ingredientLines()->sole();
        $line->update(['quantity' => '0.000000000000000001']);
        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe])
            ->assertSet('ingredients.0.quantity', '<0.001')->call('finalize')->assertHasNoErrors();
        $firstVersion = $recipe->fresh()->currentVersion;
        $this->assertSame('0.000000000000000001', $firstVersion->snapshot['ingredients'][0]['quantity']);
        app(RecipeRevisionManager::class)->startOrResume($recipe->id, $owner);
        Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe->fresh()])
            ->set('title', 'Revised title')->call('finalize')->assertHasNoErrors();
        $this->assertSame('0.000000000000000001', $recipe->fresh()->currentVersion->snapshot['ingredients'][0]['quantity']);
        $this->assertSame('0.000000000000000001', $firstVersion->fresh()->snapshot['ingredients'][0]['quantity']);
    }

    public function test_rounding_does_not_bypass_stale_draft_detection(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        $line = $recipe->ingredientLines()->sole();
        $line->update(['quantity' => '1.234567890123456789']);
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe]);
        $line->update(['quantity' => '9.876543210987654321']);
        $component->set('title', 'Stale edit')->call('save')->assertHasErrors('conflict')
            ->assertSet('ingredients.0.quantity', '1.235');
        $this->assertSame('9.876543210987654321', $line->fresh()->quantity);
    }

    public function test_client_cannot_replace_the_original_quantity_baseline(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->validDraft()->create();
        $line = $recipe->ingredientLines()->sole();
        $component = Livewire::actingAs($owner)->test(Form::class, ['recipe' => $recipe]);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('ingredientQuantityBaselines.'.$line->id.'.original', '999');
    }
}
