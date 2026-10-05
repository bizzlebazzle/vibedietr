<?php

// Synthetic-only bootstrap. Invoked exclusively inside the isolated drill containers.
use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanShare;
use App\Models\MealPlanSlot;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('RECOVERY_DRILL') !== 'yes' || ! str_starts_with((string) config('database.connections.mysql.host'), 'dep06-')) {
    throw new RuntimeException('Synthetic isolated drill environment required');
}
if (User::query()->exists()) {
    throw new RuntimeException('Drill source must be empty');
}
$owner = User::factory()->create(['email' => 'owner@drill.example.test']);
$other = User::factory()->create(['email' => 'other@drill.example.test']);
User::factory()->create(['email' => 'administrator@drill.example.test', 'is_administrator' => true]);
foreach (['public', 'private'] as $visibility) {
    $recipe = Recipe::factory()->for($owner, 'owner')->create(['title' => 'Drill '.$visibility, 'visibility' => $visibility, 'lifecycle' => 'finalized', 'servings' => '2.00', 'finalized_at' => now()]);
    $version = RecipeVersion::factory()->for($recipe)->create(['visibility' => $visibility, 'snapshot' => [
        'title' => 'Restored '.$visibility.' recipe', 'servings' => '2.00', 'visibility' => $visibility,
        'ingredients' => [['position' => 0, 'original_text' => 'Original '.$visibility.' ingredient', 'quantity' => null, 'standard_unit' => null, 'custom_unit' => null, 'generic_wording' => null, 'notes' => null]],
        'steps' => [['position' => 0, 'text' => 'Original instruction', 'section_key' => null]], 'sections' => [],
    ]]);
    $recipe->forceFill(['current_recipe_version_id' => $version->id])->save();
}
$private = Recipe::query()->where('title', 'Drill private')->sole();
$plan = MealPlan::factory()->for($owner, 'owner')->create(['name' => 'Restored private plan']);
$day = MealPlanDay::factory()->for($plan)->create();
$slot = MealPlanSlot::factory()->for($day, 'day')->create();
MealPlanRecipeEntry::factory()->for($slot, 'slot')->create([
    'recipe_id' => $private->id, 'recipe_version_id' => $private->current_recipe_version_id,
    'recipe_snapshot' => $private->currentVersion()->sole()->snapshot,
]);
MealPlanShare::factory()->for($plan)->for($other, 'recipient')->acknowledged()->create();
$ownership = [];
foreach (['owner', 'other'] as $name) {
    $key = 'durable/'.$name;
    Storage::disk('s3')->put($key, 'Synthetic '.$name.' durable bytes');
    $ownership[$key] = ['owner_generation' => 'synthetic-generation-'.$name, 'resource' => 'synthetic-durable-object'];
}
Storage::disk('s3')->put('inputs/excluded', 'Synthetic transient input');
Storage::disk('s3')->put('canonical/excluded', 'Synthetic canonical image');
Storage::disk('s3')->put('exports/excluded', 'Synthetic export');
file_put_contents('/drill/ownership.json', json_encode($ownership, JSON_THROW_ON_ERROR));
echo "Synthetic public/private/version/share/object fixtures created.\n";
