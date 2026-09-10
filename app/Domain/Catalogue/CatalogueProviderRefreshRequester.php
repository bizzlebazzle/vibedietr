<?php

namespace App\Domain\Catalogue;

use App\Jobs\RefreshOpenFoodFactsCatalogueItem;
use App\Models\CatalogueItem;
use App\Models\CatalogueProviderRefresh;
use App\Queue\CorrelationId;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CatalogueProviderRefreshRequester
{
    public function request(int $catalogueItemId, ?string $correlationId = null): CatalogueProviderRefresh
    {
        $correlationId = CorrelationId::resolve($correlationId);
        $activeKey = CatalogueItemSource::OpenFoodFacts->value.':'.$catalogueItemId;

        try {
            $refresh = DB::transaction(function () use ($catalogueItemId, $correlationId, $activeKey): CatalogueProviderRefresh {
                $existing = CatalogueProviderRefresh::query()->where('active_key', $activeKey)->lockForUpdate()->first();

                if ($existing !== null) {
                    return $existing;
                }

                $item = CatalogueItem::query()->lockForUpdate()->findOrFail($catalogueItemId);
                $this->ensureEligible($item);

                return CatalogueProviderRefresh::query()->forceCreate([
                    'catalogue_item_id' => $item->id,
                    'base_catalogue_item_version_id' => $item->current_catalogue_item_version_id,
                    'provider' => CatalogueItemSource::OpenFoodFacts,
                    'source_identifier' => $item->source_identifier,
                    'correlation_id' => $correlationId,
                    'state' => CatalogueProviderRefreshState::Queued,
                    'active_key' => $activeKey,
                ]);
            }, 3);
        } catch (QueryException $exception) {
            $refresh = CatalogueProviderRefresh::query()->where('active_key', $activeKey)->first();

            if ($refresh === null) {
                throw $exception;
            }
        }

        if (in_array($refresh->state, [CatalogueProviderRefreshState::Queued, CatalogueProviderRefreshState::Processing], true)) {
            RefreshOpenFoodFactsCatalogueItem::dispatch($refresh->id, $refresh->correlation_id);
        }

        return $refresh;
    }

    public function ensureEligible(CatalogueItem $item): void
    {
        $eligible = $item->status === CatalogueItemStatus::Approved
            && $item->canonical_catalogue_item_id === null
            && $item->current_catalogue_item_version_id !== null
            && $item->source === CatalogueItemSource::OpenFoodFacts
            && $item->barcode !== null
            && $item->source_identifier !== null;

        if ($eligible) {
            try {
                $eligible = Barcode::normalize($item->barcode) === Barcode::normalize($item->source_identifier);
            } catch (InvalidArgumentException) {
                $eligible = false;
            }
        }

        if (! $eligible) {
            throw ValidationException::withMessages([
                'catalogue_item' => 'Only an active OpenFoodFacts-backed catalogue item with matching stable source identity may be refreshed.',
            ]);
        }
    }
}
