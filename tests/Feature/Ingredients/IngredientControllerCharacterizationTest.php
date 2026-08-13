<?php

namespace Tests\Feature\Ingredients;

use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngredientControllerCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_every_ingredient_page_route(): void
    {
        $ingredient = $this->ingredientFor(User::factory()->create(), 'Owner route record');

        foreach ([
            route('ingredients.index'),
            route('ingredients.create'),
            route('ingredients.show', $ingredient),
            route('ingredients.edit', $ingredient),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_owner_can_reach_every_ingredient_page_route(): void
    {
        $owner = User::factory()->create();
        $ingredient = $this->ingredientFor($owner, 'Owner route record');

        $this->actingAs($owner)->get(route('ingredients.index'))->assertOk();
        $this->actingAs($owner)->get(route('ingredients.create'))->assertOk();
        $this->actingAs($owner)->get(route('ingredients.show', $ingredient))->assertOk();
        $this->actingAs($owner)->get(route('ingredients.edit', $ingredient))->assertOk();
    }

    public function test_non_owner_receives_forbidden_for_show_and_edit(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $ingredient = $this->ingredientFor($owner, 'Private owner record');

        $this->actingAs($otherUser)->get(route('ingredients.show', $ingredient))->assertForbidden();
        $this->actingAs($otherUser)->get(route('ingredients.edit', $ingredient))->assertForbidden();
    }

    public function test_controller_store_assigns_the_authenticated_owner_and_ignores_forged_ownership(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('ingredients.store'), $this->validPayload([
                'name' => 'Controller-created oats',
                'user_id' => $otherUser->id,
            ]))
            ->assertRedirect(route('ingredients.index'));

        $ingredient = Ingredient::query()->sole();

        $this->assertSame($owner->id, $ingredient->user_id);
        $this->assertSame('Controller-created oats', $ingredient->name);
        $this->assertSame('Original barcode', $ingredient->barcode);
    }

    public function test_controller_store_validation_failure_leaves_database_unchanged(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('ingredients.store'), $this->validPayload([
                'name' => '',
                'quantity' => -1,
                'quantity_unit' => "bad\nunit",
            ]))
            ->assertSessionHasErrors(['name', 'quantity', 'quantity_unit']);

        $this->assertDatabaseCount('ingredients', 0);
    }

    public function test_guest_controller_store_redirects_to_login_without_creating_a_record(): void
    {
        $this->post(route('ingredients.store'), $this->validPayload())
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('ingredients', 0);
    }

    public function test_owner_controller_update_changes_only_submitted_attributes(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $ingredient = $this->ingredientFor($owner, 'Original owner name', [
            'barcode' => 'Owner-only barcode',
            'categories' => ['unchanged-category'],
        ]);

        $this->actingAs($owner)
            ->put(route('ingredients.update', $ingredient), $this->validPayload([
                'name' => 'Controller-updated name',
                'barcode' => 'Owner-only barcode',
                'categories' => ['unchanged-category'],
                'user_id' => $otherUser->id,
            ]))
            ->assertRedirect(route('ingredients.index'));

        $ingredient->refresh();

        $this->assertSame($owner->id, $ingredient->user_id);
        $this->assertSame('Controller-updated name', $ingredient->name);
        $this->assertSame('Owner-only barcode', $ingredient->barcode);
        $this->assertSame(['unchanged-category'], $ingredient->categories);
    }

    public function test_non_owner_controller_update_is_forbidden_and_leaves_both_users_records_unchanged(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownersIngredient = $this->ingredientFor($owner, 'Owner private flour');
        $otherIngredient = $this->ingredientFor($otherUser, 'Other user sugar');

        $this->actingAs($otherUser)
            ->put(route('ingredients.update', $ownersIngredient), $this->validPayload([
                'name' => 'Attempted cross-user update',
            ]))
            ->assertForbidden();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ownersIngredient->id,
            'user_id' => $owner->id,
            'name' => 'Owner private flour',
        ]);
        $this->assertDatabaseHas('ingredients', [
            'id' => $otherIngredient->id,
            'user_id' => $otherUser->id,
            'name' => 'Other user sugar',
        ]);
    }

    public function test_guest_controller_update_redirects_to_login_and_leaves_record_unchanged(): void
    {
        $owner = User::factory()->create();
        $ingredient = $this->ingredientFor($owner, 'Guest-protected record');

        $this->put(route('ingredients.update', $ingredient), $this->validPayload([
            'name' => 'Guest attempted update',
        ]))->assertRedirect(route('login'));

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'user_id' => $owner->id,
            'name' => 'Guest-protected record',
        ]);
    }

    public function test_invalid_controller_update_leaves_all_attributes_unchanged(): void
    {
        $owner = User::factory()->create();
        $ingredient = $this->ingredientFor($owner, 'Validation-protected record', [
            'quantity' => 2,
            'quantity_unit' => 'kg',
        ]);

        $this->actingAs($owner)
            ->put(route('ingredients.update', $ingredient), $this->validPayload([
                'name' => '',
                'quantity' => -0.001,
            ]))
            ->assertSessionHasErrors(['name', 'quantity']);

        $ingredient->refresh();
        $this->assertSame('Validation-protected record', $ingredient->name);
        $this->assertSame('2.000', $ingredient->quantity);
        $this->assertSame('kg', $ingredient->quantity_unit);
    }

    public function test_controller_accepts_zero_quantity_boundary(): void
    {
        $owner = User::factory()->create();
        $ingredient = $this->ingredientFor($owner, 'Boundary quantity');

        $this->actingAs($owner)
            ->put(route('ingredients.update', $ingredient), $this->validPayload([
                'name' => 'Zero quantity boundary',
                'quantity' => 0,
            ]))
            ->assertRedirect(route('ingredients.index'));

        $this->assertSame('0.000', $ingredient->refresh()->quantity);
    }

    public function test_owner_controller_delete_hard_deletes_and_redirects_without_page_state(): void
    {
        $owner = User::factory()->create();
        $laterPageIngredient = $this->ingredientFor($owner, 'Later page deletion target');

        $this->actingAs($owner)
            ->from(route('ingredients.index', ['page' => 2, 'q' => 'target']))
            ->delete(route('ingredients.destroy', $laterPageIngredient))
            ->assertRedirect(route('ingredients.index'));

        $this->assertDatabaseMissing('ingredients', ['id' => $laterPageIngredient->id]);
    }

    public function test_non_owner_controller_delete_is_forbidden_and_leaves_record_intact(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $ingredient = $this->ingredientFor($owner, 'Owner undeleted record');

        $this->actingAs($otherUser)
            ->delete(route('ingredients.destroy', $ingredient))
            ->assertForbidden();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'user_id' => $owner->id,
            'name' => 'Owner undeleted record',
        ]);
    }

    public function test_guest_controller_delete_redirects_to_login_and_leaves_record_intact(): void
    {
        $owner = User::factory()->create();
        $ingredient = $this->ingredientFor($owner, 'Guest undeleted record');

        $this->delete(route('ingredients.destroy', $ingredient))
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'user_id' => $owner->id,
            'name' => 'Guest undeleted record',
        ]);
    }

    private function ingredientFor(User $owner, string $name, array $attributes = []): Ingredient
    {
        return Ingredient::factory()->for($owner)->create(array_merge([
            'name' => $name,
        ], $attributes));
    }

    private function validPayload(array $attributes = []): array
    {
        return array_merge([
            'name' => 'Valid controller ingredient',
            'barcode' => 'Original barcode',
            'quantity' => 1,
            'quantity_unit' => 'g',
        ], $attributes);
    }
}
