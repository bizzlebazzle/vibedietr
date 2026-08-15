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
            <section aria-labelledby="recipe-ingredients-heading">
                <h3 id="recipe-ingredients-heading" class="font-semibold">Ingredients</h3>
                @if ($recipe->ingredientLines->isEmpty())
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">No ingredient lines yet.</p>
                @else
                    <ol class="mt-2 list-decimal space-y-2 pl-5">
                        @foreach ($recipe->ingredientLines as $line)
                            <li class="whitespace-pre-wrap">{{ $line->original_text }}</li>
                        @endforeach
                    </ol>
                @endif
            </section>
            <section aria-labelledby="recipe-instructions-heading">
                <h3 id="recipe-instructions-heading" class="font-semibold">Instructions</h3>
                @if ($recipe->instructionSteps->isEmpty())
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">No instruction steps yet.</p>
                @else
                    <ol class="mt-2 space-y-3">
                        @foreach ($recipe->instructionSteps as $step)
                            <li class="flex gap-3">
                                <span class="font-medium text-gray-500 dark:text-gray-400">{{ $loop->iteration }}.</span>
                                <div>
                                    @if ($step->section !== null)
                                        <p class="text-sm font-semibold text-blue-700 dark:text-blue-300">{{ $step->section->name }}</p>
                                    @endif
                                    <p class="whitespace-pre-wrap">{{ $step->text }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
            <a href="{{ route('recipes.edit', $recipe) }}" class="inline-flex rounded bg-blue-600 px-4 py-2 text-white">Edit draft</a>
        </div>
    </div></div>
</x-app-layout>
