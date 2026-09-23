<?php

namespace Tests\Feature\NutritionTargets;

use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientRegistry;
use App\Domain\NutritionTargets\NutritionTargetType;
use App\Models\NutritionTargetProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class NutritionTargetProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_new_user_starts_with_one_blank_default_daily_profile(): void
    {
        $user = User::factory()->create();
        $profile = $user->nutritionTargetProfiles()->with('targets')->sole();

        $this->assertSame(NutritionTargetProfile::DEFAULT_NAME, $profile->name);
        $this->assertTrue($profile->is_default);
        $this->assertCount(0, $profile->targets);

        $this->expectException(QueryException::class);
        DB::table('nutrition_target_profiles')->insert([
            'user_id' => $user->id,
            'name' => 'Second default',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_owner_can_create_each_target_type_while_blank_nutrients_remain_absent(): void
    {
        $owner = User::factory()->create();
        $nutrients = NutrientRegistry::stableIdentifiers();
        $targets = $this->blankTargets();
        $targets[$nutrients[0]] = ['type' => 'exact', 'exact_value' => '2100'];
        $targets[$nutrients[1]] = ['type' => 'minimum', 'minimum_value' => '4.184'];
        $targets[$nutrients[2]] = ['type' => 'maximum', 'maximum_value' => '70.5'];
        $targets[$nutrients[3]] = ['type' => 'range', 'minimum_value' => '20', 'maximum_value' => '35'];

        $this->actingAs($owner)->post(route('nutrition-target-profiles.store'), [
            'name' => 'Training day',
            'targets' => $targets,
        ])->assertRedirect();

        $profile = $owner->nutritionTargetProfiles()->where('name', 'Training day')->with('targets')->sole();
        $stored = $profile->targets->keyBy('nutrient');

        $this->assertNull($profile->is_default);
        $this->assertCount(4, $stored);
        $this->assertSame(NutritionTargetType::Exact, $stored[$nutrients[0]]->type);
        $this->assertSame('2100.000000000000000000', $stored[$nutrients[0]]->exact_value);
        $this->assertSame(NutritionTargetType::Minimum, $stored[$nutrients[1]]->type);
        $this->assertSame('1.000000000000000000', $stored[$nutrients[1]]->minimum_value);
        $this->assertSame(NutritionTargetType::Maximum, $stored[$nutrients[2]]->type);
        $this->assertSame(NutritionTargetType::Range, $stored[$nutrients[3]]->type);
        $this->assertSame('20.000000000000000000', $stored[$nutrients[3]]->minimum_value);
        $this->assertSame('35.000000000000000000', $stored[$nutrients[3]]->maximum_value);
        $this->assertFalse($stored->has($nutrients[4]));
    }

    public function test_every_registered_nutrient_can_be_targeted_without_a_hard_coded_controller_list(): void
    {
        $owner = User::factory()->create();
        $targets = [];

        foreach (NutrientRegistry::stableIdentifiers() as $index => $nutrient) {
            $targets[$nutrient] = ['type' => 'exact', 'exact_value' => (string) ($index + 1)];
        }
        $targets[Nutrient::EnergyKj->value]['exact_value'] = '4.184';
        $targets[Nutrient::Sodium->value]['exact_value'] = '1000';

        $this->actingAs($owner)->post(route('nutrition-target-profiles.store'), [
            'name' => 'Complete targets',
            'targets' => $targets,
        ])->assertRedirect();

        $profile = $owner->nutritionTargetProfiles()->where('name', 'Complete targets')->sole();
        $stored = $profile->targets()->get()->keyBy('nutrient');
        $this->assertEqualsCanonicalizing(
            NutrientRegistry::stableIdentifiers(),
            $stored->pluck('nutrient')->all(),
        );
        $this->assertSame('1.000000000000000000', $stored[Nutrient::EnergyKj->value]->exact_value);
        $this->assertSame('1.000000000000000000', $stored[Nutrient::Sodium->value]->exact_value);
        $this->actingAs($owner)->get(route('nutrition-target-profiles.edit', $profile))->assertOk()->assertSee('1000');
    }

    public function test_invalid_ranges_and_fnd_06_numeric_constraints_are_rejected(): void
    {
        $owner = User::factory()->create();
        $nutrient = NutrientRegistry::stableIdentifiers()[0];
        $this->actingAs($owner);

        $this->post(route('nutrition-target-profiles.store'), [
            'name' => 'Backwards range',
            'targets' => [$nutrient => ['type' => 'range', 'minimum_value' => '2500', 'maximum_value' => '2000']],
        ])->assertSessionHasErrors("targets.$nutrient.maximum_value");

        $this->post(route('nutrition-target-profiles.store'), [
            'name' => 'Negative',
            'targets' => [$nutrient => ['type' => 'minimum', 'minimum_value' => '-1']],
        ])->assertSessionHasErrors("targets.$nutrient.minimum_value");

        $this->post(route('nutrition-target-profiles.store'), [
            'name' => 'Too large',
            'targets' => [$nutrient => ['type' => 'maximum', 'maximum_value' => '100000000000000000000']],
        ])->assertSessionHasErrors("targets.$nutrient.maximum_value");

        $this->assertSame(1, $owner->nutritionTargetProfiles()->count());
    }

    public function test_profile_update_can_clear_a_target_without_turning_it_into_zero(): void
    {
        $owner = User::factory()->create();
        $profile = NutritionTargetProfile::factory()->for($owner, 'owner')->create(['name' => 'Rest day']);
        $nutrient = NutrientRegistry::stableIdentifiers()[0];
        $profile->targets()->create(['nutrient' => $nutrient, 'type' => 'exact', 'exact_value' => '0']);

        $this->actingAs($owner)->put(route('nutrition-target-profiles.update', $profile), [
            'name' => 'Rest day',
            'targets' => [$nutrient => [
                'type' => '',
                'exact_value' => '',
                'minimum_value' => '',
                'maximum_value' => '',
            ]],
        ])->assertRedirect();

        $this->assertDatabaseMissing('nutrition_targets', ['nutrition_target_profile_id' => $profile->id]);
    }

    public function test_owner_can_switch_default_atomically_and_must_switch_before_deleting_it(): void
    {
        $owner = User::factory()->create();
        $original = $owner->nutritionTargetProfiles()->sole();
        $replacement = NutritionTargetProfile::factory()->for($owner, 'owner')->create(['name' => 'Training']);
        $this->actingAs($owner);

        $this->delete(route('nutrition-target-profiles.destroy', $original))
            ->assertSessionHasErrors('profile');

        $this->post(route('nutrition-target-profiles.default', $replacement))->assertRedirect();

        $this->assertSame(1, $owner->nutritionTargetProfiles()->whereNotNull('is_default')->count());
        $this->assertTrue($replacement->fresh()->is_default);
        $this->assertNull($original->fresh()->is_default);

        $this->delete(route('nutrition-target-profiles.destroy', $original))
            ->assertRedirect(route('nutrition-target-profiles.index'));
        $this->assertModelMissing($original);
        $this->assertTrue($replacement->fresh()->is_default);
    }

    public function test_profiles_are_private_and_cross_user_reads_and_mutations_are_hidden(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->administrator()->create();
        $profile = NutritionTargetProfile::factory()->for($owner, 'owner')->create(['name' => 'Owner secret']);
        $this->actingAs($other);

        $this->get(route('nutrition-target-profiles.index'))->assertOk()->assertDontSee('Owner secret');
        $this->get(route('nutrition-target-profiles.edit', $profile))->assertNotFound();
        $this->put(route('nutrition-target-profiles.update', $profile), [
            'name' => 'Forged',
            'targets' => $this->blankTargets(),
        ])->assertNotFound();
        $this->post(route('nutrition-target-profiles.default', $profile))->assertNotFound();
        $this->delete(route('nutrition-target-profiles.destroy', $profile))->assertNotFound();

        $this->assertFalse(Gate::forUser($other)->allows('view', $profile));
        $this->assertFalse(Gate::forUser($other)->allows('update', $profile));
        $this->assertSame('Owner secret', $profile->fresh()->name);
    }

    public function test_guest_cannot_access_target_profiles(): void
    {
        $profile = NutritionTargetProfile::factory()->create();

        $this->get(route('nutrition-target-profiles.index'))->assertRedirect(route('login'));
        $this->get(route('nutrition-target-profiles.edit', $profile))->assertRedirect(route('login'));
        $this->post(route('nutrition-target-profiles.store'), [])->assertRedirect(route('login'));
        $this->put(route('nutrition-target-profiles.update', $profile), [])->assertRedirect(route('login'));
        $this->post(route('nutrition-target-profiles.default', $profile))->assertRedirect(route('login'));
        $this->delete(route('nutrition-target-profiles.destroy', $profile))->assertRedirect(route('login'));
    }

    /** @return array<string, array{type: string}> */
    private function blankTargets(): array
    {
        return array_fill_keys(NutrientRegistry::stableIdentifiers(), ['type' => '']);
    }
}
