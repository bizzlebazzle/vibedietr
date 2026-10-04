<?php

use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\MealPlanItemEntry;
use App\Models\MealPlanSlot;
use App\Models\Recipe;
use App\Models\RecipeImport;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.connections.mysql.database') !== 'testing') {
    throw new RuntimeException('Accessibility fixtures require the testing MySQL database.');
}

if (($argv[1] ?? '') === 'cleanup') {
    $fixture = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
    DB::transaction(function () use ($fixture): void {
        foreach ($fixture['users'] as $identity) {
            $user = User::query()->whereKey($identity['id'])->where('email', $identity['email'])
                ->where('email', 'like', 'ux07-%@example.test')->first();
            if ($user) {
                // Delete only this run's synthetic shared catalogue contributions.
                CatalogueItem::query()->where('submitted_by_user_id', $user->id)->delete();
                $user->delete();
            }
        }
    });
    exit;
}

$fixture = DB::transaction(function (): array {
    $users = [];
    foreach (['owner', 'reader', 'admin', 'deletion'] as $role) {
        $factory = User::factory()->withEnabledPublicProfile('Accessibility fixture');
        if ($role === 'admin') {
            $factory = $factory->administrator(); // Existing testing-only factory state.
        }
        $user = $factory->create([
            'name' => 'Accessibility '.$role,
            'email' => 'ux07-'.$role.'-'.Str::uuid().'@example.test',
            'password' => 'browser-fixture-password',
            'timezone' => 'UTC',
        ]);
        $users[$role] = ['id' => $user->id, 'email' => $user->email];
    }
    $owner = User::findOrFail($users['owner']['id']);
    $draft = Recipe::factory()->for($owner, 'owner')->validDraft()->create(['title' => 'Accessible draft']);
    $public = Recipe::factory()->for($owner, 'owner')->finalizedPublic()->create(['title' => 'Accessible public recipe']);
    $failedImport = RecipeImport::factory()->for($owner, 'owner')->failed()->create();
    $reviewImport = RecipeImport::factory()->for($owner, 'owner')->withDraft()->create();
    $items = [];
    foreach (['approved', 'pending', 'rejected'] as $state) {
        $item = CatalogueItem::factory()->submittedBy($owner)->create(['status' => $state]);
        CatalogueItemVersion::factory()->current()->completeNutrition()->create([
            'catalogue_item_id' => $item->id, 'name' => 'Accessibility '.$state.' food',
        ]);
        $items[$state] = $item->id;
    }
    $plan = MealPlan::factory()->for($owner, 'owner')->dated()->create([
        'name' => 'Accessible sharing plan', 'starts_on' => now()->toDateString(),
        'ends_on' => now()->addDays(2)->toDateString(),
    ]);
    $day = MealPlanDay::factory()->for($plan)->create(['date' => now()->toDateString(), 'day_index' => null]);
    $slot = MealPlanSlot::factory()->for($day, 'day')->create(['name' => 'Breakfast', 'position' => 0]);
    MealPlanItemEntry::factory()->for($slot, 'slot')->create(['one_off_wording' => 'Accessibility snack']);

    return ['users' => $users, 'draft' => $draft->id, 'publicRecipe' => $public->id,
        'items' => $items, 'plan' => $plan->id, 'profile' => $owner->publicProfile->id,
        'failedImport' => $failedImport->id, 'reviewImport' => $reviewImport->id];
});
echo json_encode($fixture, JSON_THROW_ON_ERROR);
