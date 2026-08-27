<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100">Import pasted recipe text</h2></x-slot>
    <div class="py-12"><div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('recipe-imports.store') }}" class="space-y-6 rounded-lg bg-white p-6 shadow dark:bg-slate-900">
            @csrf
            <div role="note" class="rounded border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-100">
                Your pasted source stays private and is preserved exactly. A local parser will create a private draft for review; it will never publish automatically.
            </div>
            <div>
                <x-input-label for="source_format" value="Pasted format" />
                <select id="source_format" name="source_format" class="mt-1 block w-full rounded-md border-gray-300 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                    <option value="plain_text" @selected(old('source_format', 'plain_text') === 'plain_text')>Plain text</option>
                    <option value="markdown" @selected(old('source_format') === 'markdown')>Markdown</option>
                    <option value="html" @selected(old('source_format') === 'html')>HTML (treated as inert text)</option>
                </select>
                <x-input-error :messages="$errors->get('source_format')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="source_text" value="Recipe source text" />
                <textarea id="source_text" name="source_text" rows="20" required class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">{{ old('source_text') }}</textarea>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Maximum 2 MiB. Input is never silently truncated.</p>
                <x-input-error :messages="$errors->get('source_text')" class="mt-2" />
            </div>
            <x-primary-button>Import as private draft</x-primary-button>
        </form>
    </div></div>
</x-app-layout>
