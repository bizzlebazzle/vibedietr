<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AccessibilityRemediationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_authentication_pages_have_named_heading_main_and_skip_destination(): void
    {
        foreach (['login', 'register', 'password.request'] as $route) {
            $html = $this->get(route($route))->assertOk()->getContent();
            $document = new DOMDocument;
            @$document->loadHTML($html);
            $xpath = new DOMXPath($document);
            $this->assertSame(1, $xpath->query('//main[@id="main-content" and @tabindex="-1"]')->length);
            $this->assertSame(1, $xpath->query('//h1')->length);
            $this->assertSame(1, $xpath->query('//a[@href="#main-content"]')->length);
        }
    }

    public function test_login_errors_use_the_shared_summary_without_losing_email(): void
    {
        $user = User::factory()->create();
        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'incorrect-password')
            ->call('login')
            ->assertHasErrors('form.email')
            ->assertSet('form.email', $user->email)
            ->assertSee('data-validation-summary', false)
            ->assertSee('data-feedback-error', false);
        $this->assertGuest();
    }

    public function test_navigation_and_profile_expose_state_and_semantic_controls(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('profile'))->assertOk()->getContent();
        $document = new DOMDocument;
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $this->assertSame(1, $xpath->query('//nav[@aria-label="Primary navigation"]')->length);
        $this->assertSame(1, $xpath->query('//h1')->length);
        $this->assertSame(0, $xpath->query('//button//a | //a//button')->length);
        $this->assertSame(1, $xpath->query('//button[@id="delete-account-trigger"]')->length);
        $this->assertStringContainsString(':aria-pressed=', $html);
        $this->assertStringContainsString('sibling.inert = true', $html);
        $this->assertStringContainsString('this.openerId', $html);
    }

    public function test_corrected_layout_does_not_expose_admin_actions_to_ordinary_users(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertOk()->assertDontSee('Catalogue moderation');
        $this->get(route('admin.catalogue.index'))->assertForbidden();
        $this->actingAs(User::factory()->administrator()->create())->get(route('dashboard'))
            ->assertOk()->assertSee('Catalogue moderation');
    }
}
