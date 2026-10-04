<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800 dark:text-slate-100">Create meal plan</h1></x-slot>
    <div class="planning-content py-8"><div class="mx-auto max-w-2xl sm:px-6 lg:px-8"><div class="rounded-lg bg-white p-3 sm:p-6 shadow dark:bg-slate-900">
        <form method="post" action="{{ route('meal-plans.store') }}">
            @include('meal-plans.partials.form', ['mealPlan' => new \App\Models\MealPlan])
        </form>
    </div></div></div>
</x-app-layout>
