<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response
            ->assertOk()
            ->assertSeeVolt('pages.auth.register');
    }

    public function test_new_users_can_register(): void
    {
        $component = Volt::test('pages.auth.register')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password');

        $component->call('register');

        $component->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_registration_cannot_assign_administrator_status(): void
    {
        try {
            Volt::test('pages.auth.register')
                ->set('name', 'Test User')
                ->set('email', 'test@example.com')
                ->set('password', 'password')
                ->set('password_confirmation', 'password')
                ->set('is_administrator', true)
                ->call('register');

            $this->fail('Livewire accepted a forged administrator property.');
        } catch (PublicPropertyNotFoundException) {
            // The component exposes no administrator state to submitted input.
        }

        $this->assertDatabaseMissing('users', [
            'email' => 'test@example.com',
        ]);
        $this->assertSame(0, User::query()->where('is_administrator', true)->count());
    }
}
