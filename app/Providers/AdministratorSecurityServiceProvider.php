<?php

namespace App\Providers;

use App\Http\Middleware\RequirePrivilegedAuthentication;
use App\Models\User;
use App\Security\Notifications\LaravelMailSecurityNotificationTransport;
use App\Security\Notifications\SecurityNotificationTransport;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AdministratorSecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SecurityNotificationTransport::class, LaravelMailSecurityNotificationTransport::class);
    }

    public function boot(): void
    {
        Route::aliasMiddleware('privileged-auth', RequirePrivilegedAuthentication::class);
        Route::middleware('web')->group(base_path('routes/security.php'));

        User::resolveRelationUsing('secondFactor', fn (User $user) => $user->hasOne(\App\Models\SecondFactor::class));
        User::resolveRelationUsing('secondFactorEnrollment', fn (User $user) => $user->hasOne(\App\Models\SecondFactorEnrollment::class));
    }
}
