<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100">{{ $mealPlan->name }}</h2></x-slot>
    <div class="py-12"><div class="mx-auto max-w-3xl sm:px-6 lg:px-8"><div class="rounded-lg bg-white p-6 shadow dark:bg-slate-900">
        <p class="text-gray-700 dark:text-slate-200">{{ $mealPlan->type === \App\Domain\MealPlans\MealPlanType::Reusable ? 'Reusable undated schedule' : 'Dated plan' }}</p>
        @if ($mealPlan->type === \App\Domain\MealPlans\MealPlanType::Dated)
            <p class="mt-2 text-gray-700 dark:text-slate-200">{{ $mealPlan->starts_on->toFormattedDateString() }} – {{ $mealPlan->ends_on->toFormattedDateString() }}</p>
        @endif
        <p class="mt-2 text-sm text-gray-600 dark:text-slate-300">Private</p>
        <a href="{{ route('meal-plans.edit', $mealPlan) }}" class="mt-6 inline-block text-indigo-700 underline dark:text-indigo-300">Edit meal plan</a>
    </div></div></div>
</x-app-layout>
