<?php

use App\Http\Controllers\Security\AdministratorLifecycleController;
use App\Http\Controllers\Security\SecondFactorController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('security/two-step', [SecondFactorController::class, 'show'])->name('security.second-factor.show');
    Route::get('security/administrator-lifecycle', [AdministratorLifecycleController::class, 'index'])->name('administrator.lifecycle.index');

    Route::middleware('throttle:security-sensitive')->group(function (): void {
        Route::post('security/two-step', [SecondFactorController::class, 'begin'])->name('security.second-factor.begin');
        Route::post('security/two-step/confirm', [SecondFactorController::class, 'confirm'])->name('security.second-factor.confirm');
        Route::post('security/two-step/acknowledge', [SecondFactorController::class, 'acknowledge'])->name('security.second-factor.acknowledge');
        Route::post('security/two-step/verify', [SecondFactorController::class, 'verify'])->name('security.second-factor.verify');
        Route::delete('security/two-step', [SecondFactorController::class, 'cancel'])->name('security.second-factor.cancel');
        Route::post('security/administrator-lifecycle/promotions', [AdministratorLifecycleController::class, 'initiate'])->name('administrator.lifecycle.promotions.initiate');
        Route::post('security/administrator-lifecycle/promotions/{promotion}/accept', [AdministratorLifecycleController::class, 'accept'])->name('administrator.lifecycle.promotions.accept');
        Route::post('security/administrator-lifecycle/promotions/{promotion}/decline', [AdministratorLifecycleController::class, 'decline'])->name('administrator.lifecycle.promotions.decline');
        Route::post('security/administrator-lifecycle/promotions/{promotion}/cancel', [AdministratorLifecycleController::class, 'cancel'])->name('administrator.lifecycle.promotions.cancel');
        Route::post('security/administrator-lifecycle/administrators/{user}/revoke', [AdministratorLifecycleController::class, 'revoke'])->name('administrator.lifecycle.revoke');
    });
});
