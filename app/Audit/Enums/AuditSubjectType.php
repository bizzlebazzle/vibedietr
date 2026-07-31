<?php

namespace App\Audit\Enums;

enum AuditSubjectType: string
{
    case UserAccount = 'user_account';
    case CatalogueProposal = 'catalogue_proposal';
    case CatalogueItem = 'catalogue_item';
    case Recipe = 'recipe';
    case NutritionOverride = 'nutrition_override';
    case PlanSnapshot = 'plan_snapshot';
    case SystemOperation = 'system_operation';
}
