<?php

use App\Http\Controllers\BookmarkController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\RecipeDiscoveryController;
use App\Http\Controllers\RecipeRemixController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::get('recipes', RecipeDiscoveryController::class)
    ->name('recipes.index');

Route::get('recipes/{recipe}', [RecipeController::class, 'show'])
    ->whereNumber('recipe')
    ->name('recipes.show');

Route::middleware(['auth'])->group(function () {
    Route::get('bookmarks', [BookmarkController::class, 'index'])->name('bookmarks.index');
    Route::post('recipes/{recipe}/bookmark', [BookmarkController::class, 'store'])
        ->whereNumber('recipe')
        ->name('bookmarks.store');
    Route::post('recipes/{recipe}/remix', [RecipeRemixController::class, 'store'])
        ->whereNumber('recipe')
        ->name('recipes.remix.store');
    Route::delete('bookmarks/{bookmark}', [BookmarkController::class, 'destroy'])
        ->whereNumber('bookmark')
        ->name('bookmarks.destroy');
    Route::resource('ingredients', IngredientController::class);
    Route::resource('recipes', RecipeController::class)->only(['create', 'edit']);
    Route::delete('recipes/{recipe}/revision', [RecipeController::class, 'abandonRevision'])
        ->name('recipes.revision.destroy');
    Route::patch('recipes/{recipe}/visibility', [RecipeController::class, 'updateVisibility'])
        ->name('recipes.visibility.update');
});

require __DIR__.'/auth.php';
