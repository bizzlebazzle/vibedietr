<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdministratorAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'can:access-admin'])
            ->get('/__tests/admin-only', fn () => 'Administrator access granted.');
    }

    public function test_migration_preserves_existing_users_as_ordinary_users(): void
    {
        $migration = require database_path(
            'migrations/2026_07_31_000000_add_is_administrator_to_users_table.php'
        );

        $migration->down();

        $userId = DB::table('users')->insertGetId([
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password' => 'password',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertFalse((bool) DB::table('users')
            ->where('id', $userId)
            ->value('is_administrator'));

        DB::table('users')->where('id', $userId)->delete();
    }

    public function test_new_users_default_to_ordinary_users(): void
    {
        $user = User::factory()->create();
        $user->refresh();

        $this->assertFalse($user->is_administrator);
        $this->assertFalse($user->isAdministrator());
    }

    public function test_administrator_status_persists(): void
    {
        $administrator = User::factory()->administrator()->create();

        $administrator->refresh();

        $this->assertTrue($administrator->is_administrator);
        $this->assertTrue($administrator->isAdministrator());
        $this->assertDatabaseHas('users', [
            'id' => $administrator->id,
            'is_administrator' => true,
        ]);
    }

    public function test_administrator_status_is_protected_from_mass_assignment(): void
    {
        $ordinaryUser = User::create([
            'name' => 'Ordinary User',
            'email' => 'ordinary@example.com',
            'password' => 'password',
            'is_administrator' => true,
        ]);

        $ordinaryUser->update([
            'name' => 'Updated Ordinary User',
            'is_administrator' => true,
        ]);

        $administrator = User::factory()->administrator()->create();
        $administrator->update(['is_administrator' => false]);

        $this->assertFalse($ordinaryUser->refresh()->isAdministrator());
        $this->assertTrue($administrator->refresh()->isAdministrator());
    }

    public function test_central_gate_allows_administrators(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->assertTrue(Gate::forUser($administrator)->allows('access-admin'));
    }

    public function test_central_gate_denies_ordinary_users(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Gate::forUser($user)->denies('access-admin'));
    }

    public function test_central_gate_does_not_grant_access_to_guests(): void
    {
        $this->assertGuest();
        $this->assertFalse(Gate::allows('access-admin'));
    }

    public function test_administrator_can_access_a_protected_route(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->actingAs($administrator)
            ->get('/__tests/admin-only')
            ->assertOk()
            ->assertSee('Administrator access granted.');
    }

    public function test_ordinary_user_cannot_access_a_protected_route(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/__tests/admin-only')
            ->assertForbidden();
    }

    public function test_guest_is_redirected_from_a_protected_route(): void
    {
        $this->get('/__tests/admin-only')
            ->assertRedirect(route('login'));
    }
}
