<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="min-h-full">
<head>
    <script nonce="{{ Vite::cspNonce() }}">
        try {
            const theme = localStorage.getItem('theme');
            if (theme === 'dark' || (theme !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        } catch (_) {}
    </script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <meta name="description" content="VibeDietr helps you collect recipes, explore foods, and organise meal plans and nutrition estimates.">
    <title>VibeDietr — Recipes and meal planning</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    <x-skip-link />
    <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
        <header class="flex flex-wrap items-center justify-between gap-4 py-6">
            <a href="{{ url('/') }}" aria-label="VibeDietr home"><x-application-logo class="text-xl" /></a>
            <nav aria-label="Guest navigation" class="flex flex-wrap items-center gap-4 text-sm font-medium">
                <a class="hover:underline focus-visible:underline" href="{{ route('recipes.index') }}">Discover recipes</a>
                <a class="hover:underline focus-visible:underline" href="{{ route('catalogue.index') }}">Food catalogue</a>
                @auth
                    <a class="rounded-lg bg-sky-700 px-4 py-2 text-white hover:bg-sky-800" href="{{ route('dashboard') }}">Dashboard</a>
                @else
                    <a class="hover:underline focus-visible:underline" href="{{ route('login') }}">Log in</a>
                    @if (Route::has('register'))
                        <a class="rounded-lg bg-sky-700 px-4 py-2 text-white hover:bg-sky-800" href="{{ route('register') }}">Create account</a>
                    @endif
                @endauth
            </nav>
        </header>
        <main id="main-content" tabindex="-1">
            <section class="py-16 sm:py-24">
                <p class="font-semibold text-sky-700 dark:text-sky-300">Recipes, food and plans in one place</p>
                <h1 class="mt-4 max-w-3xl text-4xl font-bold tracking-tight sm:text-6xl">Make room for the meals you want to make.</h1>
                <p class="mt-6 max-w-2xl text-lg text-slate-700 dark:text-slate-300">Explore public recipes and the shared food catalogue. With an account, save your own recipes, organise meal plans and track what you consumed.</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a class="rounded-lg bg-sky-700 px-5 py-3 font-semibold text-white hover:bg-sky-800" href="{{ route('recipes.index') }}">Explore recipes</a>
                    <a class="rounded-lg border border-slate-300 px-5 py-3 font-semibold hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-900" href="{{ route('catalogue.index') }}">Browse foods</a>
                </div>
            </section>
            <section aria-label="What you can do" class="grid gap-5 border-t border-slate-200 py-12 dark:border-slate-800 sm:grid-cols-3">
                <div><h2 class="text-xl font-semibold">Keep recipes</h2><p class="mt-2 text-slate-700 dark:text-slate-300">Create or import recipes, then keep drafts private while you review them.</p></div>
                <div><h2 class="text-xl font-semibold">Plan meals</h2><p class="mt-2 text-slate-700 dark:text-slate-300">Build meal plans from recipes and foods, with private plans unless you choose to share them.</p></div>
                <div><h2 class="text-xl font-semibold">Understand nutrition</h2><p class="mt-2 text-slate-700 dark:text-slate-300">View source nutrition and labelled estimates where ingredient data is available.</p></div>
            </section>
        </main>
    </div>
</body>
</html>
