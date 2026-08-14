<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100">{{ $recipe->title }}</h2>
    </x-slot>
    <div class="py-12"><div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
        <div class="space-y-4 rounded-lg bg-white p-6 shadow dark:bg-slate-900 dark:text-slate-100">
            @if (session('status'))
                <x-auth-session-status :status="session('status')" />
            @endif
            <p><strong>Lifecycle:</strong> Draft</p>
            <p><strong>Suggested servings:</strong> {{ $recipe->servings ?? 'Not supplied' }}</p>
            <p><strong>Intended visibility:</strong> {{ ucfirst($recipe->visibility->value) }}</p>
            <p class="text-sm text-gray-600 dark:text-gray-400">This draft is private regardless of its intended visibility.</p>
            <a href="{{ route('recipes.edit', $recipe) }}" class="inline-flex rounded bg-blue-600 px-4 py-2 text-white">Edit draft</a>
        </div>
    </div></div>
</x-app-layout>
