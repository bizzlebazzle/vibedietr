<div class="max-w-xl">
    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Theme</h2>
    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
        Choose your preferred theme mode. Defaults to your system setting.
    </p>

    <div class="flex flex-wrap gap-2">
        {{-- Light --}}
        <button
            type="button"
            class="px-3 py-2 rounded border text-sm transition"
            :class="themeOverride === 'light'
                     ? 'bg-indigo-700 text-white border-indigo-700'
                     : 'bg-gray-50 dark:bg-gray-700 dark:border-gray-600 text-gray-900 dark:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-600'"
            :aria-pressed="(themeOverride === 'light').toString()"
            x-on:click="setTheme('light')"
        >Light<span aria-hidden="true" x-show="themeOverride === 'light'" x-cloak> ✓</span></button>

        {{-- Dark --}}
        <button
            type="button"
            class="px-3 py-2 rounded border text-sm transition"
            :class="themeOverride === 'dark'
                     ? 'bg-indigo-700 text-white border-indigo-700'
                     : 'bg-gray-50 dark:bg-gray-700 dark:border-gray-600 text-gray-900 dark:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-600'"
            :aria-pressed="(themeOverride === 'dark').toString()"
            x-on:click="setTheme('dark')"
        >Dark<span aria-hidden="true" x-show="themeOverride === 'dark'" x-cloak> ✓</span></button>

        {{-- System --}}
        <button
            type="button"
            class="px-3 py-2 rounded border text-sm transition"
            :class="themeOverride === null
                    ? 'bg-indigo-700 text-white border-indigo-700'
                    : 'bg-gray-50 dark:bg-gray-700 dark:border-gray-600 text-gray-900 dark:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-600'"
            :aria-pressed="(themeOverride === null).toString()"
            x-on:click="setTheme('system')"
        >System<span aria-hidden="true" x-show="themeOverride === null" x-cloak> ✓</span></button>
    </div>
</div>
