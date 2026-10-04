<?php

use App\Domain\MealPlans\MealPlanItemEntryKind;
use App\Domain\Measurements\StandardUnit;
use App\Domain\NutritionTargets\MealPlanTargetPhaseManager;
use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\MealPlanItemEntry;
use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanSlot;
use App\Models\NutritionTargetProfile;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.connections.mysql.database') !== 'testing') {
    throw new RuntimeException('Browser fixtures require the testing MySQL database.');
}

if (($argv[1] ?? '') === 'cleanup') {
    // Only this run's synthetic owner; never truncate or reset a database.
    User::query()->whereKey($argv[2] ?? '')->where('email', $argv[3] ?? '')
        ->where('email', 'like', 'ux05-%@example.test')->delete();
    exit;
}

$result = DB::transaction(function (): array {
    $owner = User::factory()->create(['name' => 'Planning browser fixture', 'email' => 'ux05-'.Str::uuid().'@example.test', 'password' => 'browser-fixture-password', 'timezone' => 'UTC']);
    $date = now('UTC')->subDay()->toDateString();
    $plan = MealPlan::factory()->for($owner, 'owner')->dated()->create(['name' => 'Accessible planning', 'starts_on' => $date, 'ends_on' => now('UTC')->addDays(3)->toDateString()]);
    $day = MealPlanDay::factory()->for($plan)->create(['date' => $date, 'day_index' => null]);
    $breakfast = MealPlanSlot::factory()->for($day, 'day')->create(['name' => 'Breakfast', 'position' => 0]);
    $dinner = MealPlanSlot::factory()->for($day, 'day')->create(['name' => 'Dinner', 'position' => 1]);
    $recipe = MealPlanRecipeEntry::factory()->for($breakfast, 'slot')->create([
        'planned_servings' => '2.00', 'recipe_snapshot' => ['title' => 'Soup with a long descriptive title '.str_repeat('vegetable', 8)],
        'nutrition_snapshot' => ['source' => 'ingredient_estimate', 'values' => [
            'protein' => ['value' => '8', 'unit' => 'g', 'basis' => 'per_serving', 'status' => 'known', 'is_estimate' => true],
            'fat' => ['value' => '0', 'unit' => 'g', 'basis' => 'per_serving', 'status' => 'known', 'is_estimate' => true],
        ], 'provenance' => null, 'ingredient_estimate' => []],
    ]);
    $item = MealPlanItemEntry::factory()->for($breakfast, 'slot')->create(['one_off_wording' => 'Private snack', 'planned_amount' => '100', 'planned_unit' => StandardUnit::Gram]);
    $catalogue = MealPlanItemEntry::factory()->for($breakfast, 'slot')->create([
        'kind' => MealPlanItemEntryKind::Catalogue, 'one_off_wording' => null,
        'catalogue_item_id' => 123456, 'catalogue_item_version_id' => (string) Str::ulid(),
        'catalogue_nutrition_snapshot' => [],
        'catalogue_snapshot' => ['name' => 'Pinned catalogue food'], 'catalogue_item_version_number' => 1,
    ]);
    $profile = NutritionTargetProfile::factory()->for($owner, 'owner')->create(['name' => 'Personal targets']);
    $profile->targets()->create(['nutrient' => 'protein', 'type' => 'minimum', 'minimum_value' => '10']);
    app(MealPlanTargetPhaseManager::class)->create($owner, $plan, $profile, $date, $plan->ends_on->toDateString());

    return ['owner' => $owner->id, 'email' => $owner->email, 'plan' => $plan->id, 'day' => $day->id, 'recipe' => $recipe->id, 'item' => $item->id, 'catalogue' => $catalogue->id, 'breakfast' => $breakfast->id, 'dinner' => $dinner->id, 'profile' => $profile->id, 'date' => $date];
});
echo json_encode($result, JSON_THROW_ON_ERROR);
