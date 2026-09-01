<?php

namespace App\Providers;

use App\Configuration\ProductionConfigurationValidator;
use App\Domain\RecipeImports\NullRecipeImportMaterializationHook;
use App\Domain\RecipeImports\Ocr\DisabledManagedOcrExtractor;
use App\Domain\RecipeImports\Ocr\GoogleDocumentAiOcrExtractor;
use App\Domain\RecipeImports\Ocr\ManagedOcrExtractor;
use App\Domain\RecipeImports\Ocr\OcrExtractor;
use App\Domain\RecipeImports\Ocr\TesseractOcrExtractor;
use App\Domain\RecipeImports\Parsing\DeterministicRecipeTextParser;
use App\Domain\RecipeImports\Parsing\RecipeTextParser;
use App\Domain\RecipeImports\RecipeImportMaterializationHook;
use App\Domain\Recipes\NullRecipeDraftSaveHook;
use App\Domain\Recipes\NullRecipeFinalizationHook;
use App\Domain\Recipes\NullRecipeRemixCreationHook;
use App\Domain\Recipes\RecipeDraftSaveHook;
use App\Domain\Recipes\RecipeFinalizationHook;
use App\Domain\Recipes\RecipeRemixCreationHook;
use App\Integrations\RecipeWebpages\AddressResolver;
use App\Integrations\RecipeWebpages\CurlWebpageTransport;
use App\Integrations\RecipeWebpages\NativeAddressResolver;
use App\Integrations\RecipeWebpages\WebpageTransport;
use App\Models\AuditEvent;
use App\Models\Bookmark;
use App\Models\CatalogueItem;
use App\Models\Ingredient;
use App\Models\ManagedRecipeTerm;
use App\Models\ManagedRecipeTermSuggestion;
use App\Models\Recipe;
use App\Models\RecipeImport;
use App\Models\User;
use App\Observability\Alerts\AlertSink;
use App\Observability\Alerts\LogAlertSink;
use App\Observability\CorrelationContext;
use App\Observability\Health\DependencyHealthProbe;
use App\Observability\Health\LaravelDependencyHealthProbe;
use App\Observability\Monitoring\QueueTelemetryListener;
use App\Policies\AuditEventPolicy;
use App\Policies\BookmarkPolicy;
use App\Policies\CatalogueItemPolicy;
use App\Policies\IngredientPolicy;
use App\Policies\ManagedRecipeTermPolicy;
use App\Policies\ManagedRecipeTermSuggestionPolicy;
use App\Policies\RecipeImportPolicy;
use App\Policies\RecipePolicy;
use App\Queue\FailedJobPruner;
use App\Queue\PrivacyAwareFailedJobProvider;
use App\Queue\Reference\CacheReferenceTaskResultRecorder;
use App\Queue\Reference\ReferenceTaskResultRecorder;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(RecipeDraftSaveHook::class, NullRecipeDraftSaveHook::class);
        $this->app->bind(RecipeFinalizationHook::class, NullRecipeFinalizationHook::class);
        $this->app->bind(RecipeRemixCreationHook::class, NullRecipeRemixCreationHook::class);
        $this->app->bind(RecipeImportMaterializationHook::class, NullRecipeImportMaterializationHook::class);
        $this->app->bind(RecipeTextParser::class, DeterministicRecipeTextParser::class);
        $this->app->bind(OcrExtractor::class, TesseractOcrExtractor::class);
        $this->app->bind(ManagedOcrExtractor::class, fn () => (bool) config('production.ocr.google.enabled')
            ? app(GoogleDocumentAiOcrExtractor::class)
            : app(DisabledManagedOcrExtractor::class));
        $this->app->bind(AddressResolver::class, NativeAddressResolver::class);
        $this->app->bind(WebpageTransport::class, CurlWebpageTransport::class);
        $this->app->scoped(CorrelationContext::class);
        $this->app->bind(DependencyHealthProbe::class, LaravelDependencyHealthProbe::class);
        $this->app->bind(AlertSink::class, LogAlertSink::class);

        $this->app->bind(
            ReferenceTaskResultRecorder::class,
            CacheReferenceTaskResultRecorder::class,
        );
        $this->app->extend('queue.failer', function (FailedJobProviderInterface $provider): FailedJobProviderInterface {
            return new PrivacyAwareFailedJobProvider(
                $provider,
                $this->app->make(FailedJobPruner::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        TrustProxies::at(config('production.trusted_proxies', []));
        TrustProxies::withHeaders((int) config('production.trusted_proxy_headers'));

        if ($this->app->environment('production') && ! $this->app->runningInConsole()) {
            $this->app->make(ProductionConfigurationValidator::class)->assertReady();
        }

        $listener = $this->app->make(QueueTelemetryListener::class);
        Queue::before($listener->processing(...));
        Queue::after($listener->processed(...));
        Queue::exceptionOccurred($listener->exception(...));
        Queue::failing($listener->failed(...));

        Gate::policy(AuditEvent::class, AuditEventPolicy::class);
        Gate::policy(Bookmark::class, BookmarkPolicy::class);
        Gate::policy(CatalogueItem::class, CatalogueItemPolicy::class);
        Gate::policy(Ingredient::class, IngredientPolicy::class);
        Gate::policy(ManagedRecipeTerm::class, ManagedRecipeTermPolicy::class);
        Gate::policy(ManagedRecipeTermSuggestion::class, ManagedRecipeTermSuggestionPolicy::class);
        Gate::policy(Recipe::class, RecipePolicy::class);
        Gate::policy(RecipeImport::class, RecipeImportPolicy::class);
        Gate::define('access-admin', fn (User $user): bool => User::query()->whereKey($user->getKey())->where('is_administrator', true)->exists());
    }
}
