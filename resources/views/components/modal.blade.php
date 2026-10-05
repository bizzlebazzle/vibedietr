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
        show: @js($show),
        opener: null,
        openerId: null,
        open() {
            this.opener = document.activeElement;
            this.openerId = this.opener?.id;
            this.show = true;
            this.$nextTick(() => {
                if (this.show && !this.$refs.panel.contains(document.activeElement)) {
                    (this.$refs.panel.querySelector('[data-validation-summary]') || this.firstFocusable() || this.$refs.panel).focus();
                }
            });
        },
        background: [],
        isolate() {
            this.restoreBackground();
            for (let branch = this.$el; branch.parentElement && branch !== document.body; branch = branch.parentElement) {
                for (const sibling of branch.parentElement.children) {
                    if (sibling === branch || ['SCRIPT', 'STYLE', 'LINK'].includes(sibling.tagName)) continue;
                    this.background.push([sibling, sibling.inert]);
                    sibling.inert = true;
                }
            }
        },
        restoreBackground() {
            for (const [element, inert] of this.background) element.inert = inert;
            this.background = [];
        },
        destroy() { this.restoreBackground(); document.body.classList.remove('overflow-y-hidden'); },
        close() {
            if (!this.show) return;
            this.show = false;
            this.$dispatch('close-modal');
            this.$nextTick(() => {
                if (this.opener?.isConnected) this.opener.focus();
                else if (this.openerId && document.getElementById(this.openerId)) document.getElementById(this.openerId).focus();
                else {
                    const destination = document.querySelector('main h1, main h2, main');
                    destination?.setAttribute('tabindex', '-1');
                    destination?.focus();
                }
            });
        },
        
        focusables() {
            let selector = 'a, button, input:not([type=\'hidden\']), textarea, select, details, [tabindex]:not([tabindex=\'-1\'])'
            return [...$refs.panel.querySelectorAll(selector)].filter(el => ! el.hasAttribute('disabled') && el.getClientRects().length)
        },
        firstFocusable() { return this.focusables()[0] },
        lastFocusable() { return this.focusables().slice(-1)[0] },
        nextFocusable() { return this.focusables()[this.nextFocusableIndex()] || this.firstFocusable() || this.$refs.panel },
        prevFocusable() { return this.focusables()[this.prevFocusableIndex()] || this.lastFocusable() || this.$refs.panel },
        nextFocusableIndex() { return (this.focusables().indexOf(document.activeElement) + 1) % (this.focusables().length + 1) },
        prevFocusableIndex() { return Math.max(0, this.focusables().indexOf(document.activeElement)) -1 },
    }"
    x-init="if (show) { opener = document.activeElement; isolate(); document.body.classList.add('overflow-y-hidden'); $nextTick(() => ($refs.panel.querySelector('[data-validation-summary]') || firstFocusable() || $refs.panel).focus()); }
    $watch('show', value => {
        if (value) {
            isolate();
            document.body.classList.add('overflow-y-hidden');
        } else {
            restoreBackground();
            document.body.classList.remove('overflow-y-hidden');
        }
    })"
    x-on:open-modal.window="if ($event.detail === @js($name)) open()"
    x-on:close.stop="close()"
    x-on:close-modal.window="close()"
    x-on:keydown.escape.window="if (show) { $event.preventDefault(); close() }"
    x-on:keydown.tab="if (show && !focusables().includes($event.target)) { $event.preventDefault(); ($event.shiftKey ? lastFocusable() : firstFocusable())?.focus() } else if (show && $event.target === lastFocusable() && !$event.shiftKey) { $event.preventDefault(); firstFocusable()?.focus() } else if (show && $event.target === firstFocusable() && $event.shiftKey) { $event.preventDefault(); lastFocusable()?.focus() }"
    x-show="show"
    class="fixed inset-0 overflow-y-auto px-4 py-6 sm:px-0 z-50"
>
    <!-- Backdrop -->
    <div
        x-show="show"
        class="fixed inset-0 z-0 transform transition-all"
        x-on:click="close()"
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
        x-ref="panel"
        tabindex="-1"
        class="relative z-10 mx-0 mb-6 w-full overflow-hidden rounded-lg border border-gray-200 bg-white text-gray-900 shadow-2xl transition-all sm:mx-auto sm:w-full {{ $maxWidth }} dark:border-slate-600/80 dark:bg-slate-900 dark:text-slate-100 dark:shadow-[0_30px_90px_rgba(0,0,0,0.8)]"
        role="dialog"
        aria-modal="true"
        @if($titleId) aria-labelledby="{{ $titleId }}" @else aria-label="{{ str_replace('-', ' ', $name) }}" @endif
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
                        <x-close-button x-on:click="close()" />
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
