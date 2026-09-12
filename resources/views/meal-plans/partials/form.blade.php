@csrf
@if ($mealPlan->exists)
    @method('patch')
@endif

<div>
    <x-input-label for="name" value="Plan name" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $mealPlan->name)" required autofocus />
    <x-input-error class="mt-2" :messages="$errors->get('name')" />
</div>

<div class="mt-4">
    <x-input-label for="type" value="Plan type" />
    <select id="type" name="type" class="mt-1 block w-full rounded-md border-gray-300 bg-white text-gray-900 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
        <option value="reusable" @selected(old('type', $mealPlan->type?->value) === 'reusable')>Reusable undated schedule</option>
        <option value="dated" @selected(old('type', $mealPlan->type?->value) === 'dated')>Dated plan</option>
    </select>
    <x-input-error class="mt-2" :messages="$errors->get('type')" />
</div>

<div class="mt-4 grid gap-4 sm:grid-cols-2">
    <div>
        <x-input-label for="starts_on" value="Start date (dated plans only)" />
        <x-text-input id="starts_on" name="starts_on" type="date" class="mt-1 block w-full" :value="old('starts_on', $mealPlan->starts_on?->format('Y-m-d'))" />
        <x-input-error class="mt-2" :messages="$errors->get('starts_on')" />
    </div>
    <div>
        <x-input-label for="ends_on" value="End date (dated plans only)" />
        <x-text-input id="ends_on" name="ends_on" type="date" class="mt-1 block w-full" :value="old('ends_on', $mealPlan->ends_on?->format('Y-m-d'))" />
        <x-input-error class="mt-2" :messages="$errors->get('ends_on')" />
    </div>
</div>

<p class="mt-4 text-sm text-gray-600 dark:text-slate-300">New meal plans are private and visible only to you.</p>

<div class="mt-6 flex items-center gap-4">
    <x-primary-button>{{ $mealPlan->exists ? 'Save plan' : 'Create plan' }}</x-primary-button>
    <a href="{{ $mealPlan->exists ? route('meal-plans.show', $mealPlan) : route('meal-plans.index') }}" class="text-sm text-gray-600 underline dark:text-slate-300">Cancel</a>
</div>
