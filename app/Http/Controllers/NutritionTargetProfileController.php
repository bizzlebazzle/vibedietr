<?php

namespace App\Http\Controllers;

use App\Domain\Nutrition\NutrientRegistry;
use App\Domain\Nutrition\NutrientUnitConverter;
use App\Domain\NutritionTargets\NutritionTargetProfileManager;
use App\Domain\Shared\Decimal;
use App\Http\Requests\NutritionTargetProfileRequest;
use App\Models\NutritionTarget;
use App\Models\NutritionTargetProfile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NutritionTargetProfileController extends Controller
{
    public function __construct(private readonly NutrientUnitConverter $converter) {}

    public function index(Request $request): View
    {
        $owner = $this->owner($request);
        $this->authorize('viewAny', NutritionTargetProfile::class);
        $profiles = $owner->nutritionTargetProfiles()->with('targets')->orderByDesc('is_default')->orderBy('name')->get();

        return view('nutrition-target-profiles.index', compact('profiles'));
    }

    public function create(Request $request): View
    {
        $this->owner($request);
        $this->authorize('create', NutritionTargetProfile::class);

        return view('nutrition-target-profiles.create', [
            'nutrients' => NutrientRegistry::all(),
            'targetInputs' => [],
        ]);
    }

    public function store(NutritionTargetProfileRequest $request, NutritionTargetProfileManager $manager): RedirectResponse
    {
        $owner = $this->owner($request);
        $this->authorize('create', NutritionTargetProfile::class);
        $validated = $request->validated();
        $profile = $manager->create($owner, $validated['name'], $validated['targets']);

        return redirect()->route('nutrition-target-profiles.edit', $profile)->with('status', 'Target profile created.');
    }

    public function edit(Request $request, int $profile): View
    {
        $profile = $this->profile($this->owner($request), $profile);
        $this->authorize('view', $profile);
        $profile->load('targets');

        return view('nutrition-target-profiles.edit', [
            'profile' => $profile,
            'nutrients' => NutrientRegistry::all(),
            'targetInputs' => $profile->targets->mapWithKeys(function (NutritionTarget $target): array {
                $definition = NutrientRegistry::find($target->nutrient);
                abort_if($definition === null, 500);

                $values = [];
                foreach (['exact_value', 'minimum_value', 'maximum_value'] as $field) {
                    if ($target->{$field} === null) {
                        continue;
                    }

                    $values[$field] = (string) $this->converter->convert(
                        Decimal::parse((string) $target->{$field}),
                        $definition->canonicalStorageUnit,
                        $definition->preferredDisplayUnit,
                    );
                }

                return [$target->nutrient => $values];
            })->all(),
        ]);
    }

    public function update(NutritionTargetProfileRequest $request, int $profile, NutritionTargetProfileManager $manager): RedirectResponse
    {
        $profile = $this->profile($this->owner($request), $profile);
        $this->authorize('update', $profile);
        $validated = $request->validated();
        $manager->update($profile, $validated['name'], $validated['targets']);

        return back()->with('status', 'Target profile updated.');
    }

    public function destroy(Request $request, int $profile, NutritionTargetProfileManager $manager): RedirectResponse
    {
        $profile = $this->profile($this->owner($request), $profile);
        $this->authorize('delete', $profile);
        $manager->delete($profile);

        return redirect()->route('nutrition-target-profiles.index')->with('status', 'Target profile deleted.');
    }

    public function designateDefault(Request $request, int $profile, NutritionTargetProfileManager $manager): RedirectResponse
    {
        $owner = $this->owner($request);
        $profile = $this->profile($owner, $profile);
        $this->authorize('designateDefault', $profile);
        $manager->designateDefault($owner, $profile);

        return back()->with('status', 'Default target profile changed.');
    }

    private function owner(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function profile(User $owner, int $id): NutritionTargetProfile
    {
        return $owner->nutritionTargetProfiles()->findOrFail($id);
    }
}
