<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100">Submit a manual catalogue food</h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('catalogue.manual.store') }}" class="space-y-8 rounded-lg bg-white p-6 shadow dark:bg-slate-900">
                @csrf
                <div>
                    <p class="text-sm text-gray-700 dark:text-gray-300">
                        Use this only for a rare food without a barcode. Your submission stays private to you and administrators while it awaits moderation.
                    </p>
                    <p class="mt-2 text-sm font-medium text-amber-800 dark:text-amber-200">
                        If the food has a barcode, use the barcode import workflow instead.
                    </p>
                </div>

                @if ($errors->any())
                    <div role="alert" class="rounded border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
                        Please correct the highlighted fields. Your entered details have been kept.
                    </div>
                @endif

                @php($field = fn (string $key, mixed $default = '') => $input[$key] ?? old($key, $default))

                <section class="space-y-4" aria-labelledby="manual-identity-heading">
                    <h3 id="manual-identity-heading" class="text-lg font-semibold">Food identity</h3>
                    <div>
                        <x-input-label for="name" value="Food name" />
                        <x-text-input id="name" name="name" value="{{ $field('name') }}" maxlength="255" class="mt-1 block w-full" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>
                    <fieldset>
                        <legend class="text-sm font-medium text-gray-700 dark:text-gray-300">Classification</legend>
                        <div class="mt-2 flex gap-5">
                            @foreach (['generic' => 'Generic food', 'branded' => 'Branded product'] as $value => $label)
                                <label class="inline-flex items-center gap-2">
                                    <input type="radio" name="classification" value="{{ $value }}" @checked($field('classification', 'generic') === $value) required>
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('classification')" class="mt-2" />
                    </fieldset>
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach (['brand' => 'Brand (required for branded products)', 'manufacturer' => 'Manufacturer', 'food_form' => 'Food form', 'preparation' => 'Preparation', 'treatment' => 'Treatment'] as $key => $label)
                            <div>
                                <x-input-label for="{{ $key }}" value="{{ $label }}" />
                                <x-text-input id="{{ $key }}" name="{{ $key }}" value="{{ $field($key) }}" maxlength="255" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get($key)" class="mt-2" />
                            </div>
                        @endforeach
                    </div>
                    <div>
                        <x-input-label for="composition" value="Ingredients or composition (optional)" />
                        <textarea id="composition" name="composition" maxlength="1000" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">{{ $field('composition') }}</textarea>
                        <x-input-error :messages="$errors->get('composition')" class="mt-2" />
                    </div>
                </section>

                <section class="space-y-4 border-t pt-6 dark:border-slate-700" aria-labelledby="package-heading">
                    <div>
                        <h3 id="package-heading" class="text-lg font-semibold">Package and serving</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Leave unknown values blank. Amounts and their units must be supplied together.</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><x-input-label for="package_count" value="Package count" /><x-text-input id="package_count" name="package_count" value="{{ $field('package_count') }}" type="number" min="1" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('package_count')" class="mt-2" /></div>
                        <div><x-input-label for="item_type" value="Item type (for example, can)" /><x-text-input id="item_type" name="item_type" value="{{ $field('item_type') }}" maxlength="32" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('item_type')" class="mt-2" /></div>
                        <div><x-input-label for="amount_per_item" value="Amount per item" /><x-text-input id="amount_per_item" name="amount_per_item" value="{{ $field('amount_per_item') }}" inputmode="decimal" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('amount_per_item')" class="mt-2" /></div>
                        <div>
                            <x-input-label for="amount_per_item_unit" value="Amount-per-item unit" />
                            <select id="amount_per_item_unit" name="amount_per_item_unit" class="mt-1 block w-full rounded-md border-gray-300 dark:border-slate-600 dark:bg-slate-900">
                                <option value="">Unknown</option>
                                @foreach (\App\Domain\Measurements\StandardUnit::cases() as $unit)<option value="{{ $unit->value }}" @selected($field('amount_per_item_unit') === $unit->value)>{{ $unit->value }}</option>@endforeach
                            </select>
                            <x-input-error :messages="$errors->get('amount_per_item_unit')" class="mt-2" />
                        </div>
                        <div><x-input-label for="servings_per_item" value="Servings per item" /><x-text-input id="servings_per_item" name="servings_per_item" value="{{ $field('servings_per_item') }}" inputmode="decimal" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('servings_per_item')" class="mt-2" /></div>
                        <div><x-input-label for="serving_amount" value="Direct serving amount" /><x-text-input id="serving_amount" name="serving_amount" value="{{ $field('serving_amount') }}" inputmode="decimal" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('serving_amount')" class="mt-2" /></div>
                        <div>
                            <x-input-label for="serving_amount_unit" value="Serving-amount unit" />
                            <select id="serving_amount_unit" name="serving_amount_unit" class="mt-1 block w-full rounded-md border-gray-300 dark:border-slate-600 dark:bg-slate-900">
                                <option value="">Unknown</option>
                                @foreach (\App\Domain\Measurements\StandardUnit::cases() as $unit)<option value="{{ $unit->value }}" @selected($field('serving_amount_unit') === $unit->value)>{{ $unit->value }}</option>@endforeach
                            </select>
                            <x-input-error :messages="$errors->get('serving_amount_unit')" class="mt-2" />
                        </div>
                    </div>
                </section>

                <section class="space-y-4 border-t pt-6 dark:border-slate-700" aria-labelledby="nutrition-heading">
                    <div>
                        <h3 id="nutrition-heading" class="text-lg font-semibold">Nutrition (optional)</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Incomplete panels are accepted. A blank value is unknown; zero means a known zero.</p>
                    </div>
                    <div>
                        <x-input-label for="nutrition_basis" value="Nutrition basis" />
                        <select id="nutrition_basis" name="nutrition_basis" class="mt-1 block w-full rounded-md border-gray-300 dark:border-slate-600 dark:bg-slate-900" required>
                            @foreach (['per_100g' => 'Per 100 g', 'per_100ml' => 'Per 100 mL', 'per_serving' => 'Per serving'] as $value => $label)
                                <option value="{{ $value }}" @selected($field('nutrition_basis', 'per_100g') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('nutrition_basis')" class="mt-2" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach (\App\Domain\Nutrition\Nutrient::cases() as $nutrient)
                            @php($unit = match($nutrient) { \App\Domain\Nutrition\Nutrient::EnergyKcal => 'kcal', \App\Domain\Nutrition\Nutrient::EnergyKj => 'kJ', \App\Domain\Nutrition\Nutrient::Sodium => 'mg', default => 'g' })
                            <div>
                                <x-input-label for="nutrition_{{ $nutrient->value }}" value="{{ ucfirst(str_replace('_', ' ', $nutrient->value)).' ('.$unit.')' }}" />
                                <x-text-input id="nutrition_{{ $nutrient->value }}" name="nutrition[{{ $nutrient->value }}]" value="{{ data_get($input, 'nutrition.'.$nutrient->value, old('nutrition.'.$nutrient->value)) }}" inputmode="decimal" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('nutrition.'.$nutrient->value)" class="mt-2" />
                            </div>
                        @endforeach
                    </div>
                </section>

                @if ($strongMatches !== [])
                    <fieldset class="space-y-4 rounded border-2 border-amber-400 bg-amber-50 p-4 dark:bg-amber-950/30">
                        <legend class="px-2 font-semibold text-amber-900 dark:text-amber-100">Possible existing approved food</legend>
                        <p class="text-sm text-amber-900 dark:text-amber-100">Choose the matching approved food, then explicitly reuse it or submit this food as distinct.</p>
                        @foreach ($strongMatches as $match)
                            <label class="flex items-start gap-2 rounded border border-amber-300 bg-white p-3 dark:bg-slate-900">
                                <input type="radio" name="duplicate_item_id" value="{{ $match['item_id'] }}" required>
                                <span><strong>{{ $match['name'] }}</strong><span class="block text-xs">{{ $match['evidence'] === 'approved_alias' ? 'Approved alias and compatible identity details' : 'Exact name and compatible identity details' }}</span></span>
                            </label>
                        @endforeach
                        <x-input-error :messages="$errors->get('duplicate_item_id')" />
                        <div class="space-y-2">
                            <label class="flex items-center gap-2"><input type="radio" name="duplicate_choice" value="reuse" required><span>Use existing food</span></label>
                            <label class="flex items-center gap-2"><input type="radio" name="duplicate_choice" value="continue_distinct" required><span>Submit as distinct food</span></label>
                        </div>
                        <div>
                            <x-input-label for="distinction_explanation" value="Why is this a distinct food? (required when submitting separately)" />
                            <textarea id="distinction_explanation" name="distinction_explanation" maxlength="500" rows="3" class="mt-1 block w-full rounded-md border-gray-300 dark:border-slate-600 dark:bg-slate-900"></textarea>
                            <x-input-error :messages="$errors->get('distinction_explanation')" class="mt-2" />
                            <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">Private moderation information; maximum 500 characters.</p>
                        </div>
                    </fieldset>
                @endif

                <div class="flex flex-wrap items-center justify-between gap-3 border-t pt-6 dark:border-slate-700">
                    <a href="{{ route('catalogue.index') }}" class="text-sm text-sky-700 hover:underline dark:text-sky-300">Cancel</a>
                    <x-primary-button>{{ $strongMatches === [] ? 'Check and submit' : 'Continue with my choice' }}</x-primary-button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
