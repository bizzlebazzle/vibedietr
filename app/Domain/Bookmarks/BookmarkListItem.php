<?php

namespace App\Domain\Bookmarks;

use App\Domain\Recipes\PublicRecipeSummary;
use App\Models\Bookmark;
use Carbon\CarbonImmutable;

final readonly class BookmarkListItem
{
    private function __construct(
        public int $id,
        public int $recipeId,
        public CarbonImmutable $bookmarkedAt,
        public ?PublicRecipeSummary $publicRecipe,
    ) {}

    public static function fromBookmark(Bookmark $bookmark, ?PublicRecipeSummary $publicRecipe): self
    {
        return new self(
            id: (int) $bookmark->getKey(),
            recipeId: (int) $bookmark->recipe_id,
            bookmarkedAt: CarbonImmutable::instance($bookmark->created_at),
            publicRecipe: $publicRecipe,
        );
    }

    public function isAvailable(): bool
    {
        return $this->publicRecipe !== null;
    }

    /** @return array{id: int, recipe_id: int, bookmarked_at: string, available: bool, recipe: array{id: int, title: string, servings: string|null, finalized_at: string}|null} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'recipe_id' => $this->recipeId,
            'bookmarked_at' => $this->bookmarkedAt->toIso8601String(),
            'available' => $this->isAvailable(),
            'recipe' => $this->publicRecipe?->toArray(),
        ];
    }
}
