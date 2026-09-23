<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100">Nutrition targets</h2></x-slot>
    <div class="py-12"><div class="mx-auto max-w-3xl space-y-4 sm:px-6 lg:px-8">
        <div class="flex justify-end"><a href="{{ route('nutrition-target-profiles.create') }}" class="rounded bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Create target profile</a></div>
        @if ($errors->has('profile'))
            <div class="rounded border border-red-300 bg-red-50 p-4 text-sm text-red-700">{{ $errors->first('profile') }}</div>
        @endif
        @foreach ($profiles as $profile)
            <section class="rounded-lg bg-white p-6 shadow dark:bg-slate-900">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-slate-100">{{ $profile->name }}</h3>
                        @if ($profile->is_default)<span class="text-xs font-semibold uppercase text-indigo-700 dark:text-indigo-300">Default daily profile</span>@endif
                        <p class="mt-2 text-sm text-gray-600 dark:text-slate-300">{{ $profile->targets->count() }} nutrient {{ Str::plural('target', $profile->targets->count()) }}</p>
                    </div>
                    <a href="{{ route('nutrition-target-profiles.edit', $profile) }}" class="text-sm text-indigo-700 underline dark:text-indigo-300">Edit</a>
                </div>
                @unless ($profile->is_default)
                    <div class="mt-4 flex gap-3">
                        <form method="post" action="{{ route('nutrition-target-profiles.default', $profile) }}">@csrf<x-secondary-button>Make default</x-secondary-button></form>
                        <form method="post" action="{{ route('nutrition-target-profiles.destroy', $profile) }}">@csrf @method('delete')<x-danger-button>Delete</x-danger-button></form>
                    </div>
                @endunless
            </section>
        @endforeach
        <p class="text-sm text-gray-600 dark:text-slate-300">Target profiles are private and visible only to you. A blank nutrient has no target.</p>
    </div></div>
</x-app-layout>
