<?php

namespace App\Domain\Catalogue;

use App\Models\CatalogueCorrectionChange;
use App\Models\CatalogueCorrectionProposal;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\CatalogueProviderRefresh;
use App\Observability\OperationalTelemetry;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final readonly class CatalogueProviderRefreshStager
{
    public function __construct(
        private CatalogueProviderRefreshRequester $eligibility,
        private CatalogueProviderRefreshDiffer $differ,
        private OperationalTelemetry $telemetry,
    ) {}

    public function stage(string $refreshId, CatalogueImportData $candidate): CatalogueProviderRefresh
    {
        $outcome = null;

        $refresh = DB::transaction(function () use ($refreshId, $candidate, &$outcome): CatalogueProviderRefresh {
            $refresh = CatalogueProviderRefresh::query()->lockForUpdate()->findOrFail($refreshId);

            if ($refresh->state->isTerminal() || $refresh->state === CatalogueProviderRefreshState::Staged) {
                return $refresh;
            }

            $item = CatalogueItem::query()->lockForUpdate()->findOrFail($refresh->catalogue_item_id);
            if (! $this->stillEligible($refresh, $item)) {
                $outcome = 'refresh_cancelled';
                $this->terminal($refresh, CatalogueProviderRefreshState::Cancelled, 'target_ineligible');

                return $refresh;
            }

            $base = CatalogueItemVersion::query()
                ->whereKey($refresh->base_catalogue_item_version_id)
                ->where('catalogue_item_id', $item->id)
                ->first();

            if ($base === null) {
                $outcome = 'refresh_cancelled';
                $this->terminal($refresh, CatalogueProviderRefreshState::Cancelled, 'base_version_missing');

                return $refresh;
            }

            $changes = $this->differ->diff($refresh, $base, $candidate);

            if ($changes === []) {
                $outcome = 'refresh_success_no_change';
                $this->terminal($refresh, CatalogueProviderRefreshState::NoChange);

                return $refresh;
            }

            $proposal = CatalogueCorrectionProposal::query()->forceCreate([
                'proposal_type' => CatalogueChangeProposalType::ProviderRefresh,
                'catalogue_item_id' => $item->id,
                'base_catalogue_item_version_id' => $base->id,
                'provider_refresh_id' => $refresh->id,
                'proposer_user_id' => null,
                'reason' => 'OpenFoodFacts supplied newer candidate facts.',
                'state' => CatalogueCorrectionProposalState::Pending,
                'submitted_at' => Date::now()->utc(),
            ]);

            foreach ($changes as $change) {
                CatalogueCorrectionChange::query()->forceCreate([
                    'proposal_id' => $proposal->id,
                    'field_key' => $change['field'],
                    'before_value' => $change['before'],
                    'proposed_value' => $change['proposed'],
                    'provenance' => $change['provenance'],
                ]);
            }

            $refresh->forceFill(['state' => CatalogueProviderRefreshState::Staged])->save();
            $outcome = 'refresh_staged_changes';

            return $refresh;
        }, 3);

        if ($outcome !== null) {
            $this->telemetry->counter('catalogue.provider_refresh', [
                'provider' => CatalogueItemSource::OpenFoodFacts->value,
                'outcome' => $outcome,
            ]);
        }

        return $refresh->fresh(['proposal.changes']);
    }

    private function stillEligible(CatalogueProviderRefresh $refresh, CatalogueItem $item): bool
    {
        try {
            $this->eligibility->ensureEligible($item);
        } catch (\Throwable) {
            return false;
        }

        return $item->source === $refresh->provider
            && $item->source_identifier === $refresh->source_identifier;
    }

    private function terminal(
        CatalogueProviderRefresh $refresh,
        CatalogueProviderRefreshState $state,
        ?string $failureCode = null,
    ): void {
        $refresh->forceFill([
            'state' => $state,
            'active_key' => null,
            'failure_code' => $failureCode,
            'completed_at' => Date::now()->utc(),
        ])->save();
    }
}
