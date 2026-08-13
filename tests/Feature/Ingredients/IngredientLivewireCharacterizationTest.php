<?php

namespace Tests\Feature\Ingredients;

use App\Livewire\Ingredients\Form;
use App\Livewire\Ingredients\Index;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;
use Tests\TestCase;

class IngredientLivewireCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_direct_livewire_update_changes_the_correct_record_without_changing_ownership(): void
    {
        $owner = User::factory()->create();
        $ingredient = $this->ingredientFor($owner, 'Livewire original', [
            'barcode' => 'LIVEWIRE-OWNER',
            'categories' => ['unchanged-category'],
        ]);

        Livewire::actingAs($owner)->test(Form::class, ['ingredient' => $ingredient])
            ->set('name', 'Livewire owner update')
            ->set('quantity', 2)
            ->set('quantity_unit', 'kg')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('name', 'Livewire owner update')
            ->assertSet('quantity_unit', 'kg');

        $ingredient->refresh();
        $this->assertSame($owner->id, $ingredient->user_id);
        $this->assertSame('Livewire owner update', $ingredient->name);
        $this->assertSame('LIVEWIRE-OWNER', $ingredient->barcode);
        $this->assertSame(['unchanged-category'], $ingredient->categories);
    }

    public function test_invalid_owner_livewire_update_keeps_component_state_and_database_unchanged(): void
    {
        $owner = User::factory()->create();
        $ingredient = $this->ingredientFor($owner, 'Livewire validation original', [
            'quantity' => 3,
            'quantity_unit' => 'kg',
        ]);

        Livewire::actingAs($owner)->test(Form::class, ['ingredient' => $ingredient])
            ->set('name', '')
            ->set('quantity', -1)
            ->set('quantity_unit', "bad\nunit")
            ->call('save')
            ->assertHasErrors(['name', 'quantity', 'quantity_unit'])
            ->assertSet('name', '')
            ->assertSet('quantity', -1);

        $ingredient->refresh();
        $this->assertSame($owner->id, $ingredient->user_id);
        $this->assertSame('Livewire validation original', $ingredient->name);
        $this->assertSame('3.000', $ingredient->quantity);
        $this->assertSame('kg', $ingredient->quantity_unit);
    }

    public function test_livewire_accepts_zero_quantity_boundary(): void
    {
        $owner = User::factory()->create();
        $ingredient = $this->ingredientFor($owner, 'Livewire boundary original');

        Livewire::actingAs($owner)->test(Form::class, ['ingredient' => $ingredient])
            ->set('name', 'Livewire zero boundary')
            ->set('quantity', 0)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('0.000', $ingredient->refresh()->quantity);
    }

    public function test_non_owner_direct_livewire_update_is_forbidden_and_leaves_both_users_records_unchanged(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownersIngredient = $this->ingredientFor($owner, 'Owner vulnerable record');
        $otherUsersIngredient = $this->ingredientFor($otherUser, 'Other user untouched record');

        Livewire::actingAs($otherUser)->test(Form::class, ['ingredient' => $ownersIngredient])
            ->set('name', 'Cross-user Livewire mutation')
            ->set('quantity', 4)
            ->set('quantity_unit', 'kg')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ownersIngredient->id,
            'user_id' => $owner->id,
            'name' => 'Owner vulnerable record',
        ]);
        $this->assertDatabaseHas('ingredients', [
            'id' => $otherUsersIngredient->id,
            'user_id' => $otherUser->id,
            'name' => 'Other user untouched record',
        ]);
    }

    public function test_guest_direct_livewire_update_is_forbidden_and_leaves_record_unchanged(): void
    {
        $owner = User::factory()->create();
        $ingredient = $this->ingredientFor($owner, 'Guest Livewire authorization protected');

        Livewire::test(Form::class, ['ingredient' => $ingredient])
            ->set('name', 'Guest Livewire attempted mutation')
            ->set('quantity', 5)
            ->set('quantity_unit', 'kg')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'user_id' => $owner->id,
            'name' => 'Guest Livewire authorization protected',
            'quantity_unit' => 'g',
        ]);
    }

    public function test_guest_direct_livewire_create_is_forbidden_and_creates_nothing(): void
    {
        Livewire::test(Form::class)
            ->set('name', 'Guest Livewire attempted creation')
            ->set('quantity', 1)
            ->set('quantity_unit', 'g')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseCount('ingredients', 0);
    }

    public function test_forged_livewire_ingredient_identifier_is_forbidden_and_changes_no_records(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownersIngredient = $this->ingredientFor($owner, 'Mounted owner record');
        $otherUsersIngredient = $this->ingredientFor($otherUser, 'Forged target record');

        Livewire::actingAs($owner)->test(Form::class, ['ingredient' => $ownersIngredient])
            ->set('ingredientId', $otherUsersIngredient->id)
            ->set('name', 'Forged target mutation')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ownersIngredient->id,
            'user_id' => $owner->id,
            'name' => 'Mounted owner record',
        ]);
        $this->assertDatabaseHas('ingredients', [
            'id' => $otherUsersIngredient->id,
            'user_id' => $otherUser->id,
            'name' => 'Forged target record',
        ]);
        $this->assertDatabaseCount('ingredients', 2);
    }

    public function test_stale_mounted_livewire_component_rechecks_current_ownership_before_update(): void
    {
        $originalOwner = User::factory()->create();
        $newOwner = User::factory()->create();
        $ingredient = $this->ingredientFor($originalOwner, 'Stale component target');

        $component = Livewire::actingAs($originalOwner)->test(Form::class, ['ingredient' => $ingredient]);

        Ingredient::query()->whereKey($ingredient)->update(['user_id' => $newOwner->id]);

        $component
            ->set('name', 'Stale component mutation')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'user_id' => $newOwner->id,
            'name' => 'Stale component target',
        ]);
        $this->assertDatabaseCount('ingredients', 1);
    }

    public function test_forged_livewire_ownership_field_is_rejected_and_cannot_reassign_the_record(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $ingredient = $this->ingredientFor($owner, 'Ownership-bound record');

        try {
            Livewire::actingAs($owner)->test(Form::class, ['ingredient' => $ingredient])
                ->set('user_id', $otherUser->id)
                ->set('name', 'Forged ownership update')
                ->call('save');

            $this->fail('The ownership field must not be public Livewire state.');
        } catch (PublicPropertyNotFoundException) {
            // Expected: ownership is not a bindable component property.
        }

        $ingredient->refresh();
        $this->assertSame($owner->id, $ingredient->user_id);
        $this->assertSame('Ownership-bound record', $ingredient->name);
    }

    public function test_owner_can_open_the_currently_unused_edit_modal_path(): void
    {
        $owner = User::factory()->create();
        $ingredient = $this->ingredientFor($owner, 'Dormant modal owner record');

        Livewire::actingAs($owner)->test(Index::class)
            ->call('openEditModal', $ingredient->id)
            ->assertSet('editingIngredientId', $ingredient->id)
            ->assertSet('showFormModal', true)
            ->assertSet('showDetailsModal', false)
            ->assertSee('Edit Ingredient')
            ->assertSee('Dormant modal owner record');
    }

    public function test_non_owner_direct_edit_modal_invocation_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $ingredient = $this->ingredientFor($owner, 'Dormant modal private record');

        Livewire::actingAs($otherUser)->test(Index::class)
            ->call('openEditModal', $ingredient->id)
            ->assertForbidden();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'user_id' => $owner->id,
            'name' => 'Dormant modal private record',
        ]);
    }

    public function test_guest_direct_edit_modal_invocation_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $ingredient = $this->ingredientFor($owner, 'Dormant modal guest record');

        Livewire::test(Index::class)
            ->call('openEditModal', $ingredient->id)
            ->assertForbidden();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'user_id' => $owner->id,
            'name' => 'Dormant modal guest record',
        ]);
    }

    public function test_controller_and_livewire_normalize_valid_unit_aliases_consistently(): void
    {
        $owner = User::factory()->create();
        $controllerIngredient = $this->ingredientFor($owner, 'Controller unit original');
        $livewireIngredient = $this->ingredientFor($owner, 'Livewire unit original');

        $this->actingAs($owner)
            ->put(route('ingredients.update', $controllerIngredient), [
                'name' => 'Controller unit update',
                'quantity' => 1,
                'quantity_unit' => 'grams',
            ])
            ->assertRedirect(route('ingredients.index'));

        Livewire::actingAs($owner)->test(Form::class, ['ingredient' => $livewireIngredient])
            ->set('name', 'Livewire unit update')
            ->set('quantity', 1)
            ->set('quantity_unit', 'grams')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('g', $controllerIngredient->refresh()->quantity_unit);
        $this->assertSame('g', $livewireIngredient->refresh()->quantity_unit);
    }

    private function ingredientFor(User $owner, string $name, array $attributes = []): Ingredient
    {
        return Ingredient::factory()->for($owner)->create(array_merge([
            'name' => $name,
        ], $attributes));
    }
}
