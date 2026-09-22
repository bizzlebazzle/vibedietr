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
    case NutritionCalculation = 'nutrition_calculation';
    case PlanSnapshot = 'plan_snapshot';
    case MealPlan = 'meal_plan';
    case DiaryConsumptionTransition = 'diary_consumption_transition';
    case SystemOperation = 'system_operation';
}
