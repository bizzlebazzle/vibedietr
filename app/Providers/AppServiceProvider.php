<?php

namespace App\Providers;

use App\Models\AuditEvent;
use App\Models\Ingredient;
use App\Models\User;
use App\Policies\AuditEventPolicy;
use App\Policies\IngredientPolicy;
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
        Gate::define('access-admin', fn (User $user): bool => User::query()->whereKey($user->getKey())->where('is_administrator', true)->exists());
    }
}
