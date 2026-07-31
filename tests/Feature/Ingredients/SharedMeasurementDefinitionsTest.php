<?php

namespace Tests\Feature\Ingredients;

use App\Domain\Measurements\MeasurementUnitRegistry;
use App\Livewire\Ingredients\Form;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SharedMeasurementDefinitionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingredient_form_uses_the_shared_unit_catalogue(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test(Form::class);

        $this->assertSame(MeasurementUnitRegistry::formGroups()['Weight']['g'], $component->instance()->measurementUnitGroups()['Weight']['g']);
        $component->assertSee('US fluid ounce');
        $component->assertSee('UK tablespoon');
    }

    public function test_standard_alias_is_normalized_to_its_storage_symbol(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Form::class)
            ->set('name', 'Alias ingredient')
            ->set('quantity', '2.5')
            ->set('quantity_unit', 'grams')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('g', Ingredient::query()->sole()->quantity_unit);
    }

    public function test_custom_unit_is_valid_and_preserved_exactly(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Form::class)
            ->set('name', 'Custom unit ingredient')
            ->set('quantity', '1')
            ->set('quantity_unit', 'one package-specific unit')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('one package-specific unit', Ingredient::query()->sole()->quantity_unit);
    }

    public function test_ambiguous_single_letter_unit_is_not_silently_coerced(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Form::class)
            ->set('name', 'Ambiguous unit ingredient')
            ->set('quantity', '1')
            ->set('quantity_unit', 'T')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('T', Ingredient::query()->sole()->quantity_unit);
    }

    public function test_unsafe_custom_unit_is_rejected(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Form::class)
            ->set('name', 'Unsafe unit ingredient')
            ->set('quantity', '1')
            ->set('quantity_unit', "bad\nunit")
            ->call('save')
            ->assertHasErrors('quantity_unit');
    }
}
