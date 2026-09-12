<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100">Meal plans</h2></x-slot>
    <div class="py-12"><div class="mx-auto max-w-3xl space-y-4 sm:px-6 lg:px-8">
        <div class="flex justify-end"><a href="{{ route('meal-plans.create') }}" class="rounded bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Create meal plan</a></div>
        <div class="rounded-lg bg-white p-6 shadow dark:bg-slate-900">
            @forelse ($mealPlans as $mealPlan)
                <a class="block py-2 text-indigo-700 underline dark:text-indigo-300" href="{{ route('meal-plans.show', $mealPlan) }}">{{ $mealPlan->name }}</a>
            @empty
                <p class="text-gray-600 dark:text-slate-300">You have no meal plans yet.</p>
            @endforelse
        </div>
    </div></div>
</x-app-layout>
