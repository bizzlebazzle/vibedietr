<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800 dark:text-gray-100">Suggest a catalogue correction</h1></x-slot>
    <div class="mx-auto max-w-3xl p-6 text-gray-900 dark:text-gray-100">
        <p class="mb-4">You are proposing changes to version {{ $version->version_number }} of <strong>{{ $version->name }}</strong>. The catalogue will not change until an administrator accepts the proposal.</p>
        <x-input-error :messages="$errors->all()" class="mb-4" />
        <form method="POST" action="{{ route('catalogue.corrections.store', $item) }}" class="space-y-6">
            @csrf
            <input type="hidden" name="base_version_id" value="{{ $version->id }}">
            @foreach(['name' => 'Name', 'brand' => 'Brand', 'manufacturer' => 'Manufacturer'] as $field => $label)
                <fieldset class="rounded border p-4">
                    <legend class="font-semibold">{{ $label }}</legend>
                    <label class="flex gap-2"><input type="checkbox" name="change[{{ $field }}]" value="1" @checked(old("change.$field"))> Propose a change</label>
                    <x-text-input class="mt-2 w-full" :name="$field" :value="old($field, $version->{$field})" :aria-label="$label.' proposed value'" />
                    @if($field !== 'name')<label class="mt-2 flex gap-2"><input type="checkbox" name="clear[{{ $field }}]" value="1" @checked(old("clear.$field"))> Clear this value</label>@endif
                </fieldset>
            @endforeach

            <fieldset class="rounded border p-4 space-y-3">
                <legend class="font-semibold">Package and serving structure</legend>
                <label class="flex gap-2"><input type="checkbox" name="change[package]" value="1" @checked(old('change.package'))> Propose package/serving changes</label>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach(['package_count' => 'Package count', 'item_type' => 'Item type', 'amount_per_item' => 'Amount per item', 'amount_per_item_unit' => 'Amount unit', 'servings_per_item' => 'Servings per item', 'serving_amount' => 'Serving amount', 'serving_amount_unit' => 'Serving unit'] as $field => $label)
                        <label>{{ $label }}<x-text-input class="mt-1 w-full" :name="$field" :value="old($field, is_object($version->{$field}) ? $version->{$field}->value : $version->{$field})" /></label>
                    @endforeach
                </div>
                <p class="text-sm">Use standard units such as gram, millilitre, kilogram, or litre. Derived serving values are recalculated on acceptance.</p>
            </fieldset>

            <fieldset class="rounded border p-4 space-y-3">
                <legend class="font-semibold">Nutrition per 100 g</legend>
                @foreach($nutrients as $nutrient)
                    @php($observation = $version->nutrientObservations->first(fn ($row) => $row->nutrient === $nutrient && $row->basis->value === 'per_100g'))
                    <div class="grid gap-2 border-t pt-3 sm:grid-cols-[1fr_8rem_auto_auto]">
                        <span>{{ str_replace('_', ' ', ucfirst($nutrient->value)) }}</span>
                        <x-text-input name="nutrition[{{ $nutrient->value }}][value]" :value="old('nutrition.'.$nutrient->value.'.value', $observation?->value)" aria-label="{{ $nutrient->value }} proposed value" />
                        <label class="flex gap-2"><input type="checkbox" name="change[nutrition_{{ $nutrient->value }}]" value="1" @checked(old('change.nutrition_'.$nutrient->value))> Change</label>
                        <label class="flex gap-2"><input type="checkbox" name="clear[nutrition_{{ $nutrient->value }}]" value="1" @checked(old('clear.nutrition_'.$nutrient->value))> Clear</label>
                    </div>
                @endforeach
            </fieldset>

            <div>
                <x-input-label for="reason" value="Why is this data incorrect?" />
                <textarea id="reason" name="reason" maxlength="500" required class="mt-1 w-full rounded dark:bg-gray-800">{{ old('reason') }}</textarea>
                <p class="text-sm">This reason is private to moderators and is not published with catalogue facts.</p>
            </div>
            <x-primary-button>Submit correction proposal</x-primary-button>
            <a class="ml-3 underline" href="{{ route('catalogue.show', $item) }}">Cancel</a>
        </form>
    </div>
</x-app-layout>
