<?php

namespace Tests\Recovery;

use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Explicit drill only: no RefreshDatabase, no seeding, no database destruction. */
class RestoredAccessTest extends TestCase
{
    public function test_restored_public_private_versions_shares_and_objects_keep_their_boundaries(): void
    {
        $this->assertSame('yes', getenv('RECOVERY_DRILL'));
        $this->assertStringStartsWith('dep06-', config('database.connections.mysql.host'));
        $owner = User::query()->where('email', 'owner@drill.example.test')->sole();
        $other = User::query()->where('email', 'other@drill.example.test')->sole();
        $admin = User::query()->where('email', 'administrator@drill.example.test')->sole();
        $public = Recipe::query()->where('title', 'Drill public')->sole();
        $private = Recipe::query()->where('title', 'Drill private')->sole();
        $plan = MealPlan::query()->where('name', 'Restored private plan')->sole();

        $this->get(route('recipes.show', $public))->assertOk()->assertSee('Restored public recipe')->assertSee('Original public ingredient')->assertDontSee($owner->email);
        $this->get(route('recipes.show', $private))->assertNotFound()->assertDontSee('Original private ingredient');
        $this->get(route('meal-plans.show', $plan))->assertNotFound();
        foreach ([$other, $admin] as $viewer) {
            $this->actingAs($viewer)->get(route('recipes.show', $public))->assertOk();
            $this->get(route('recipes.show', $private))->assertNotFound()->assertDontSee('Restored private recipe');
            $this->get(route('recipes.edit', $private))->assertForbidden();
        }
        $this->actingAs($owner)->get(route('recipes.show', $private))->assertOk()->assertSee('Original private ingredient');
        $this->get(route('meal-plans.show', $plan))->assertOk()->assertSee('Restored private plan');
        $this->actingAs($other)->get(route('meal-plans.show', $plan))->assertOk()->assertSee('Restored private plan');
        $this->actingAs($admin)->get(route('meal-plans.show', $plan))->assertNotFound();

        $this->assertSame(1, $private->versions()->count());
        $this->assertSame($private->id, $private->currentVersion()->sole()->recipe_id);
        $this->assertSame('Synthetic owner durable bytes', Storage::disk('s3')->get('durable/owner'));
        $this->assertSame('Synthetic other durable bytes', Storage::disk('s3')->get('durable/other'));
        foreach (['inputs/excluded', 'canonical/excluded', 'exports/excluded'] as $key) {
            $this->assertFalse(Storage::disk('s3')->exists($key));
        }
    }
}
