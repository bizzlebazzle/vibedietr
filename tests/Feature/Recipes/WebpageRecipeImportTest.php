<?php

namespace Tests\Feature\Recipes;

use App\Domain\RecipeImports\RecipeImportStatus;
use App\Domain\RecipeImports\RecipeImportType;
use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Integrations\RecipeWebpages\AddressResolver;
use App\Integrations\RecipeWebpages\ValidatedDestination;
use App\Integrations\RecipeWebpages\WebpageFetchException;
use App\Integrations\RecipeWebpages\WebpageResponse;
use App\Integrations\RecipeWebpages\WebpageTransport;
use App\Jobs\ProcessWebpageRecipeImport;
use App\Models\RecipeImport;
use App\Models\User;
use App\Queue\Exceptions\RetryableJobException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebpageRecipeImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_submits_private_url_import_with_identifier_only_job(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email' => 'private@example.test']);
        $url = 'https://recipes.example.test/cake?token=private-query-secret';

        $response = $this->actingAs($user)->withHeader('X-Correlation-ID', '01K1WEBIMPORTCORRELATION0000')
            ->post(route('recipe-imports.webpage.store'), ['source_url' => $url]);

        $import = RecipeImport::query()->sole();
        $response->assertRedirect(route('recipe-imports.show', $import));
        $this->assertSame($user->id, $import->user_id);
        $this->assertSame(RecipeImportType::WebpageUrl, $import->type);
        $this->assertSame($url, $import->submitted_url);
        $this->assertNull($import->source_text);
        Queue::assertPushed(ProcessWebpageRecipeImport::class, fn ($job): bool => $job->importId === $import->id && $job->afterCommit);

        $job = new ProcessWebpageRecipeImport($import->id, $import->correlation_id);
        $payload = serialize($job);
        $this->assertStringNotContainsString($url, $payload);
        $this->assertStringNotContainsString('private-query-secret', $payload);
        $this->assertStringNotContainsString($user->email, $payload);
    }

    public function test_guest_invalid_scheme_port_and_credentials_are_rejected_without_dispatch(): void
    {
        Queue::fake();
        $this->post(route('recipe-imports.webpage.store'), ['source_url' => 'https://example.com/recipe'])
            ->assertRedirect(route('login'));

        $user = User::factory()->create();
        foreach (['file:///etc/passwd', 'http://example.com:8080/a', 'https://user:pass@example.com/a'] as $url) {
            $this->actingAs($user)->post(route('recipe-imports.webpage.store'), ['source_url' => $url])
                ->assertSessionHasErrors('source_url');
        }
        $this->assertDatabaseCount('recipe_imports', 0);
        Queue::assertNothingPushed();
    }

    public function test_job_creates_one_private_source_attributed_draft_and_retry_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $import = RecipeImport::factory()->for($owner, 'owner')->webpage()->create([
            'submitted_url' => 'https://recipes.test/cake',
        ]);
        $html = file_get_contents(base_path('tests/Fixtures/RecipeImports/Webpages/complete-jsonld.html')) ?: '';
        $transport = new SequenceWebpageTransport([
            new WebpageResponse(200, ['content-type' => 'text/html'], $html),
        ]);
        $this->app->instance(AddressResolver::class, new StaticAddressResolver);
        $this->app->instance(WebpageTransport::class, $transport);

        $job = new ProcessWebpageRecipeImport($import->id, $import->correlation_id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $import->refresh();
        $recipe = $import->recipe()->firstOrFail();
        $this->assertSame(RecipeImportStatus::ReviewReady, $import->status);
        $this->assertSame(RecipeLifecycle::Draft, $recipe->lifecycle);
        $this->assertSame(RecipeVisibility::Private, $recipe->visibility);
        $this->assertSame($owner->id, $recipe->user_id);
        $this->assertSame('schema_jsonld', $import->extraction_method);
        $this->assertSame('https://recipes.test/cake', $import->final_url);
        $provenance = $import->provenance;
        $this->assertIsArray($provenance);
        $this->assertSame($import->submitted_url, $provenance['submitted_url']);
        $this->assertDatabaseCount('recipes', 1);
        $this->assertDatabaseCount('recipe_ingredient_lines', 2);
        $this->assertDatabaseCount('recipe_instruction_steps', 2);
        $this->assertCount(1, $transport->destinations);
        $this->get(route('recipes.show', $recipe))->assertNotFound();
    }

    public function test_visible_text_fallback_creates_a_private_reviewable_draft(): void
    {
        $owner = User::factory()->create();
        $import = RecipeImport::factory()->for($owner, 'owner')->webpage()->create([
            'submitted_url' => 'https://recipes.test/fallback',
        ]);
        $html = file_get_contents(base_path('tests/Fixtures/RecipeImports/Webpages/fallback.html')) ?: '';
        $this->app->instance(AddressResolver::class, new StaticAddressResolver);
        $this->app->instance(WebpageTransport::class, new SequenceWebpageTransport([
            new WebpageResponse(200, ['content-type' => 'text/html'], $html),
        ]));

        app()->call([(new ProcessWebpageRecipeImport($import->id, $import->correlation_id)), 'handle']);

        $import->refresh();
        $recipe = $import->recipe()->firstOrFail();
        $this->assertSame(RecipeImportStatus::ReviewReady, $import->status);
        $this->assertSame('visible_text_fallback', $import->extraction_method);
        $this->assertSame(RecipeLifecycle::Draft, $recipe->lifecycle);
        $this->assertSame(RecipeVisibility::Private, $recipe->visibility);
        $this->assertSame($owner->id, $recipe->user_id);
        $this->get(route('recipes.show', $recipe))->assertNotFound();
    }

    public function test_transient_timeout_retries_and_permanent_oversize_fails_without_a_draft(): void
    {
        $this->app->instance(AddressResolver::class, new StaticAddressResolver);
        $retryable = RecipeImport::factory()->webpage()->create([
            'submitted_url' => 'https://recipes.test/timeout',
        ]);
        $this->app->instance(WebpageTransport::class, new ExceptionalImportWebpageTransport(
            new WebpageFetchException('fetch_timeout', 'timeout', true),
        ));

        try {
            app()->call([(new ProcessWebpageRecipeImport(
                $retryable->id,
                $retryable->correlation_id,
            )), 'handle']);
            $this->fail('Transient timeout should be released to the queue retry policy.');
        } catch (RetryableJobException $exception) {
            $this->assertSame('fetch_timeout', $exception->safeErrorCode);
        }
        $this->assertSame(RecipeImportStatus::Fetching, $retryable->fresh()->status);
        $this->assertDatabaseCount('recipes', 0);

        $permanent = RecipeImport::factory()->webpage()->create([
            'submitted_url' => 'https://recipes.test/oversize',
        ]);
        $this->app->instance(WebpageTransport::class, new ExceptionalImportWebpageTransport(
            new WebpageFetchException('response_too_large', 'oversized'),
        ));
        $job = (new ProcessWebpageRecipeImport(
            $permanent->id,
            $permanent->correlation_id,
        ))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        $job->assertFailedWith(WebpageFetchException::class);
        $this->assertSame(RecipeImportStatus::Failed, $permanent->fresh()->status);
        $this->assertSame('response_too_large', $permanent->fresh()->failure_code);
        $this->assertDatabaseCount('recipes', 0);
    }

    public function test_another_user_cannot_view_import_source_or_draft(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $import = RecipeImport::factory()->for($owner, 'owner')->webpage()->withDraft()->create();

        $this->actingAs($other)->get(route('recipe-imports.show', $import))->assertForbidden();
        $this->actingAs($other)->get(route('recipes.edit', $import->recipe))->assertForbidden();
    }
}

final class StaticAddressResolver implements AddressResolver
{
    public function resolve(string $host): array
    {
        return ['93.184.216.34'];
    }
}

final class SequenceWebpageTransport implements WebpageTransport
{
    /** @var list<ValidatedDestination> */
    public array $destinations = [];

    /** @param list<WebpageResponse> $responses */
    public function __construct(private array $responses) {}

    public function request(ValidatedDestination $destination): WebpageResponse
    {
        $this->destinations[] = $destination;

        return array_shift($this->responses) ?? throw new \RuntimeException('Unexpected request.');
    }
}

final readonly class ExceptionalImportWebpageTransport implements WebpageTransport
{
    public function __construct(private WebpageFetchException $exception) {}

    public function request(ValidatedDestination $destination): WebpageResponse
    {
        throw $this->exception;
    }
}
