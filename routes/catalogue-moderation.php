<?php

use App\Http\Controllers\CatalogueCorrectionModerationController;
use App\Http\Controllers\CatalogueModerationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'can:moderate-catalogue'])->prefix('admin/catalogue')->name('admin.catalogue.')->group(function () {
    Route::get('/', [CatalogueModerationController::class, 'index'])->name('index');
    Route::get('submissions/{item}', [CatalogueModerationController::class, 'submission'])->whereNumber('item')->name('submission');
    Route::get('candidates/{candidate}', [CatalogueModerationController::class, 'candidate'])->whereNumber('candidate')->name('candidate');
    Route::get('decisions/{decision}', [CatalogueModerationController::class, 'decision'])->whereUlid('decision')->name('decision');
    Route::get('corrections/{proposal}', [CatalogueCorrectionModerationController::class, 'show'])->whereUlid('proposal')->name('correction');
    Route::post('submissions/{item}/approve', [CatalogueModerationController::class, 'approve'])->whereNumber('item')->name('approve');
    Route::post('submissions/{item}/reject', [CatalogueModerationController::class, 'reject'])->whereNumber('item')->name('reject');
    Route::post('candidates/{candidate}/distinct', [CatalogueModerationController::class, 'distinct'])->whereNumber('candidate')->name('distinct');
    Route::post('candidates/{candidate}/dismiss', [CatalogueModerationController::class, 'dismiss'])->whereNumber('candidate')->name('dismiss');
    Route::post('candidates/{candidate}/duplicate', [CatalogueModerationController::class, 'duplicate'])->whereNumber('candidate')->name('duplicate');
    Route::post('candidates/{candidate}/merge', [CatalogueModerationController::class, 'merge'])->whereNumber('candidate')->name('merge');
    Route::post('decisions/{decision}/correct', [CatalogueModerationController::class, 'correct'])->whereUlid('decision')->name('correct');
    Route::post('corrections/{proposal}/accept', [CatalogueCorrectionModerationController::class, 'accept'])->whereUlid('proposal')->name('corrections.accept');
    Route::post('corrections/{proposal}/reject', [CatalogueCorrectionModerationController::class, 'reject'])->whereUlid('proposal')->name('corrections.reject');
});
