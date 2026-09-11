<?php

namespace App\Domain\Catalogue;

use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class CatalogueReadQuery
{
    public function __construct(private readonly CatalogueVisibility $visibility) {}

    /** @return LengthAwarePaginator<int, CatalogueItemReadModel> */
    public function paginate(?User $user, string $search, int $perPage = 12): LengthAwarePaginator
    {
        $paginator = $this->search($this->visibleQuery($user, discovery: true), $search)
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
        $source = CatalogueItem::query()->whereKey($id)->where('status', CatalogueItemStatus::Merged)->first();
        if ($source !== null) {
            $canonical = app(CatalogueCanonicalResolver::class)->resolve($source);
            abort_if($canonical === null, 404);
            $id = (int) $canonical->getKey();
        }

        return $this->visibleQuery($user, discovery: false)->findOrFail($id);
    }

    public function findVisibleByBarcode(?User $user, string $barcode): ?CatalogueItem
    {
        return $this->visibleQuery($user, discovery: true)
            ->where('catalogue_items.barcode', $barcode)
            ->first();
    }

    /** @return LengthAwarePaginator<int, CatalogueMatchCandidate> */
    public function paginateSelectable(User $user, string $search, int $page = 1, int $perPage = 8): LengthAwarePaginator
    {
        $paginator = $this->search($this->selectableQuery($user), $search)
            ->whereNotNull('catalogue_items.current_catalogue_item_version_id')
            ->orderByDesc('catalogue_items.introduced_at')
            ->orderByDesc('catalogue_items.id')
            ->paginate($perPage, ['*'], 'catalogueMatchPage', $page);

        return $paginator->through(function (Model $item): CatalogueMatchCandidate {
            assert($item instanceof CatalogueItem);

            return CatalogueMatchCandidate::fromCatalogueItem($item);
        });
    }

    public function findSelectableCurrentVersion(
        User $user,
        int $itemId,
        string $versionId,
        bool $lock = false,
    ): ?CatalogueItemVersion {
        $query = $this->selectableQuery($user)
            ->where('catalogue_items.id', $itemId)
            ->where('catalogue_items.current_catalogue_item_version_id', $versionId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $item = $query->first();

        return $item?->currentVersion;
    }

    /**
     * @param  list<int>  $itemIds
     * @return Collection<int, CatalogueItem>
     */
    public function eligibleAutomaticItems(User $user, array $itemIds, bool $lock = false): Collection
    {
        if ($itemIds === []) {
            return new Collection;
        }

        $query = $this->selectableQuery($user)
            ->where('catalogue_items.status', CatalogueItemStatus::Approved)
            ->whereNotNull('catalogue_items.current_catalogue_item_version_id')
            ->whereIn('catalogue_items.id', $itemIds)
            ->orderBy('catalogue_items.id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
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
    private function visibleQuery(?User $user, bool $discovery): Builder
    {
        $query = CatalogueItem::query()
            ->with([
                'currentVersion.nutrientValues.sourceObservation',
                'suggestedReplacement.currentVersion',
            ])
            ->leftJoin(
                'legacy_ingredient_catalogue_mappings',
                'legacy_ingredient_catalogue_mappings.catalogue_item_id',
                '=',
                'catalogue_items.id',
            )
            ->select('catalogue_items.*')
            ->addSelect('legacy_ingredient_catalogue_mappings.legacy_snapshot as migration_snapshot');
        $query->leftJoin(
            'catalogue_item_versions as current_catalogue_item_versions',
            'current_catalogue_item_versions.id',
            '=',
            'catalogue_items.current_catalogue_item_version_id',
        );

        return $discovery
            ? $this->visibility->applyDiscovery($query, $user)
            : $this->visibility->apply($query, $user);
    }

    /** @return Builder<CatalogueItem> */
    private function selectableQuery(User $user): Builder
    {
        $query = CatalogueItem::query()
            ->with('currentVersion')
            ->leftJoin(
                'legacy_ingredient_catalogue_mappings',
                'legacy_ingredient_catalogue_mappings.catalogue_item_id',
                '=',
                'catalogue_items.id',
            )
            ->leftJoin(
                'catalogue_item_versions as current_catalogue_item_versions',
                'current_catalogue_item_versions.id',
                '=',
                'catalogue_items.current_catalogue_item_version_id',
            )
            ->select('catalogue_items.*')
            ->addSelect('legacy_ingredient_catalogue_mappings.legacy_snapshot as migration_snapshot');

        return $this->visibility->applySelectable($query, $user);
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
                ->where('current_catalogue_item_versions.name', 'like', $term)
                ->orWhereRaw(
                    "JSON_UNQUOTE(JSON_EXTRACT(legacy_ingredient_catalogue_mappings.legacy_snapshot, '$.name')) LIKE ?",
                    [$term],
                )
                ->orWhere('catalogue_items.barcode', 'like', $term)
                ->orWhereHas('aliases', fn (Builder $alias) => $alias->whereNull('disabled_at')->where('alias', 'like', $term));
        });
    }
}
