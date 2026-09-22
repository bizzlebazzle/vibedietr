<?php

use App\Http\Controllers\BookmarkController;
use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\CatalogueCorrectionProposalController;
use App\Http\Controllers\DiaryEntryController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\ManualCatalogueSubmissionController;
use App\Http\Controllers\MealPlanBookmarkController;
use App\Http\Controllers\MealPlanConsumptionController;
use App\Http\Controllers\MealPlanController;
use App\Http\Controllers\MealPlanDayController;
use App\Http\Controllers\MealPlanItemEntryController;
use App\Http\Controllers\MealPlanRecipeEntryController;
use App\Http\Controllers\MealPlanRecipeVersionReviewController;
use App\Http\Controllers\MealPlanShareController;
use App\Http\Controllers\MealPlanSlotController;
use App\Http\Controllers\MealPlanVisibilityController;
use App\Http\Controllers\PrivateRecipeTagController;
use App\Http\Controllers\PublicProfileController;
use App\Http\Controllers\PublicProfileSettingsController;
use App\Http\Controllers\RecipeCollectionController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\RecipeDiscoveryController;
use App\Http\Controllers\RecipeImportController;
use App\Http\Controllers\RecipeNutritionOverrideController;
use App\Http\Controllers\RecipeRemixController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::get('profiles/{publicProfile}', PublicProfileController::class)
    ->whereUlid('publicProfile')
    ->name('public-profiles.show');

Route::get('recipes', RecipeDiscoveryController::class)
    ->middleware('throttle:public-search')
    ->name('recipes.index');

Route::get('recipes/{recipe}', [RecipeController::class, 'show'])
    ->whereNumber('recipe')
    ->name('recipes.show');

Route::get('catalogue', [CatalogueController::class, 'index'])
    ->middleware('throttle:public-search')
    ->name('catalogue.index');

Route::get('catalogue/{catalogueItem}', [CatalogueController::class, 'show'])
    ->whereNumber('catalogueItem')
    ->name('catalogue.show');

Route::get('meal-plans/{mealPlan}', [MealPlanController::class, 'show'])
    ->whereNumber('mealPlan')->name('meal-plans.show');

