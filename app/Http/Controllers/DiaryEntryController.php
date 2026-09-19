<?php

namespace App\Http\Controllers;

use App\Domain\Diary\DiaryConsumptionManager;
use App\Domain\Diary\DiaryEntryWriter;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Nutrition\NutrientRegistry;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DiaryEntryController extends Controller
{
    public function store(Request $request, DiaryEntryWriter $writer): RedirectResponse
    {
        $user = $this->user($request);
        $writer->create($request->validate($this->storeRules()), $user);

        return back()->with('status', 'Diary entry recorded.');
    }

    public function consume(Request $request, int $diaryEntry, DiaryConsumptionManager $manager): RedirectResponse
    {
        $user = $this->user($request);
        $entry = $user->diaryEntries()->findOrFail($diaryEntry);
        $data = $request->validate($this->consumptionRules(true, $entry->kind->value === 'recipe'));
        $unit = $entry->kind->value === 'recipe' ? 'serving' : $data['actual_unit'];
        $manager->consumeDiary($entry, (string) $data['actual_amount'], $unit, $data['consumed_local_at'] ?? null, $data['timezone'] ?? null, isset($data['utc_offset_minutes']) ? (int) $data['utc_offset_minutes'] : null, $data['effective_diary_date'] ?? null, $user);

        return back()->with('status', 'Diary consumption recorded.');
    }

    public function update(Request $request, int $diaryEntry, DiaryConsumptionManager $manager): RedirectResponse
    {
        $user = $this->user($request);
        $entry = $user->diaryEntries()->findOrFail($diaryEntry);
        $data = $request->validate($this->consumptionRules(false, $entry->kind->value === 'recipe'));
        $manager->correct($entry, 'diary_entry_id', $data['actual_amount'] ?? null, $data['consumed_local_at'] ?? null, $data['timezone'] ?? null, isset($data['utc_offset_minutes']) ? (int) $data['utc_offset_minutes'] : null, $data['effective_diary_date'] ?? null, $user);

        return back()->with('status', 'Diary consumption corrected.');
    }

    public function destroy(Request $request, int $diaryEntry, DiaryConsumptionManager $manager): RedirectResponse
    {
        $user = $this->user($request);
        $entry = $user->diaryEntries()->findOrFail($diaryEntry);
        $manager->reverse($entry, 'diary_entry_id', $user);

        return back()->with('status', 'Diary consumption reversed.');
    }

    private function user(Request $r): User
    {
        $u = $r->user();
        if (! $u instanceof User) {
            abort(403);
        }

        return $u;
    }

    /** @return array<string,mixed> */
    private function consumptionRules(bool $required, bool $recipe): array
    {
        $amountRules = $recipe
            ? [$required ? 'required' : 'nullable', 'string', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99']
            : [$required ? 'required' : 'nullable', 'string', 'numeric', 'decimal:0,18', 'gt:0', 'regex:/^\d{1,20}(\.\d{1,18})?$/'];
        $unitRule = ! $required || $recipe ? 'prohibited' : 'required';

        return ['actual_amount' => $amountRules, 'actual_unit' => [$unitRule, Rule::enum(StandardUnit::class)], 'consumed_local_at' => ['nullable', 'string', 'max:19'], 'timezone' => ['nullable', 'string', 'timezone:all', 'max:64'], 'utc_offset_minutes' => ['nullable', 'integer', 'between:-840,840'], 'effective_diary_date' => ['nullable', 'date_format:Y-m-d']];
    }

    /** @return array<string,mixed> */
    private function storeRules(): array
    {
        $kind = request()->input('kind');

        return [
            'kind' => ['required', Rule::in(['recipe', 'catalogue', 'one_off'])],
            'recipe_id' => [$kind === 'recipe' ? 'required' : 'prohibited', 'integer', 'min:1'],
            'catalogue_item_id' => [$kind === 'catalogue' ? 'required' : 'prohibited', 'integer', 'min:1'],
            'one_off_wording' => [$kind === 'one_off' ? 'required' : 'prohibited', 'string', 'max:255'],
            'actual_amount' => $kind === 'recipe'
                ? ['required', 'string', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99']
                : ['required', 'string', 'numeric', 'decimal:0,18', 'gt:0', 'regex:/^\d{1,20}(\.\d{1,18})?$/'],
            'actual_unit' => [$kind === 'recipe' ? 'prohibited' : 'required', Rule::enum(StandardUnit::class)],
            'one_off_nutrition_basis' => [$kind === 'one_off' ? 'nullable' : 'prohibited', 'string', Rule::in(['per_100g', 'per_100ml', 'per_serving', 'per_item']), 'required_with:one_off_nutrition'],
            'one_off_nutrition' => [$kind === 'one_off' ? 'nullable' : 'prohibited', 'array:'.implode(',', NutrientRegistry::stableIdentifiers())],
            'one_off_nutrition.*' => ['nullable', 'string', 'numeric', 'decimal:0,18', 'gte:0'],
            'consumed_local_at' => ['nullable', 'string', 'max:19'],
            'timezone' => ['nullable', 'string', 'timezone:all', 'max:64'],
            'utc_offset_minutes' => ['nullable', 'integer', 'between:-840,840'],
            'effective_diary_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
