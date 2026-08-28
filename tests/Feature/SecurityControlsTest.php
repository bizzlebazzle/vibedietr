<?php

namespace Tests\Feature;

use App\Domain\Recipes\RecipeVisibility;
use App\Jobs\ProcessPastedRecipeImport;
use App\Models\Recipe;
use App\Models\User;
use App\Security\Limits\AbuseRateLimiter;
use App\Security\Limits\LimiterIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

class SecurityControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_and_authenticated_responses_receive_restrictive_security_headers(): void
    {
        $public = $this->get('/');
        $authenticated = $this->actingAs(User::factory()->create())->get('/dashboard');

        foreach ([$public, $authenticated] as $response) {
            $response->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('X-Frame-Options', 'DENY');
            $csp = (string) $response->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("default-src 'self'", $csp);
            $this->assertStringContainsString("object-src 'none'", $csp);
            $this->assertStringContainsString("frame-ancestors 'none'", $csp);
            $this->assertStringNotContainsString('script-src *', $csp);
            $this->assertStringNotContainsString("script-src 'unsafe-inline'", $csp);
            $this->assertMatchesRegularExpression("/script-src [^;]*'nonce-[^']+'/", $csp);
        }
    }

    public function test_csp_supports_local_assets_and_nonce_backed_inline_bootstrap(): void
    {
        $response = $this->get('/login')->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("style-src 'self'", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $response->assertSee('nonce=', false)->assertDontSee('fonts.bunny.net', false);
    }

    public function test_sensitive_authentication_and_import_pages_are_not_cacheable(): void
    {
        $this->get('/login')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');

        $this->actingAs(User::factory()->create())->get(route('recipe-imports.create'))
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_oversized_request_is_rejected_before_route_work_with_safe_413(): void
    {
        config(['security.requests.max_bytes' => 10]);
        $processed = false;
        Route::post('/_dep03/request', function () use (&$processed): string {
            $processed = true;

            return 'processed';
        });

        $this->call('POST', '/_dep03/request', [], [], [], [
            'CONTENT_LENGTH' => 11,
            'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(413)->assertExactJson(['message' => 'The request is too large.']);

        $this->assertFalse($processed);
        $this->call('POST', '/_dep03/request', [], [], [], ['CONTENT_LENGTH' => 10])->assertOk();
    }

    public function test_public_search_allows_normal_use_then_returns_429_without_query_in_limiter_identity(): void
    {
        config(['security.throttles.public_search.attempts' => 2]);
        $query = 'distinctive-private-search-content';

        $this->get(route('recipes.index', ['q' => $query]))->assertOk();
        $this->get(route('recipes.index', ['q' => $query]))->assertOk();
        $this->get(route('recipes.index', ['q' => $query]))->assertTooManyRequests();

        $identity = app(LimiterIdentity::class)->request(request());
        $this->assertStringNotContainsString($query, $identity);
    }

    public function test_import_default_allows_ten_per_user_and_rejects_the_eleventh(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $payload = ['source_format' => 'plain_text', 'source_text' => "Dish\nIngredients\n1 cup rice\nInstructions\nCook."];

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->actingAs($user)->post(route('recipe-imports.store'), $payload)->assertRedirect();
        }
        $this->actingAs($user)->post(route('recipe-imports.store'), $payload)->assertTooManyRequests();

        $this->assertDatabaseCount('recipe_imports', 10);
        Queue::assertPushed(ProcessPastedRecipeImport::class, 10);
    }

    public function test_import_configured_user_and_global_ceilings_are_enforced(): void
    {
        Queue::fake();
        config([
            'security.throttles.import_user.attempts' => 1,
            'security.throttles.import_global.attempts' => 2,
        ]);
        $payload = ['source_format' => 'plain_text', 'source_text' => "Dish\nIngredients\nRice\nInstructions\nCook."];
        $first = User::factory()->create();
        $second = User::factory()->create();
        $third = User::factory()->create();

        $this->actingAs($first)->post(route('recipe-imports.store'), $payload)->assertRedirect();
        $this->actingAs($first)->post(route('recipe-imports.store'), $payload)->assertTooManyRequests();
        $this->actingAs($second)->post(route('recipe-imports.store'), $payload)->assertRedirect();
        $this->actingAs($third)->post(route('recipe-imports.store'), $payload)->assertTooManyRequests();
    }

    public function test_sharing_and_security_writes_are_throttled_but_reads_are_not(): void
    {
        $visibility = Route::getRoutes()->getByName('recipes.visibility.update');
        $recipeRead = Route::getRoutes()->getByName('recipes.show');
        $securityWrite = Route::getRoutes()->getByName('security.second-factor.verify');
        $securityRead = Route::getRoutes()->getByName('security.second-factor.show');

        $this->assertContains('throttle:sharing', $visibility->gatherMiddleware());
        $this->assertNotContains('throttle:sharing', $recipeRead->gatherMiddleware());
        $this->assertContains('throttle:security-sensitive', $securityWrite->gatherMiddleware());
        $this->assertNotContains('throttle:security-sensitive', $securityRead->gatherMiddleware());
    }

    public function test_real_visibility_share_action_allows_normal_write_then_enforces_boundary(): void
    {
        config(['security.throttles.sharing.attempts' => 1]);
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->finalizedPublic()->create();

        $this->actingAs($owner)->patch(route('recipes.visibility.update', $recipe), [
            'visibility' => RecipeVisibility::Private->value,
        ])->assertRedirect(route('recipes.show', $recipe));
        $this->actingAs($owner)->patch(route('recipes.visibility.update', $recipe), [
            'visibility' => RecipeVisibility::Public->value,
        ])->assertTooManyRequests();
        $this->actingAs($owner)->get(route('recipes.show', $recipe))->assertOk();
    }

    public function test_reusable_auth_limiter_enforces_boundary_with_hashed_identity(): void
    {
        $request = request();
        $secret = 'synthetic-password@example.test';
        $identity = app(LimiterIdentity::class)->input($request, $secret);
        $limiter = app(AbuseRateLimiter::class);
        $limiter->consume('password_reset', $identity, 1, 60);

        $this->assertStringNotContainsString($secret, $identity);
        $this->expectException(TooManyRequestsHttpException::class);
        $limiter->consume('password_reset', $identity, 1, 60);
    }
}
