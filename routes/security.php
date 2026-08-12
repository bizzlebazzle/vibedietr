<?php

use App\Http\Controllers\Security\SecondFactorController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('security/two-step', [SecondFactorController::class, 'show'])->name('security.second-factor.show');
    Route::post('security/two-step', [SecondFactorController::class, 'begin'])->name('security.second-factor.begin');
    Route::post('security/two-step/confirm', [SecondFactorController::class, 'confirm'])->name('security.second-factor.confirm');
    Route::post('security/two-step/acknowledge', [SecondFactorController::class, 'acknowledge'])->name('security.second-factor.acknowledge');
    Route::post('security/two-step/verify', [SecondFactorController::class, 'verify'])->name('security.second-factor.verify');
    Route::delete('security/two-step', [SecondFactorController::class, 'cancel'])->name('security.second-factor.cancel');
});
