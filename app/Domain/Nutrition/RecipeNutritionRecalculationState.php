<?php

namespace App\Domain\Nutrition;

enum RecipeNutritionRecalculationState: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
