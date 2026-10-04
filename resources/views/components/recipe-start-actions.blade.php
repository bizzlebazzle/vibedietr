<div class="mt-3 flex flex-wrap gap-4 text-sm font-semibold text-blue-700 dark:text-blue-300">
    @can('create', \App\Models\Recipe::class)
        <a href="{{ route('recipes.create') }}" class="inline-flex min-h-11 items-center underline">Create a recipe</a>
    @endcan
    @can('create', \App\Models\RecipeImport::class)
        <a href="{{ route('recipe-imports.create') }}" class="inline-flex min-h-11 items-center underline">Import a recipe</a>
    @endcan
</div>
