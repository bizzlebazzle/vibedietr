<?php

namespace App\Domain\Recipes;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Models\ManagedRecipeTerm;
use App\Models\ManagedRecipeTermSuggestion;
use App\Models\User;

final class ManagedRecipeVocabularyAudit
{
    public function __construct(private readonly AuditEventRecorder $recorder) {}

    public function vocabularyChanged(ManagedRecipeTerm $term, User $administrator, string $action): void
    {
        $this->recorder->record(
            AuditAction::ManagedRecipeVocabularyChanged,
            AuditActor::administrator($administrator),
            AuditSubject::resource(AuditSubjectType::ManagedRecipeTerm, (string) $term->getKey()),
            ['action' => $action, 'category' => $term->category->value, 'outcome' => 'completed'],
        );
    }

    public function suggestionReviewed(
        ManagedRecipeTermSuggestion $suggestion,
        User $creator,
        string $action,
    ): void {
        $this->recorder->record(
            AuditAction::RecipeTagSuggestionReviewed,
            AuditActor::authenticatedUser($creator),
            AuditSubject::resource(AuditSubjectType::RecipeTagSuggestion, (string) $suggestion->getKey()),
            [
                'action' => $action,
                'managed_term_id' => (string) $suggestion->managed_recipe_term_id,
                'outcome' => 'completed',
                'recipe_id' => (int) $suggestion->recipe_id,
            ],
        );
    }
}
