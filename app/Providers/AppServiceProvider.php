<?php

namespace App\Providers;

use App\Models\AuditEvent;
use App\Models\Ingredient;
use App\Models\User;
use App\Policies\AuditEventPolicy;
use App\Policies\IngredientPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(AuditEvent::class, AuditEventPolicy::class);
        Gate::policy(Ingredient::class, IngredientPolicy::class);
        Gate::define('access-admin', fn (User $user): bool => $user->isAdministrator());
    }
}
