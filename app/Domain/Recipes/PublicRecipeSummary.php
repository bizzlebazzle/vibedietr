<?php

namespace App\Domain\Recipes;

use App\Models\ManagedRecipeTerm;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use Carbon\CarbonImmutable;
use LogicException;

final readonly class PublicRecipeSummary
{
    /** @param list<string> $tags @param list<array{category: string, name: string}> $classifications */
    private function __construct(
        public int $id,
        public string $title,
        public ?string $servings,
        public CarbonImmutable $finalizedAt,
        public array $tags,
        public array $classifications,
    ) {}

    public static function fromCurrentVersion(Recipe $recipe): self
    {
        $version = $recipe->currentVersion;
        if (! $recipe->isPubliclyViewable() || ! $version instanceof RecipeVersion) {
            throw new LogicException('A discovery summary requires a current public finalized version.');
        }
        $recipe->loadMissing(['publicTags:id,recipe_id,name', 'managedTerms:id,category,name']);

        return new self(
            id: (int) $recipe->getKey(),
            title: (string) ($version->snapshot['title'] ?? ''),
            servings: isset($version->snapshot['servings']) ? (string) $version->snapshot['servings'] : null,
            finalizedAt: $version->finalized_at,
            tags: $recipe->publicTags->pluck('name')->values()->all(),
            classifications: $recipe->managedTerms->map(fn (ManagedRecipeTerm $term): array => [
                'category' => $term->category->value,
                'name' => $term->name,
            ])->values()->all(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'servings' => $this->servings,
            'finalized_at' => $this->finalizedAt->toIso8601String(),
            'tags' => $this->tags,
            'classifications' => $this->classifications,
        ];
    }
}