Route::middleware(['auth'])->group(function () {
    Route::resource('meal-plans', MealPlanController::class)->only([
        'index', 'create', 'store', 'edit', 'update',
    ]);
    Route::post('meal-plans/{mealPlan}/shares', [MealPlanShareController::class, 'store'])
        ->middleware('throttle:sharing')->whereNumber('mealPlan')->name('meal-plans.shares.store');
    Route::delete('meal-plans/{mealPlan}/shares/{share}', [MealPlanShareController::class, 'destroy'])
        ->middleware('throttle:sharing')->whereNumber('mealPlan')->whereUlid('share')->name('meal-plans.shares.destroy');
    Route::post('meal-plans/{mealPlan}/public', [MealPlanVisibilityController::class, 'store'])
        ->middleware('throttle:sharing')->whereNumber('mealPlan')->name('meal-plans.public.store');
    Route::delete('meal-plans/{mealPlan}/public', [MealPlanVisibilityController::class, 'destroy'])
        ->middleware('throttle:sharing')->whereNumber('mealPlan')->name('meal-plans.public.destroy');
    Route::post('meal-plans/{mealPlan}/bookmark', [MealPlanBookmarkController::class, 'store'])
        ->middleware('throttle:sharing')->whereNumber('mealPlan')->name('meal-plans.bookmarks.store');
    Route::delete('meal-plan-bookmarks/{bookmark}', [MealPlanBookmarkController::class, 'destroy'])
        ->middleware('throttle:sharing')->whereNumber('bookmark')->name('meal-plans.bookmarks.destroy');
    Route::post('meal-plans/{mealPlan}/days', [MealPlanDayController::class, 'store'])
        ->whereNumber('mealPlan')->name('meal-plans.days.store');
    Route::post('meal-plans/{mealPlan}/days/{day}/slots', [MealPlanSlotController::class, 'store'])
        ->whereNumber(['mealPlan', 'day'])->name('meal-plans.days.slots.store');
    Route::patch('meal-plans/{mealPlan}/days/{day}/slots/{slot}', [MealPlanSlotController::class, 'update'])
        ->whereNumber(['mealPlan', 'day', 'slot'])->name('meal-plans.days.slots.update');
    Route::put('meal-plans/{mealPlan}/days/{day}/slots/order', [MealPlanSlotController::class, 'reorder'])
        ->whereNumber(['mealPlan', 'day'])->name('meal-plans.days.slots.reorder');
    Route::post('meal-plans/{mealPlan}/{entryType}-entries/{entry}/consumption', [MealPlanConsumptionController::class, 'store'])
        ->whereNumber(['mealPlan', 'entry'])->whereIn('entryType', ['recipe', 'item'])->name('meal-plans.consumption.store');
    Route::patch('meal-plans/{mealPlan}/{entryType}-entries/{entry}/consumption', [MealPlanConsumptionController::class, 'update'])
        ->whereNumber(['mealPlan', 'entry'])->whereIn('entryType', ['recipe', 'item'])->name('meal-plans.consumption.update');
    Route::delete('meal-plans/{mealPlan}/{entryType}-entries/{entry}/consumption', [MealPlanConsumptionController::class, 'destroy'])
        ->whereNumber(['mealPlan', 'entry'])->whereIn('entryType', ['recipe', 'item'])->name('meal-plans.consumption.destroy');
    Route::post('diary-entries', [DiaryEntryController::class, 'store'])->name('diary-entries.store');
    Route::post('diary-entries/{diaryEntry}/consumption', [DiaryEntryController::class, 'consume'])->whereNumber('diaryEntry')->name('diary-entries.consumption.store');
    Route::patch('diary-entries/{diaryEntry}/consumption', [DiaryEntryController::class, 'update'])->whereNumber('diaryEntry')->name('diary-entries.consumption.update');
    Route::delete('diary-entries/{diaryEntry}/consumption', [DiaryEntryController::class, 'destroy'])->whereNumber('diaryEntry')->name('diary-entries.consumption.destroy');
    Route::post('meal-plans/{mealPlan}/recipe-entries', [MealPlanRecipeEntryController::class, 'store'])
        ->whereNumber('mealPlan')->name('meal-plans.recipe-entries.store');
    Route::patch('meal-plans/{mealPlan}/recipe-entries/{entry}', [MealPlanRecipeEntryController::class, 'update'])
        ->whereNumber(['mealPlan', 'entry'])->name('meal-plans.recipe-entries.update');
    Route::delete('meal-plans/{mealPlan}/recipe-entries/{entry}', [MealPlanRecipeEntryController::class, 'destroy'])
        ->whereNumber(['mealPlan', 'entry'])->name('meal-plans.recipe-entries.destroy');
    Route::post('meal-plans/{mealPlan}/recipe-version-reviews/{review}/update', [MealPlanRecipeVersionReviewController::class, 'update'])
        ->whereNumber('mealPlan')->whereUlid('review')->name('meal-plans.recipe-version-reviews.update');
    Route::post('meal-plans/{mealPlan}/recipe-version-reviews/{review}/retain', [MealPlanRecipeVersionReviewController::class, 'retain'])
        ->whereNumber('mealPlan')->whereUlid('review')->name('meal-plans.recipe-version-reviews.retain');
    Route::post('meal-plans/{mealPlan}/item-entries', [MealPlanItemEntryController::class, 'store'])
        ->whereNumber('mealPlan')->name('meal-plans.item-entries.store');
    Route::patch('meal-plans/{mealPlan}/item-entries/{entry}', [MealPlanItemEntryController::class, 'update'])
        ->whereNumber(['mealPlan', 'entry'])->name('meal-plans.item-entries.update');
    Route::delete('meal-plans/{mealPlan}/item-entries/{entry}', [MealPlanItemEntryController::class, 'destroy'])
        ->whereNumber(['mealPlan', 'entry'])->name('meal-plans.item-entries.destroy');

    Route::get('catalogue/manual/create', [ManualCatalogueSubmissionController::class, 'create'])
        ->name('catalogue.manual.create');
    Route::post('catalogue/manual', [ManualCatalogueSubmissionController::class, 'store'])
        ->middleware('throttle:catalogue-submission')
        ->name('catalogue.manual.store');
    Route::get('catalogue/{catalogueItem}/corrections/create', [CatalogueCorrectionProposalController::class, 'create'])
        ->whereNumber('catalogueItem')
        ->name('catalogue.corrections.create');
    Route::post('catalogue/{catalogueItem}/corrections', [CatalogueCorrectionProposalController::class, 'store'])
        ->whereNumber('catalogueItem')->middleware('throttle:catalogue-submission')
        ->name('catalogue.corrections.store');
    Route::patch('profile/public-attribution', [PublicProfileSettingsController::class, 'update'])
        ->name('profile.public-attribution.update');
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
    Route::get('recipe-imports/create', [RecipeImportController::class, 'create'])->name('recipe-imports.create');
    Route::post('recipe-imports', [RecipeImportController::class, 'store'])->middleware('throttle:recipe-import')->name('recipe-imports.store');
    Route::post('recipe-imports/webpage', [RecipeImportController::class, 'storeWebpage'])->middleware('throttle:recipe-import')->name('recipe-imports.webpage.store');
    Route::post('recipe-imports/upload', [RecipeImportController::class, 'storeUpload'])->middleware('throttle:recipe-import')->name('recipe-imports.upload.store');
    Route::get('recipe-imports/{recipeImport}', [RecipeImportController::class, 'show'])->whereUlid('recipeImport')->name('recipe-imports.show');
    Route::post('recipe-imports/{recipeImport}/retry', [RecipeImportController::class, 'retry'])
        ->middleware('throttle:recipe-import')->whereUlid('recipeImport')->name('recipe-imports.retry');
    Route::put('recipes/{recipe}/nutrition-override', [RecipeNutritionOverrideController::class, 'update'])
        ->whereNumber('recipe')
        ->name('recipes.nutrition-override.update');
    Route::delete('recipes/{recipe}/nutrition-override', [RecipeNutritionOverrideController::class, 'destroy'])
        ->whereNumber('recipe')
        ->name('recipes.nutrition-override.destroy');
    Route::resource('recipes', RecipeController::class)->only(['create', 'edit']);
    Route::delete('recipes/{recipe}/revision', [RecipeController::class, 'abandonRevision'])
        ->name('recipes.revision.destroy');
    Route::patch('recipes/{recipe}/visibility', [RecipeController::class, 'updateVisibility'])
        ->middleware('throttle:sharing')
        ->name('recipes.visibility.update');
});

require __DIR__.'/catalogue-moderation.php';
require __DIR__.'/recipe-tags.php';
require __DIR__.'/auth.php';
