<?php

namespace App\Audit\Enums;

enum AuditSubjectType: string
{
    case UserAccount = 'user_account';
    case CatalogueProposal = 'catalogue_proposal';
    case CatalogueItem = 'catalogue_item';
    case Recipe = 'recipe';
    case ManagedRecipeTerm = 'managed_recipe_term';
    case RecipeTagSuggestion = 'recipe_tag_suggestion';
    case NutritionOverride = 'nutrition_override';
    case PlanSnapshot = 'plan_snapshot';
    case SystemOperation = 'system_operation';
}
