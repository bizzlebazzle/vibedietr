<?php

namespace App\Providers;

use App\Configuration\ProductionConfigurationValidator;
use App\Domain\Recipes\NullRecipeDraftSaveHook;
use App\Domain\Recipes\NullRecipeFinalizationHook;
use App\Domain\Recipes\NullRecipeRemixCreationHook;
use App\Domain\Recipes\RecipeDraftSaveHook;
use App\Domain\Recipes\RecipeFinalizationHook;
use App\Domain\Recipes\RecipeRemixCreationHook;
use App\Models\AuditEvent;
use App\Models\Bookmark;
use App\Models\Ingredient;
use App\Models\ManagedRecipeTerm;
use App\Models\ManagedRecipeTermSuggestion;
use App\Models\Recipe;
use App\Models\User;
use App\Policies\AuditEventPolicy;
use App\Policies\BookmarkPolicy;
use App\Policies\IngredientPolicy;
use App\Policies\ManagedRecipeTermPolicy;
use App\Policies\ManagedRecipeTermSuggestionPolicy;
use App\Policies\RecipePolicy;
use App\Queue\FailedJobPruner;
use App\Queue\PrivacyAwareFailedJobProvider;
use App\Queue\Reference\CacheReferenceTaskResultRecorder;
use App\Queue\Reference\ReferenceTaskResultRecorder;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Gate;
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

        Gate::policy(AuditEvent::class, AuditEventPolicy::class);
        Gate::policy(Bookmark::class, BookmarkPolicy::class);
        Gate::policy(Ingredient::class, IngredientPolicy::class);
        Gate::policy(ManagedRecipeTerm::class, ManagedRecipeTermPolicy::class);
        Gate::policy(ManagedRecipeTermSuggestion::class, ManagedRecipeTermSuggestionPolicy::class);
        Gate::policy(Recipe::class, RecipePolicy::class);
        Gate::define('access-admin', fn (User $user): bool => User::query()->whereKey($user->getKey())->where('is_administrator', true)->exists());
    }
}
