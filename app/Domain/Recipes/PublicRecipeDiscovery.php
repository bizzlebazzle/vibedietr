<?php

namespace App\Domain\Recipes;

use App\Models\Recipe;
use Illuminate\Pagination\LengthAwarePaginator;
use LogicException;

final class PublicRecipeDiscovery
{
    /** @return LengthAwarePaginator<int, PublicRecipeSummary> */
    public function paginate(string $search, int $perPage = 12): LengthAwarePaginator
    {
        $recipes = Recipe::query()
            ->publiclyViewable()
            ->with('currentVersion')
            ->when($search !== '', function ($query) use ($search): void {
                $query->whereHas('currentVersion', function ($version) use ($search): void {
                    $pattern = '%'.mb_strtolower($search).'%';

                    match ($version->getModel()->getConnection()->getDriverName()) {
                        'mysql', 'mariadb' => $version->whereRaw(
                            'LOWER(JSON_UNQUOTE(JSON_EXTRACT(snapshot, \'$."title"\'))) LIKE ?',
                            [$pattern],
                        ),
                        'sqlite' => $version->whereRaw(
                            'LOWER(json_extract(snapshot, \'$.title\')) LIKE ?',
                            [$pattern],
                        ),
                        'pgsql' => $version->whereRaw(
                            "LOWER(snapshot->>'title') LIKE ?",
                            [$pattern],
                        ),
                        default => throw new LogicException('The configured database does not support recipe title discovery.'),
                    };
                });
            })
            ->orderByDesc('finalized_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return $recipes->through(
            fn (Recipe $recipe): PublicRecipeSummary => PublicRecipeSummary::fromCurrentVersion($recipe),
        );
    }
}
