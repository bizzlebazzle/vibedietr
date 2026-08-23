<article class="space-y-8">
    <header>
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-slate-100">{{ $profile->attributionName }}</h1>
        <p class="mt-1 text-sm text-gray-600 dark:text-slate-400">Public recipe attribution profile</p>
    </header>

    @if ($profile->showsRecipes)
        <section aria-labelledby="public-profile-recipes">
            <h2 id="public-profile-recipes" class="text-xl font-semibold text-gray-900 dark:text-slate-100">Public recipes</h2>
            @include('public-profiles.partials.recipe-list', ['items' => $profile->recipes, 'emptyMessage' => 'No public recipes are listed.'])
        </section>
    @endif

    @if ($profile->showsRemixes)
        <section aria-labelledby="public-profile-remixes">
            <h2 id="public-profile-remixes" class="text-xl font-semibold text-gray-900 dark:text-slate-100">Public remixes</h2>
            @include('public-profiles.partials.recipe-list', ['items' => $profile->remixes, 'emptyMessage' => 'No public remixes are listed.'])
        </section>
    @endif

    @if (! $profile->showsRecipes && ! $profile->showsRemixes)
        <p class="rounded border border-gray-200 bg-white p-5 text-gray-700 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">This profile does not list recipes.</p>
    @endif
</article>
