<?php

namespace App\Domain\Catalogue;

use App\Integrations\OpenFoodFacts\OpenFoodFactsCatalogueMapper;
use App\Integrations\OpenFoodFacts\OpenFoodFactsClient;
use App\Integrations\OpenFoodFacts\OpenFoodFactsLookupStatus;
use App\Models\CatalogueItem;
use App\Models\CatalogueProviderRefresh;
use App\Observability\OperationalTelemetry;
use App\Queue\Exceptions\RetryableJobException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final readonly class ProcessCatalogueProviderRefresh
{
    public function __construct(
        private OpenFoodFactsClient $client,
        private OpenFoodFactsCatalogueMapper $mapper,
        private CatalogueProviderRefreshRequester $eligibility,
        private CatalogueProviderRefreshStager $stager,
        private OperationalTelemetry $telemetry,
    ) {}

    public function process(string $refreshId): void
    {
        $refresh = $this->claim($refreshId);

        if ($refresh === null) {
            return;
        }

        $result = $this->client->lookup($refresh->source_identifier, $refresh->correlation_id);

        if ($result->status === OpenFoodFactsLookupStatus::Success && $result->product !== null) {
            $this->stager->stage($refresh->id, $this->mapper->map($result->product));

            return;
        }

        if (in_array($result->status, [OpenFoodFactsLookupStatus::Unavailable, OpenFoodFactsLookupStatus::RateLimited], true)) {
            $this->telemetry->counter('catalogue.provider_refresh', [
                'provider' => CatalogueItemSource::OpenFoodFacts->value,
                'outcome' => 'refresh_retry',
            ]);

            throw new RetryableJobException('openfoodfacts_'.$result->status->value);
        }

        $state = $result->status === OpenFoodFactsLookupStatus::NotFound
            ? CatalogueProviderRefreshState::NotFound
            : CatalogueProviderRefreshState::Failed;
        $code = match ($result->status) {
            OpenFoodFactsLookupStatus::NotFound => 'provider_not_found',
            OpenFoodFactsLookupStatus::InvalidResponse => 'provider_invalid_response',
            default => 'provider_permanent_failure',
        };
        $this->finish($refresh->id, $state, $code);

        $this->telemetry->counter('catalogue.provider_refresh', [
            'provider' => CatalogueItemSource::OpenFoodFacts->value,
            'outcome' => $state === CatalogueProviderRefreshState::NotFound
                ? 'refresh_provider_not_found'
                : 'refresh_provider_failure',
        ]);
    }

    private function claim(string $refreshId): ?CatalogueProviderRefresh
    {
        return DB::transaction(function () use ($refreshId): ?CatalogueProviderRefresh {
            $refresh = CatalogueProviderRefresh::query()->lockForUpdate()->findOrFail($refreshId);

            if ($refresh->state->isTerminal() || $refresh->state === CatalogueProviderRefreshState::Staged) {
                return null;
            }

            $item = CatalogueItem::query()->lockForUpdate()->findOrFail($refresh->catalogue_item_id);
            try {
                $this->eligibility->ensureEligible($item);
                $eligible = $item->source === $refresh->provider
                    && $item->source_identifier === $refresh->source_identifier;
            } catch (\Throwable) {
                $eligible = false;
            }

            if (! $eligible) {
                $refresh->forceFill([
                    'state' => CatalogueProviderRefreshState::Cancelled,
                    'active_key' => null,
                    'failure_code' => 'target_ineligible',
                    'completed_at' => Date::now()->utc(),
                ])->save();

                return null;
            }

            $refresh->forceFill([
                'state' => CatalogueProviderRefreshState::Processing,
                'started_at' => $refresh->started_at ?? Date::now()->utc(),
            ])->save();

            return $refresh;
        }, 3);
    }

    private function finish(string $refreshId, CatalogueProviderRefreshState $state, string $code): void
    {
        DB::transaction(function () use ($refreshId, $state, $code): void {
            $refresh = CatalogueProviderRefresh::query()->lockForUpdate()->findOrFail($refreshId);

            if ($refresh->state->isTerminal() || $refresh->state === CatalogueProviderRefreshState::Staged) {
                return;
            }

            $refresh->forceFill([
                'state' => $state,
                'active_key' => null,
                'failure_code' => $code,
                'completed_at' => Date::now()->utc(),
            ])->save();
        }, 3);
    }
}
