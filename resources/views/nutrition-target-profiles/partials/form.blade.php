@csrf
@if ($profile->exists) @method('put') @endif
@php($existingTargets = $profile->exists ? $profile->targets->keyBy('nutrient') : collect())

<div>
    <x-input-label for="name" value="Profile name" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $profile->name)" required autofocus />
    <x-input-error class="mt-2" :messages="$errors->get('name')" />
</div>

<p class="mt-6 text-sm text-gray-600 dark:text-slate-300">Choose a target type only for nutrients you want to target. Values use the unit shown; zero is a value, while a blank type means no target.</p>

<div class="mt-4 space-y-5">
    @foreach ($nutrients as $nutrient)
        @php($target = $existingTargets->get($nutrient->id->value))
        @php($prefix = 'targets.'.$nutrient->id->value)
        <fieldset class="rounded border border-gray-200 p-4 dark:border-slate-700">
            <legend class="px-1 font-semibold text-gray-900 dark:text-slate-100">{{ $nutrient->label }} ({{ $nutrient->preferredDisplayUnit->symbol() }})</legend>
            <div class="grid gap-4 sm:grid-cols-4">
                <div>
                    <x-input-label :for="$prefix.'.type'" value="Target type" />
                    <select id="{{ $prefix }}.type" name="targets[{{ $nutrient->id->value }}][type]" class="mt-1 block w-full rounded-md border-gray-300 bg-white text-gray-900 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                        <option value="">No target</option>
                        @foreach (\App\Domain\NutritionTargets\NutritionTargetType::cases() as $type)
                            <option value="{{ $type->value }}" @selected(old($prefix.'.type', $target?->type?->value) === $type->value)>{{ ucfirst($type->value) }}</option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-2" :messages="$errors->get($prefix.'.type')" />
                </div>
                @foreach (['exact_value' => 'Exact', 'minimum_value' => 'Minimum', 'maximum_value' => 'Maximum'] as $field => $label)
                    <div>
                        <x-input-label :for="$prefix.'.'.$field" :value="$label" />
                        <x-text-input :id="$prefix.'.'.$field" name="targets[{{ $nutrient->id->value }}][{{ $field }}]" type="number" min="0" step="any" class="mt-1 block w-full" :value="old($prefix.'.'.$field, data_get($targetInputs, $nutrient->id->value.'.'.$field))" />
                        <x-input-error class="mt-2" :messages="$errors->get($prefix.'.'.$field)" />
                    </div>
                @endforeach
            </div>
        </fieldset>
    @endforeach
</div>

<div class="mt-6 flex items-center gap-4">
    <x-primary-button>{{ $profile->exists ? 'Save profile' : 'Create profile' }}</x-primary-button>
    <a href="{{ route('nutrition-target-profiles.index') }}" class="text-sm text-gray-600 underline dark:text-slate-300">Cancel</a>
</div>
