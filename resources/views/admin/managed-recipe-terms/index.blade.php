<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight">Recipe classifications</h2>
    </x-slot>

    <div class="mx-auto max-w-5xl space-y-6 p-6">
        @if (session('status'))
            <x-auth-session-status :status="session('status')" />
        @endif

        @if ($errors->any())
            <div role="alert" class="rounded border border-red-300 bg-red-50 p-4 text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="rounded-lg bg-white p-6 shadow dark:bg-slate-900">
            <h3 class="text-lg font-semibold">Add a controlled term</h3>
            <form method="POST" action="{{ route('admin.managed-recipe-terms.store') }}" class="mt-4 grid gap-4 sm:grid-cols-[12rem_1fr_auto] sm:items-end">
                @csrf
                <div>
                    <label for="category" class="block text-sm font-medium">Category</label>
                    <select id="category" name="category" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-800">
                        @foreach ($categories as $category)
                            <option value="{{ $category->value }}">{{ $category->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="name" class="block text-sm font-medium">Name</label>
                    <input id="name" name="name" maxlength="100" required class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-800">
                </div>
                <button class="rounded bg-blue-600 px-4 py-2 font-semibold text-white">Create term</button>
            </form>
        </section>

        <section class="rounded-lg bg-white p-6 shadow dark:bg-slate-900">
            <h3 class="text-lg font-semibold">Suggest a classification</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">This sends a pending suggestion. The recipe creator must accept it before it becomes public metadata.</p>
            <form method="POST" action="{{ route('admin.managed-recipe-term-suggestions.store') }}" class="mt-4 grid gap-4 sm:grid-cols-[10rem_1fr_auto] sm:items-end">
                @csrf
                <div>
                    <label for="recipe_id" class="block text-sm font-medium">Recipe ID</label>
                    <input id="recipe_id" name="recipe_id" type="number" min="1" required class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-800">
                </div>
                <div>
                    <label for="managed_term_id" class="block text-sm font-medium">Active term</label>
                    <select id="managed_term_id" name="managed_term_id" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-800">
                        @foreach ($terms->where('is_active', true) as $term)
                            <option value="{{ $term->id }}">{{ $term->category->label() }} — {{ $term->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded bg-blue-600 px-4 py-2 font-semibold text-white">Send suggestion</button>
            </form>
        </section>

        @foreach ($categories as $category)
            <section class="rounded-lg bg-white p-6 shadow dark:bg-slate-900">
                <h3 class="text-lg font-semibold">{{ $category->label() }}</h3>
                <div class="mt-4 space-y-3">
                    @forelse ($terms->where('category', $category) as $term)
                        <form method="POST" action="{{ route('admin.managed-recipe-terms.update', $term) }}" class="grid gap-3 rounded border p-3 dark:border-slate-700 sm:grid-cols-[1fr_auto_auto] sm:items-end">
                            @csrf
                            @method('PATCH')
                            <div>
                                <label for="term-{{ $term->id }}" class="block text-sm font-medium">Term name</label>
                                <input id="term-{{ $term->id }}" name="name" value="{{ $term->name }}" maxlength="100" required class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-800">
                            </div>
                            <label class="flex items-center gap-2 pb-2 text-sm">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" name="is_active" value="1" @checked($term->is_active)>
                                Active
                            </label>
                            <button class="rounded border px-4 py-2 font-semibold dark:border-slate-600">Save</button>
                        </form>
                    @empty
                        <p class="text-sm text-gray-600 dark:text-gray-400">No terms in this category.</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
</x-app-layout>
