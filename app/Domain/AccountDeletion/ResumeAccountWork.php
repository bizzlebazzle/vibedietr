<?php

namespace App\Domain\AccountDeletion;

use App\Domain\RecipeImports\RecipeImportStatus;
use App\Domain\RecipeImports\RecipeImportType;
use App\Jobs\GenerateAccountExport;
use App\Jobs\ProcessPastedRecipeImport;
use App\Jobs\ProcessUploadedRecipeImport;
use App\Jobs\ProcessWebpageRecipeImport;
use App\Jobs\RecalculateRecipeNutrition;
use App\Models\AccountExport;
use App\Models\RecipeImport;
use App\Models\RecipeNutritionRecalculation;
use App\Models\User;

final class ResumeAccountWork
{
    public function dispatch(User $user): void
    {
        if (! $user->isActive()) {
            return;
        }
        foreach (RecipeImport::query()->where('user_id', $user->getKey())
            ->whereNotIn('status', [RecipeImportStatus::ReviewReady, RecipeImportStatus::Failed, RecipeImportStatus::Cancelled])->cursor() as $import) {
            match ($import->type) {
                RecipeImportType::PastedText => ProcessPastedRecipeImport::dispatch($import->id, $import->correlation_id),
                RecipeImportType::WebpageUrl => ProcessWebpageRecipeImport::dispatch($import->id, $import->correlation_id),
                RecipeImportType::UploadedText, RecipeImportType::UploadedImage => ProcessUploadedRecipeImport::dispatch($import->id, $import->correlation_id),
            };
        }
        foreach (AccountExport::query()->where('user_id', $user->getKey())->whereIn('state', ['queued', 'failed'])
            ->where('expires_at', '>', now()->utc())->cursor() as $export) {
            GenerateAccountExport::dispatch($export->getKey(), $export->correlation_id);
        }
        foreach (RecipeNutritionRecalculation::query()->whereHas('recipeVersion.recipe', fn ($recipe) => $recipe->where('user_id', $user->getKey()))
            ->whereIn('state', ['queued', 'processing'])->cursor() as $recalculation) {
            RecalculateRecipeNutrition::dispatch($recalculation->id, $recalculation->recipe_version_id, $recalculation->correlation_id);
        }
    }
}
