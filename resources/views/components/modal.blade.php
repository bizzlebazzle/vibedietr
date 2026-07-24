@props([
    'name',
    'show' => false,
    'maxWidth' => '2xl',
    'title' => null,
    'showClose' => true,
])

@php
$maxWidth = [
    'sm'          => 'sm:max-w-sm',
    'md'          => 'sm:max-w-md',
    'lg'          => 'sm:max-w-lg',
    'xl'          => 'sm:max-w-xl',
    '2xl'         => 'sm:max-w-2xl',
    '3xl'         => 'sm:max-w-3xl',
    '4xl'         => 'sm:max-w-4xl',
    '5xl'         => 'sm:max-w-5xl',
    '6xl'         => 'sm:max-w-6xl',
    '7xl'         => 'sm:max-w-7xl',
    'screen-xl'   => 'sm:max-w-screen-xl',
    'screen-2xl'  => 'sm:max-w-screen-2xl',
][$maxWidth] ?? 'sm:max-w-2xl';

$titleId = $title ? $name . '-title' : null;
@endphp

<div
    x-data="{
        show:  @js($show) ,
        
        focusables() {
            let selector = 'a, button, input:not([type=\'hidden\']), textarea, select, details, [tabindex]:not([tabindex=\'-1\'])'
            return [...$el.querySelectorAll(selector)].filter(el => ! el.hasAttribute('disabled'))
        },
        firstFocusable() { return this.focusables()[0] },
        lastFocusable() { return this.focusables().slice(-1)[0] },
        nextFocusable() { return this.focusables()[this.nextFocusableIndex()] || this.firstFocusable() },
        prevFocusable() { return this.focusables()[this.prevFocusableIndex()] || this.lastFocusable() },
        nextFocusableIndex() { return (this.focusables().indexOf(document.activeElement) + 1) % (this.focusables().length + 1) },
        prevFocusableIndex() { return Math.max(0, this.focusables().indexOf(document.activeElement)) -1 },
    }"
    x-init="$watch('show', value => {
        if (value) {
            document.body.classList.add('overflow-y-hidden');
            {{ $attributes->has('focusable') ? 'setTimeout(() => firstFocusable()?.focus(), 100)' : '' }}
        } else {
            document.body.classList.remove('overflow-y-hidden');
        }
    })"
    x-on:close.stop="show = false"
    x-on:keydown.escape.window="show = false; $dispatch('close-modal')"
    x-on:keydown.tab.prevent="$event.shiftKey || nextFocusable().focus()"
    x-on:keydown.shift.tab.prevent="prevFocusable().focus()"
    x-show="show"
    class="fixed inset-0 overflow-y-auto px-4 py-6 sm:px-0 z-50"
>
    <!-- Backdrop -->
    <div
        x-show="show"
        class="fixed inset-0 z-0 transform transition-all"
        x-on:click="show = false; $dispatch('close-modal')"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <div class="absolute inset-0 bg-gray-500/75 dark:bg-black/50"></div>
    </div>

    <!-- Panel -->
    <div
        x-show="show"
        class="relative z-10 mx-0 mb-6 w-full overflow-hidden rounded-lg border border-gray-200 bg-white text-gray-900 shadow-2xl transition-all sm:mx-auto sm:w-full {{ $maxWidth }} dark:border-slate-600/80 dark:bg-slate-900 dark:text-slate-100 dark:shadow-[0_30px_90px_rgba(0,0,0,0.8)]"
        role="dialog"
        aria-modal="true"
        @if($titleId) aria-labelledby="{{ $titleId }}" @endif
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
    >
        @isset($header)
            <div class="sticky top-0 z-10 border-b border-gray-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-slate-700 dark:bg-slate-900/95">
                {{ $header }}
            </div>
        @else
            @if($title || $showClose)
                <div class="relative flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-slate-700">
                    @if($title)
                        <h3 id="{{ $titleId }}" class="text-base font-semibold">{{ $title }}</h3>
                    @else
                        <span></span>
                    @endif

                    @if($showClose)
                        <x-close-button x-on:click="show = false; $dispatch('close-modal')" />
                    @endif
                </div>
            @endif
        @endisset

        <!-- Content -->
        <div class="p-4 sm:p-6 max-h-[calc(100vh-8rem)] overflow-y-auto">
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="sticky bottom-0 z-10 border-t border-gray-200 bg-gray-50/95 px-4 py-3 backdrop-blur dark:border-slate-700 dark:bg-slate-900/95">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
