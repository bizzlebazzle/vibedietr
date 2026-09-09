<?php

namespace App\Domain\Catalogue;

use App\Models\CatalogueCorrectionProposal;
use App\Models\CatalogueDuplicateCandidate;
use App\Models\CatalogueItem;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CatalogueModerationQueue
{
    public const STATES = [
        'manual_submission' => ['pending', 'approved', 'rejected', 'merged'],
        'duplicate_candidate' => ['pending_review', 'confirmed_distinct', 'confirmed_duplicate', 'dismissed'],
        'correction_proposal' => ['pending', 'accepted', 'rejected'],
    ];

    /** @return LengthAwarePaginator<int, CatalogueItem>|LengthAwarePaginator<int, CatalogueDuplicateCandidate>|LengthAwarePaginator<int, CatalogueCorrectionProposal> */
    public function paginate(User $actor, string $type, string $state = ''): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('moderate-catalogue');
        if (! isset(self::STATES[$type]) || ($state !== '' && ! in_array($state, self::STATES[$type], true))) {
            throw ValidationException::withMessages(['state' => 'Choose a state belonging to the selected work type.']);
        }
        if ($type === 'manual_submission') {
            return CatalogueItem::query()->where('origin', CatalogueItemOrigin::Manual)
                ->with(['currentVersion', 'submitter:id,name'])
                ->when($state !== '', fn ($q) => $q->where('status', $state))
                ->orderBy('id')->paginate(25)->withQueryString();
        }

        if ($type === 'correction_proposal') {
            return CatalogueCorrectionProposal::query()->with(['catalogueItem.currentVersion'])
                ->when($state !== '', fn ($q) => $q->where('state', $state))
                ->orderBy('id')->paginate(25)->withQueryString();
        }

        return CatalogueDuplicateCandidate::query()->with(['firstItem.currentVersion', 'secondItem.currentVersion'])
            ->when($state !== '', fn ($q) => $q->where('status', $state))
            ->orderBy('id')->paginate(25)->withQueryString();
    }
}
