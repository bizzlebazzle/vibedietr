<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100">Edit nutrition target profile</h2></x-slot>
    <div class="py-12"><div class="mx-auto max-w-4xl sm:px-6 lg:px-8"><div class="rounded-lg bg-white p-6 shadow dark:bg-slate-900">
        <form method="post" action="{{ route('nutrition-target-profiles.update', $profile) }}">
            @include('nutrition-target-profiles.partials.form')
        </form>
    </div></div></div>
</x-app-layout>
