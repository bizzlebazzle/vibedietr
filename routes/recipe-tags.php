<?php

use App\Http\Controllers\ManagedRecipeTermController;
use App\Http\Controllers\ManagedRecipeTermSuggestionController;
use App\Http\Controllers\PublicRecipeMetadataController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:access-admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('managed-recipe-terms', [ManagedRecipeTermController::class, 'index'])->name('managed-recipe-terms.index');
    Route::post('managed-recipe-terms', [ManagedRecipeTermController::class, 'store'])->name('managed-recipe-terms.store');
    Route::patch('managed-recipe-terms/{term}', [ManagedRecipeTermController::class, 'update'])->name('managed-recipe-terms.update');
    Route::post('managed-recipe-term-suggestions', [ManagedRecipeTermSuggestionController::class, 'store'])->name('managed-recipe-term-suggestions.store');
});

Route::middleware('auth')->group(function () {
    Route::post('recipes/{recipe}/public-tags', [PublicRecipeMetadataController::class, 'storeTag'])->whereNumber('recipe')->name('recipes.public-tags.store');
    Route::delete('recipes/{recipe}/public-tags/{tag}', [PublicRecipeMetadataController::class, 'destroyTag'])->whereNumber(['recipe', 'tag'])->name('recipes.public-tags.destroy');
    Route::post('recipes/{recipe}/managed-classifications', [PublicRecipeMetadataController::class, 'storeClassification'])->whereNumber('recipe')->name('recipes.managed-classifications.store');
    Route::delete('recipes/{recipe}/managed-classifications/{term}', [PublicRecipeMetadataController::class, 'destroyClassification'])->whereNumber('recipe')->name('recipes.managed-classifications.destroy');
    Route::post('managed-recipe-term-suggestions/{suggestion}/accept', [ManagedRecipeTermSuggestionController::class, 'accept'])->name('managed-recipe-term-suggestions.accept');
    Route::post('managed-recipe-term-suggestions/{suggestion}/reject', [ManagedRecipeTermSuggestionController::class, 'reject'])->name('managed-recipe-term-suggestions.reject');
});
