<div class="max-w-xl">
    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Theme</h3>
    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
        Choose your preferred theme mode. Defaults to your system setting.
    </p>

    <div class="flex gap-2">
        {{-- Light --}}
        <button
            type="button"
            class="px-3 py-2 rounded border text-sm transition"
            :class="themeOverride === 'light'
                     ? 'bg-indigo-500 text-white border-indigo-500'
                     : 'bg-gray-50 dark:bg-gray-700 dark:border-gray-600 text-gray-900 dark:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-600'"
            x-on:click="setTheme('light')"
        >Light</button>

        {{-- Dark --}}
        <button
            type="button"
            class="px-3 py-2 rounded border text-sm transition"
            :class="themeOverride === 'dark'
                     ? 'bg-indigo-500 text-white border-indigo-500'
                     : 'bg-gray-50 dark:bg-gray-700 dark:border-gray-600 text-gray-900 dark:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-600'"
            x-on:click="setTheme('dark')"
        >Dark</button>

        {{-- System --}}
        <button
            type="button"
            class="px-3 py-2 rounded border text-sm transition"
            :class="themeOverride === null
                    ? 'bg-indigo-500 text-white border-indigo-500'
                    : 'bg-gray-50 dark:bg-gray-700 dark:border-gray-600 text-gray-900 dark:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-600'"
            x-on:click="setTheme('system')"
        >System</button>
    </div>
</div>
