<?php

namespace App\Domain\Recipes;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use Carbon\CarbonImmutable;
use LogicException;

final readonly class PublicRecipe
{
    /**
     * @param  list<array{position: int, original_text: string, quantity: string|null, standard_unit: string|null, custom_unit: string|null, generic_wording: string|null, notes: string|null}>  $ingredients
     * @param  list<array{position: int, text: string, section: string|null}>  $instructions
     */
    private function __construct(
        public int $id,
        public string $title,
        public ?string $servings,
        public RecipeVisibility $visibility,
        public string $versionId,
        public int $versionNumber,
        public CarbonImmutable $finalizedAt,
        public array $ingredients,
        public array $instructions,
    ) {}

    public static function fromCurrentVersion(Recipe $recipe): self
    {
        $version = $recipe->currentVersion;

        if (! $recipe->isFinalized() || ! $version instanceof RecipeVersion) {
            throw new LogicException('A public recipe projection requires a current finalized version.');
        }

        $snapshot = $version->snapshot;
        $sectionNames = collect($snapshot['sections'] ?? [])->mapWithKeys(
            fn (array $section): array => [(string) ($section['key'] ?? '') => (string) ($section['name'] ?? '')],
        );

        $ingredients = collect($snapshot['ingredients'] ?? [])->map(
            fn (array $ingredient): array => [
                'position' => (int) ($ingredient['position'] ?? 0),
                'original_text' => (string) ($ingredient['original_text'] ?? ''),
                'quantity' => isset($ingredient['quantity']) ? (string) $ingredient['quantity'] : null,
                'standard_unit' => isset($ingredient['standard_unit']) ? (string) $ingredient['standard_unit'] : null,
                'custom_unit' => isset($ingredient['custom_unit']) ? (string) $ingredient['custom_unit'] : null,
                'generic_wording' => isset($ingredient['generic_wording']) ? (string) $ingredient['generic_wording'] : null,
                'notes' => isset($ingredient['notes']) ? (string) $ingredient['notes'] : null,
            ],
        )->sortBy('position')->values()->all();

        $instructions = collect($snapshot['steps'] ?? [])->map(
            fn (array $step): array => [
                'position' => (int) ($step['position'] ?? 0),
                'text' => (string) ($step['text'] ?? ''),
                'section' => isset($step['section_key'])
                    ? $sectionNames->get((string) $step['section_key'])
                    : null,
            ],
        )->sortBy('position')->values()->all();

        return new self(
            id: (int) $recipe->getKey(),
            title: (string) ($snapshot['title'] ?? ''),
            servings: isset($snapshot['servings']) ? (string) $snapshot['servings'] : null,
            visibility: RecipeVisibility::from((string) $recipe->getRawOriginal('visibility')),
            versionId: (string) $version->getKey(),
            versionNumber: $version->version_number,
            finalizedAt: $version->finalized_at,
            ingredients: $ingredients,
            instructions: $instructions,
        );
    }

    /** @return array{id: int, title: string, servings: string|null, visibility: string, version: array{id: string, number: int, finalized_at: string}, ingredients: list<array{position: int, original_text: string, quantity: string|null, standard_unit: string|null, custom_unit: string|null, generic_wording: string|null, notes: string|null}>, instructions: list<array{position: int, text: string, section: string|null}>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'servings' => $this->servings,
            'visibility' => $this->visibility->value,
            'version' => [
                'id' => $this->versionId,
                'number' => $this->versionNumber,
                'finalized_at' => $this->finalizedAt->toIso8601String(),
            ],
            'ingredients' => $this->ingredients,
            'instructions' => $this->instructions,
        ];
    }
}
