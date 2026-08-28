<?php

namespace App\Policies;

use App\Domain\RecipeImports\RecipeImportStatus;
use App\Domain\RecipeImports\RecipeImportType;
use App\Models\RecipeImport;
use App\Models\User;

class RecipeImportPolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, RecipeImport $import): bool
    {
        return $user->getKey() === $import->user_id;
    }

    public function retry(User $user, RecipeImport $import): bool
    {
        return $this->view($user, $import)
            && $import->status === RecipeImportStatus::Failed
            && in_array($import->type, [RecipeImportType::PastedText, RecipeImportType::WebpageUrl], true)
            && $import->manual_retry_count < 3;
    }
}
