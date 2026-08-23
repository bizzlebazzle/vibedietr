<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<nav x-data="{ open: false }" class="border-b border-gray-200 bg-white/95 text-gray-900 dark:border-slate-800 dark:bg-slate-900/95 dark:text-slate-100">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}" wire:navigate>
                        <x-application-logo class="block h-9 w-auto fill-current text-gray-800 dark:text-slate-100" />
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </x-nav-link>
                    <x-nav-link :href="route('ingredients.index')" :active="request()->routeIs('ingredients.*')">
                        Ingredients
                    </x-nav-link>
                    <x-nav-link :href="route('recipes.index')" :active="request()->routeIs('recipes.index')">
                        Discover recipes
                    </x-nav-link>
                    <x-nav-link :href="route('bookmarks.index')" :active="request()->routeIs('bookmarks.*')">
                        Bookmarks
                    </x-nav-link>
                    <x-nav-link :href="route('recipe-collections.index')" :active="request()->routeIs('recipe-collections.*')">
                        Collections
                    </x-nav-link>
                    <x-nav-link :href="route('private-recipe-tags.index')" :active="request()->routeIs('private-recipe-tags.*')">
                        Private tags
                    </x-nav-link>
                    <x-nav-link :href="route('recipes.create')" :active="request()->routeIs('recipes.create')">
                        Create recipe
                    </x-nav-link>
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-600 transition ease-in-out duration-150 hover:text-gray-900 focus:outline-none dark:bg-slate-900 dark:text-slate-300 dark:hover:text-slate-100">
                            <div x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile')" wire:navigate>
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        @can('access-admin')
                            <x-dropdown-link :href="route('admin.managed-recipe-terms.index')">
                                Recipe classifications
                            </x-dropdown-link>
                        @endcan

                        <!-- Authentication -->
                        <button wire:click="logout" class="w-full text-start">
                            <x-dropdown-link>
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center rounded-md p-2 text-gray-500 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-700 focus:bg-gray-100 focus:outline-none focus:text-gray-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100 dark:focus:bg-slate-800 dark:focus:text-slate-100">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                {{ __('Dashboard') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('ingredients.index')" :active="request()->routeIs('ingredients.*')">
                Ingredients
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('recipes.index')" :active="request()->routeIs('recipes.index')">
                Discover recipes
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('bookmarks.index')" :active="request()->routeIs('bookmarks.*')">
                Bookmarks
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('recipe-collections.index')" :active="request()->routeIs('recipe-collections.*')">
                Collections
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('private-recipe-tags.index')" :active="request()->routeIs('private-recipe-tags.*')">
                Private tags
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('recipes.create')" :active="request()->routeIs('recipes.create')">
                Create recipe
            </x-responsive-nav-link>
        </div>

        <!-- Responsive Settings Options -->
        <div class="border-t border-gray-200 pt-4 pb-1 dark:border-slate-800">
            <div class="px-4">
                <div class="text-base font-medium text-gray-800 dark:text-slate-100" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
                <div class="text-sm font-medium text-gray-500 dark:text-slate-400">{{ auth()->user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile')" wire:navigate>
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                @can('access-admin')
                    <x-responsive-nav-link :href="route('admin.managed-recipe-terms.index')">
                        Recipe classifications
                    </x-responsive-nav-link>
                @endcan

                <!-- Authentication -->
                <button wire:click="logout" class="w-full text-start">
                    <x-responsive-nav-link>
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </button>
            </div>
        </div>
    </div>
</nav>
