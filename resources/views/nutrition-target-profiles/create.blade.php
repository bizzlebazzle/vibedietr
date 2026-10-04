<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800 dark:text-slate-100">Create nutrition target profile</h1></x-slot>
    <div class="planning-content py-8"><div class="mx-auto max-w-4xl sm:px-6 lg:px-8"><div class="rounded-lg bg-white p-3 sm:p-6 shadow dark:bg-slate-900">
        <form method="post" action="{{ route('nutrition-target-profiles.store') }}">
            @include('nutrition-target-profiles.partials.form', ['profile' => new \App\Models\NutritionTargetProfile])
        </form>
    </div></div></div>
</x-app-layout>
