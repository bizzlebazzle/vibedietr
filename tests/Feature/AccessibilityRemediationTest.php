<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
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

    public function test_first_and_last_pagination_controls_have_valid_disabled_semantics(): void
    {
        foreach (['pagination::tailwind' => 'link', 'livewire::tailwind' => 'button'] as $view => $role) {
            foreach ([1, 2] as $page) {
                $paginator = new LengthAwarePaginator(range(1, 25), 50, 25, $page, ['path' => '/catalogue']);
                $document = new DOMDocument;
                @$document->loadHTML($paginator->links($view)->toHtml());
                $xpath = new DOMXPath($document);
                $disabled = $xpath->query('//span[@aria-disabled="true" and @aria-label]');
                $this->assertSame(1, $disabled->length);
                $control = $disabled->item(0);
                $this->assertInstanceOf(DOMElement::class, $control);
                $this->assertSame($role, $control->getAttribute('role'));
                $this->assertStringContainsString($page === 1 ? 'Previous' : 'Next', $control->getAttribute('aria-label'));
                $this->assertFalse($control->hasAttribute('tabindex'));
                $this->assertSame(1, $xpath->query('//nav[@aria-label="Pagination Navigation"]')->length);
            }
        }
    }
}
