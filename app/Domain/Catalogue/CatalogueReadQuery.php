<?php

namespace App\Domain\Catalogue;

use App\Models\CatalogueItem;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class CatalogueReadQuery
{
    public function __construct(private readonly CatalogueVisibility $visibility) {}

    /** @return LengthAwarePaginator<int, CatalogueItemReadModel> */
    public function paginate(?User $user, string $search, int $perPage = 12): LengthAwarePaginator
    {
        $paginator = $this->search($this->visibleQuery($user), $search)
            ->orderByDesc('catalogue_items.introduced_at')
            ->orderByDesc('catalogue_items.id')
            ->paginate($perPage, ['*'], 'page');

        return $paginator->through(function (Model $item): CatalogueItemReadModel {
            assert($item instanceof CatalogueItem);

            return CatalogueItemReadModel::fromCatalogueItem($item);
        });
    }

    public function findVisibleOrFail(int $id, ?User $user): CatalogueItem
    {
        return $this->visibleQuery($user)->findOrFail($id);
    }

    public function findVisibleByBarcode(?User $user, string $barcode): ?CatalogueItem
    {
        return $this->visibleQuery($user)
            ->where('catalogue_items.barcode', $barcode)
            ->first();
    }

    public function project(CatalogueItem $item): CatalogueItemReadModel
    {
        return CatalogueItemReadModel::fromCatalogueItem($item);
    }

    /** @return LengthAwarePaginator<int, Ingredient> */
    public function paginateLegacyFallback(User $user, string $search, int $perPage = 12): LengthAwarePaginator
    {
        return Ingredient::query()
            ->where('user_id', $user->getKey())
            ->whereDoesntHave('catalogueMapping', fn (Builder $mapping) => $mapping->whereNotNull('catalogue_item_id'))
            ->when(trim($search) !== '', function (Builder $query) use ($search): void {
                $term = '%'.trim($search).'%';
                $query->where(fn (Builder $match) => $match
                    ->where('name', 'like', $term)
                    ->orWhere('barcode', 'like', $term));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'legacyPage');
    }

    /** @return Builder<CatalogueItem> */
    private function visibleQuery(?User $user): Builder
    {
        $query = CatalogueItem::query()
            ->join(
                'legacy_ingredient_catalogue_mappings',
                'legacy_ingredient_catalogue_mappings.catalogue_item_id',
                '=',
                'catalogue_items.id',
            )
            ->select('catalogue_items.*')
            ->addSelect('legacy_ingredient_catalogue_mappings.legacy_snapshot as migration_snapshot');

        return $this->visibility->apply($query, $user);
    }

    /** @param Builder<CatalogueItem> $query */
    private function search(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        $term = '%'.$search.'%';

        return $query->where(function (Builder $match) use ($term): void {
            $match
                ->whereRaw(
                    "JSON_UNQUOTE(JSON_EXTRACT(legacy_ingredient_catalogue_mappings.legacy_snapshot, '$.name')) LIKE ?",
                    [$term],
                )
                ->orWhere('catalogue_items.barcode', 'like', $term);
        });
    }
}
