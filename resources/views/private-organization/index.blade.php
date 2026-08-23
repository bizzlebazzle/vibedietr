<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">{{ $heading }}</h2></x-slot>
    <div class="py-12">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status')) <x-auth-session-status :status="session('status')" /> @endif
            <div>
                <h1 class="text-2xl font-semibold">{{ $heading }}</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-slate-400">{{ $description }}</p>
            </div>
            <form method="POST" action="{{ route($routePrefix.'.store') }}" class="rounded border bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                @csrf
                <label for="organization-name" class="block text-sm font-medium">New {{ $noun }} name</label>
                <div class="mt-2 flex gap-3">
                    <input id="organization-name" name="name" value="{{ old('name') }}" maxlength="100" required class="min-w-0 flex-1 rounded border-gray-300 dark:border-slate-600 dark:bg-slate-800">
                    <button class="rounded bg-blue-600 px-4 py-2 font-semibold text-white">Create</button>
                </div>
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </form>
            @if ($items->isEmpty())
                <p class="rounded border bg-white p-6 dark:border-slate-800 dark:bg-slate-900">No {{ $noun }}s yet.</p>
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($items as $item)
                        <a href="{{ route($routePrefix.'.show', $item) }}" class="rounded border bg-white p-5 font-semibold text-blue-700 hover:underline dark:border-slate-800 dark:bg-slate-900 dark:text-blue-300">{{ $item->name }}</a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
