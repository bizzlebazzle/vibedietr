<?php

namespace App\Domain\NutritionTargets;

use App\Domain\Nutrition\NutrientDefinition;
use App\Domain\Nutrition\NutrientRegistry;
use App\Domain\Nutrition\NutrientUnitConverter;
use App\Domain\Shared\Decimal;
use App\Models\NutritionTargetProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class NutritionTargetProfileManager
{
    public function __construct(private readonly NutrientUnitConverter $converter) {}

    /** @param array<string, array<string, mixed>> $targets */
    public function create(User $owner, string $name, array $targets): NutritionTargetProfile
    {
        return DB::transaction(function () use ($owner, $name, $targets): NutritionTargetProfile {
            $profile = $owner->nutritionTargetProfiles()->create(['name' => trim($name)]);
            $this->replaceTargets($profile, $targets);

            return $profile;
        });
    }

    /** @param array<string, array<string, mixed>> $targets */
    public function update(NutritionTargetProfile $profile, string $name, array $targets): void
    {
        DB::transaction(function () use ($profile, $name, $targets): void {
            $profile->forceFill(['name' => trim($name)])->save();
            $profile->targets()->delete();
            $this->replaceTargets($profile, $targets);
        });
    }

    public function designateDefault(User $owner, NutritionTargetProfile $profile): void
    {
        DB::transaction(function () use ($owner, $profile): void {
            $profiles = $owner->nutritionTargetProfiles()->lockForUpdate()->get();
            $target = $profiles->firstWhere('id', $profile->getKey());

            if (! $target instanceof NutritionTargetProfile) {
                abort(404);
            }

            if ($target->is_default) {
                return;
            }

            $owner->nutritionTargetProfiles()->whereNotNull('is_default')->update(['is_default' => null]);
            $target->forceFill(['is_default' => true])->save();
        });
    }

    public function delete(NutritionTargetProfile $profile): void
    {
        DB::transaction(function () use ($profile): void {
            $locked = NutritionTargetProfile::query()->lockForUpdate()->findOrFail($profile->getKey());

            if ($locked->is_default) {
                throw ValidationException::withMessages([
                    'profile' => 'Choose another default profile before deleting this profile.',
                ]);
            }

            $locked->delete();
        });
    }

    /** @param array<string, array<string, mixed>> $targets */
    private function replaceTargets(NutritionTargetProfile $profile, array $targets): void
    {
        foreach (NutrientRegistry::all() as $definition) {
            $nutrient = $definition->id->value;
            $input = $targets[$nutrient] ?? [];
            $type = isset($input['type']) && is_string($input['type'])
                ? NutritionTargetType::tryFrom($input['type'])
                : null;

            if ($type === null) {
                continue;
            }

            $profile->targets()->create([
                'nutrient' => $nutrient,
                'type' => $type,
                'exact_value' => $type === NutritionTargetType::Exact
                    ? $this->canonicalValue($definition, $input['exact_value'])
                    : null,
                'minimum_value' => in_array($type, [NutritionTargetType::Minimum, NutritionTargetType::Range], true)
                    ? $this->canonicalValue($definition, $input['minimum_value'])
                    : null,
                'maximum_value' => in_array($type, [NutritionTargetType::Maximum, NutritionTargetType::Range], true)
                    ? $this->canonicalValue($definition, $input['maximum_value'])
                    : null,
            ]);
        }
    }

    private function canonicalValue(NutrientDefinition $definition, mixed $value): string
    {
        $displayValue = Decimal::parse((string) $value);
        $canonicalValue = $this->converter->convert(
            $displayValue,
            $definition->preferredDisplayUnit,
            $definition->canonicalStorageUnit,
        );

        return Decimal::forStorage($canonicalValue);
    }
}
