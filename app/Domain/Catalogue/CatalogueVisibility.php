<?php

namespace App\Domain\Catalogue;

use App\Models\CatalogueItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class CatalogueVisibility
{
    /** @param Builder<CatalogueItem> $query */
    public function apply(Builder $query, ?User $user): Builder
    {
        if ($user?->can('access-admin') === true) {
            return $query;
        }

        return $query->where(function (Builder $visibility) use ($user): void {
            $visibility->where('catalogue_items.status', CatalogueItemStatus::Approved);

            if ($user !== null) {
                $visibility->orWhere(function (Builder $privateManual) use ($user): void {
                    $privateManual
                        ->whereIn('catalogue_items.status', [
                            CatalogueItemStatus::Pending,
                            CatalogueItemStatus::Rejected,
                        ])
                        ->where('catalogue_items.origin', CatalogueItemOrigin::Manual)
                        ->where('catalogue_items.submitted_by_user_id', $user->getKey());
                });
            }
        });
    }

    /** @param Builder<CatalogueItem> $query */
    public function applyDiscovery(Builder $query, ?User $user): Builder
    {
        if ($user?->can('access-admin') === true) {
            return $query->whereIn('catalogue_items.status', [
                CatalogueItemStatus::Approved,
                CatalogueItemStatus::Pending,
            ]);
        }

        return $query->where(function (Builder $visibility) use ($user): void {
            $visibility->where('catalogue_items.status', CatalogueItemStatus::Approved);

            if ($user !== null) {
                $visibility->orWhere(function (Builder $pending) use ($user): void {
                    $pending
                        ->where('catalogue_items.status', CatalogueItemStatus::Pending)
                        ->where('catalogue_items.origin', CatalogueItemOrigin::Manual)
                        ->where('catalogue_items.submitted_by_user_id', $user->getKey());
                });
            }
        });
    }

    public function allows(?User $user, CatalogueItem $item): bool
    {
        if ($user?->can('access-admin') === true) {
            return true;
        }

        if ($item->status === CatalogueItemStatus::Approved) {
            return true;
        }

        return $user !== null
            && in_array($item->status, [CatalogueItemStatus::Pending, CatalogueItemStatus::Rejected], true)
            && $item->origin === CatalogueItemOrigin::Manual
            && $item->submitted_by_user_id === $user->getKey();
    }

    /** @param Builder<CatalogueItem> $query */
    public function applySelectable(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $selectable) use ($user): void {
            $selectable->where('catalogue_items.status', CatalogueItemStatus::Approved)
                ->orWhere(function (Builder $pending) use ($user): void {
                    $pending
                        ->where('catalogue_items.status', CatalogueItemStatus::Pending)
                        ->where('catalogue_items.origin', CatalogueItemOrigin::Manual)
                        ->where('catalogue_items.submitted_by_user_id', $user->getKey());
                });
        });
    }
}
