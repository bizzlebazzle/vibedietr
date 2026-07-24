<?php

namespace App\Providers;

use App\Models\Ingredient;
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
        Gate::policy(Ingredient::class, IngredientPolicy::class);
    }
}
