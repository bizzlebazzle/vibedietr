<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceInformationTest extends TestCase
{
    use RefreshDatabase;

    public function test_information_is_public_and_links_are_available_before_registration_and_from_profile(): void
    {
        foreach (['/', '/login', '/register', '/account-recovery'] as $path) {
            $this->get($path)->assertOk()->assertSee(route('service-information'), false);
        }

        $this->actingAs(User::factory()->create())->get('/profile')
            ->assertOk()->assertSee(route('service-information'), false);

        $this->get(route('service-information'))->assertOk()
            ->assertSee('Launch review is pending.')
            ->assertSee('installation-specific privacy notice and terms still require owner review')
            ->assertSee('There is currently no dedicated private webapp contact or reconsideration workflow.')
            ->assertSee('Calculated nutrition is an estimate.')
            ->assertSee('User-entered nutrition targets are not medical advice.')
            ->assertSee('final purge becomes due:')
            ->assertSee('installation-specific disposal and restore verification remain outstanding.')
            ->assertSee('XSRF-TOKEN');
    }

    public function test_key_pages_do_not_make_prohibited_compliance_or_accuracy_claims(): void
    {
        config(['catalogue.read_cutover' => true]);
        $paths = ['/', '/login', '/register', '/service-information', '/account-recovery', '/recipes', '/catalogue'];
        foreach ($paths as $path) {
            $response = $this->get($path)->assertOk();
            $text = html_entity_decode(strip_tags($response->getContent()));
            $this->assertDoesNotMatchRegularExpression(
                '/GDPR[ -]compliant|guarantee(?:s|d)? legal compliance|professionally (?:legally )?approved|professional legal approval|guaranteed (?:nutrition|accuracy)|100% accurate|every copy erased at day 30/i',
                $text,
                $path,
            );
        }

        $this->actingAs(User::factory()->create())->get('/profile')->assertOk()
            ->assertSee('the deadline is not proof that removal has finished.')
            ->assertDontSee('After the deadline, private recipes');
    }

    public function test_guest_responses_set_only_session_and_csrf_cookies_with_expected_security_attributes(): void
    {
        config(['session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax']);

        foreach (['/', '/login', '/register', '/service-information'] as $path) {
            $cookies = $this->get('https://localhost'.$path)->assertOk()->headers->getCookies();
            $this->assertEqualsCanonicalizing(
                [config('session.cookie'), 'XSRF-TOKEN'],
                array_map(fn ($cookie) => $cookie->getName(), $cookies),
            );
            foreach ($cookies as $cookie) {
                $this->assertTrue($cookie->isSecure());
                $this->assertSame('lax', $cookie->getSameSite());
                $this->assertSame($cookie->getName() !== 'XSRF-TOKEN', $cookie->isHttpOnly());
            }
        }
    }
}
