<?php

use App\Http\Controllers\BookmarkController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\PrivateRecipeTagController;
use App\Http\Controllers\RecipeCollectionController;
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
    Route::post('recipe-collections/{collection}/recipes', [RecipeCollectionController::class, 'storeRecipe'])
        ->whereNumber('collection')->name('recipe-collections.recipes.store');
    Route::delete('recipe-collections/{collection}/recipes/{recipe}', [RecipeCollectionController::class, 'destroyRecipe'])
        ->whereNumber(['collection', 'recipe'])->name('recipe-collections.recipes.destroy');
    Route::post('recipe-collections/{collection}/bookmarks', [RecipeCollectionController::class, 'storeBookmark'])
        ->whereNumber('collection')->name('recipe-collections.bookmarks.store');
    Route::delete('recipe-collections/{collection}/bookmarks/{bookmark}', [RecipeCollectionController::class, 'destroyBookmark'])
        ->whereNumber(['collection', 'bookmark'])->name('recipe-collections.bookmarks.destroy');
    Route::resource('recipe-collections', RecipeCollectionController::class)
        ->parameters(['recipe-collections' => 'collection'])
        ->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::post('private-recipe-tags/{tag}/recipes', [PrivateRecipeTagController::class, 'storeRecipe'])
        ->whereNumber('tag')->name('private-recipe-tags.recipes.store');
    Route::delete('private-recipe-tags/{tag}/recipes/{recipe}', [PrivateRecipeTagController::class, 'destroyRecipe'])
        ->whereNumber(['tag', 'recipe'])->name('private-recipe-tags.recipes.destroy');
    Route::post('private-recipe-tags/{tag}/bookmarks', [PrivateRecipeTagController::class, 'storeBookmark'])
        ->whereNumber('tag')->name('private-recipe-tags.bookmarks.store');
    Route::delete('private-recipe-tags/{tag}/bookmarks/{bookmark}', [PrivateRecipeTagController::class, 'destroyBookmark'])
        ->whereNumber(['tag', 'bookmark'])->name('private-recipe-tags.bookmarks.destroy');
    Route::resource('private-recipe-tags', PrivateRecipeTagController::class)
        ->parameters(['private-recipe-tags' => 'tag'])
        ->only(['index', 'store', 'show', 'update', 'destroy']);

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
