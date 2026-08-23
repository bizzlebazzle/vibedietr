@php($publicProfile = auth()->user()->publicProfile)

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-slate-100">Public attribution</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            Choose the name shown on newly finalized public recipe versions. Your email and private account details are never shown.
        </p>
    </header>

    @if (session('status') === 'Public attribution settings saved.')
        <p role="status" class="mt-4 text-sm font-medium text-green-700 dark:text-green-300">Public attribution settings saved.</p>
    @endif

    <form method="POST" action="{{ route('profile.public-attribution.update') }}" class="mt-6 space-y-5">
        @csrf
        @method('PATCH')

        <div>
            <x-input-label for="attribution_name" value="Public name" />
            <input
                id="attribution_name"
                name="attribution_name"
                type="text"
                maxlength="80"
                autocomplete="nickname"
                value="{{ old('attribution_name', $publicProfile?->attribution_name) }}"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
            >
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Use your chosen display name, username, or real name. Email addresses and HTML are not accepted.</p>
            <x-input-error class="mt-2" :messages="$errors->get('attribution_name')" />
        </div>

        @foreach ([
            'profile_enabled' => ['Enable public profile page', 'Disabling the page does not unpublish recipes or erase their saved attribution.'],
            'show_public_recipes' => ['List public recipes', 'Lists only current public finalized recipes that are not remixes.'],
            'show_public_remixes' => ['List public remixes', 'Lists only current public finalized recipes created as remixes.'],
        ] as $field => [$label, $help])
            <div>
                <input type="hidden" name="{{ $field }}" value="0">
                <label class="flex items-start gap-3">
                    <input
                        type="checkbox"
                        name="{{ $field }}"
                        value="1"
                        @checked(old($field, $publicProfile?->{$field} ?? false))
                        class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-800"
                    >
                    <span>
                        <span class="font-medium text-gray-900 dark:text-slate-100">{{ $label }}</span>
                        <span class="block text-sm text-gray-600 dark:text-gray-400">{{ $help }}</span>
                    </span>
                </label>
            </div>
        @endforeach

        @if ($publicProfile?->profile_enabled)
            <p class="text-sm">
                <a class="text-blue-700 underline dark:text-blue-300" href="{{ route('public-profiles.show', $publicProfile) }}">View your public profile</a>
            </p>
        @endif

        <button type="submit" class="rounded bg-blue-600 px-4 py-2 font-semibold text-white">Save public attribution</button>
        <p class="text-sm text-gray-600 dark:text-gray-400">Changing this name affects future finalized versions. Existing immutable versions keep the attribution selected when they were published.</p>
    </form>
</section>
