@auth
    <x-app-layout>
        <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100">{{ $mealPlan->name }}</h2></x-slot>
        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                @include('meal-plans.partials.read-only-plan')
            </div>
        </div>
    </x-app-layout>
@else
    <x-guest-layout>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">{{ $mealPlan->name }}</h1>
        <div class="mt-6">
            @include('meal-plans.partials.read-only-plan')
        </div>
    </x-guest-layout>
@endauth
