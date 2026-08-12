<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AdministratorAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sole_administrator_account_deletion_is_denied(): void
    {
        $administrator = User::factory()->administrator()->create(['password' => 'password']);
        $this->actingAs($administrator);

        Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors('password');

        $this->assertNotNull($administrator->fresh());
        $this->assertAuthenticatedAs($administrator);
    }

    public function test_one_of_multiple_administrators_can_follow_existing_account_deletion(): void
    {
        $administrator = User::factory()->administrator()->create(['password' => 'password']);
        User::factory()->administrator()->create();
        $this->actingAs($administrator);

        Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertNull($administrator->fresh());
        $this->assertSame(1, User::query()->where('is_administrator', true)->count());
    }
}
