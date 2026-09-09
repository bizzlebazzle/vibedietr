<?php

namespace App\Http\Controllers;

use App\Domain\Catalogue\CatalogueCorrectionProposalCreator;
use App\Domain\Catalogue\CatalogueItemStatus;
use App\Domain\Nutrition\Nutrient;
use App\Models\CatalogueItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class CatalogueCorrectionProposalController extends Controller
{
    public function create(Request $request, int $catalogueItem): View
    {
        $item = CatalogueItem::query()->with(['currentVersion.nutrientObservations'])->findOrFail($catalogueItem);
        abort_unless($item->status === CatalogueItemStatus::Approved && $item->canonical_catalogue_item_id === null, 404);
        $this->authorize('view', $item);

        return view('catalogue.corrections.create', ['item' => $item, 'version' => $item->currentVersion, 'nutrients' => Nutrient::cases()]);
    }

    public function store(Request $request, int $catalogueItem, CatalogueCorrectionProposalCreator $creator): RedirectResponse
    {
        $data = $request->validate([
            'base_version_id' => ['required', 'ulid'],
            'reason' => ['required', 'string', 'max:500'],
            'change' => ['nullable', 'array'],
            'clear' => ['nullable', 'array'],
            'name' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'package_count' => ['nullable'],
            'item_type' => ['nullable', 'string', 'max:32'],
            'amount_per_item' => ['nullable'],
            'amount_per_item_unit' => ['nullable', 'string', 'max:32'],
            'servings_per_item' => ['nullable'],
            'serving_amount' => ['nullable'],
            'serving_amount_unit' => ['nullable', 'string', 'max:32'],
            'nutrition' => ['nullable', 'array'],
            'nutrition.*.value' => ['nullable'],
        ]);
        $changes = [];
        foreach (['name', 'brand', 'manufacturer'] as $field) {
            if ($request->boolean("change.$field")) {
                $changes[$field] = $request->boolean("clear.$field") ? null : ($data[$field] ?? null);
            }
        }
        if ($request->boolean('change.package')) {
            $changes['package'] = $request->only([
                'package_count', 'item_type', 'amount_per_item', 'amount_per_item_unit',
                'servings_per_item', 'serving_amount', 'serving_amount_unit',
            ]);
        }
        foreach (Nutrient::cases() as $nutrient) {
            if ($request->boolean("change.nutrition_{$nutrient->value}")) {
                $field = "nutrition.{$nutrient->value}.per_100g";
                $changes[$field] = $request->boolean("clear.nutrition_{$nutrient->value}") ? null : [
                    'value' => $data['nutrition'][$nutrient->value]['value'] ?? null,
                    'unit' => match ($nutrient) {
                        Nutrient::EnergyKcal => 'kcal',
                        Nutrient::EnergyKj => 'kj',
                        Nutrient::Sodium => 'mg',
                        default => 'g',
                    },
                    'status' => 'known',
                ];
            }
        }

        $creator->create($request->user(), $catalogueItem, $data['base_version_id'], $data['reason'], $changes);

        return redirect()->route('catalogue.show', $catalogueItem)->with('status', 'Your correction proposal was submitted for private administrator review.');
    }
}
