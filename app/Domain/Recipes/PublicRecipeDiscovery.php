<?php

namespace App\Domain\Recipes;

use App\Models\Recipe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use LogicException;

final class PublicRecipeDiscovery
{
    /** @return LengthAwarePaginator<int, PublicRecipeSummary> */
    public function paginate(string $search, int $perPage = 12): LengthAwarePaginator
    {
        $recipes = Recipe::query()
            ->publiclyViewable()
            ->with(['currentVersion', 'publicTags:id,recipe_id,name', 'managedTerms:id,category,name'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $pattern = '%'.mb_strtolower($search).'%';
                $query->where(function (Builder $matching) use ($pattern): void {
                    $matching->whereHas('currentVersion', function (Builder $version) use ($pattern): void {
                        match ($version->getModel()->getConnection()->getDriverName()) {
                            'mysql', 'mariadb' => $version->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.\"title\"'))) LIKE ?", [$pattern]),
                            'sqlite' => $version->whereRaw("LOWER(json_extract(snapshot, '$.title')) LIKE ?", [$pattern]),
                            'pgsql' => $version->whereRaw("LOWER(snapshot->>'title') LIKE ?", [$pattern]),
                            default => throw new LogicException('The configured database does not support recipe discovery.'),
                        };
                    })->orWhereHas('publicTags', fn (Builder $tags): Builder => $tags->whereRaw('LOWER(name) LIKE ?', [$pattern]))
                        ->orWhereHas('managedTerms', fn (Builder $terms): Builder => $terms->whereRaw('LOWER(name) LIKE ?', [$pattern]));
                });
            })
            ->orderByDesc('finalized_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return $recipes->through(fn (Recipe $recipe): PublicRecipeSummary => PublicRecipeSummary::fromCurrentVersion($recipe));
    }
}
