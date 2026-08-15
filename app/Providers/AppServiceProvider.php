<?php

namespace App\Providers;

use App\Domain\Recipes\NullRecipeDraftSaveHook;
use App\Domain\Recipes\NullRecipeFinalizationHook;
use App\Domain\Recipes\RecipeDraftSaveHook;
use App\Domain\Recipes\RecipeFinalizationHook;
use App\Models\AuditEvent;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\User;
use App\Policies\AuditEventPolicy;
use App\Policies\IngredientPolicy;
use App\Policies\RecipePolicy;
use App\Queue\Reference\CacheReferenceTaskResultRecorder;
use App\Queue\Reference\ReferenceTaskResultRecorder;
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

        $this->app->bind(
            ReferenceTaskResultRecorder::class,
            CacheReferenceTaskResultRecorder::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(AuditEvent::class, AuditEventPolicy::class);
        Gate::policy(Ingredient::class, IngredientPolicy::class);
        Gate::policy(Recipe::class, RecipePolicy::class);
        Gate::define('access-admin', fn (User $user): bool => User::query()->whereKey($user->getKey())->where('is_administrator', true)->exists());
    }
}
