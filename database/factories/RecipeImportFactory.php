<?php

namespace Database\Factories;

use App\Domain\RecipeImports\Parsing\DeterministicRecipeTextParser;
use App\Domain\RecipeImports\RecipeImportStatus;
use App\Domain\RecipeImports\RecipeImportType;
use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Jobs\ProcessWebpageRecipeImport;
use App\Models\Recipe;
use App\Models\RecipeImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<RecipeImport> */
class RecipeImportFactory extends Factory
{
    public function definition(): array
    {
        $id = (string) Str::ulid();

        return [
            'id' => $id,
            'user_id' => User::factory(),
            'type' => RecipeImportType::PastedText,
            'source_format' => 'plain_text',
            'source_text' => "Synthetic Soup\nIngredients\n1 cup lentils\nInstructions\n1. Simmer gently.",
            'status' => RecipeImportStatus::Pending,
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => 'recipe_import.process|'.$id,
            'requires_review' => true,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => RecipeImportStatus::Processing,
            'started_at' => now()->utc(),
        ]);
    }

    public function webpage(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => RecipeImportType::WebpageUrl,
            'source_format' => 'html',
            'source_text' => null,
            'submitted_url' => 'https://recipes.example.test/synthetic-soup',
            'idempotency_key' => ProcessWebpageRecipeImport::OPERATION_TYPE.'|'.$attributes['id'],
        ]);
    }

    public function reviewReady(): static
    {
        return $this->state(fn (): array => [
            'status' => RecipeImportStatus::ReviewReady,
            'parser_identifier' => DeterministicRecipeTextParser::IDENTIFIER,
            'parser_version' => 'rec15-v1',
            'completion_classification' => 'reviewable',
            'provenance' => ['channel' => 'pasted_text', 'parser' => DeterministicRecipeTextParser::IDENTIFIER, 'parser_version' => 'rec15-v1'],
            'completed_at' => now()->utc(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => RecipeImportStatus::Failed,
            'failure_category' => 'permanent',
            'failure_code' => 'recipe_structure_not_found',
            'failed_at' => now()->utc(),
        ]);
    }

    public function ambiguous(): static
    {
        return $this->reviewReady()->state(fn (): array => [
            'warnings' => ['servings_uncertain', 'extraction_incomplete'],
            'completion_classification' => 'reviewable_with_strong_warnings',
        ]);
    }

    public function withDraft(): static
    {
        return $this->reviewReady()->afterCreating(function (RecipeImport $import): void {
            $recipe = Recipe::factory()->for($import->owner, 'owner')->create([
                'lifecycle' => RecipeLifecycle::Draft,
                'visibility' => RecipeVisibility::Private,
            ]);
            $import->forceFill(['recipe_id' => $recipe->getKey()])->save();
        });
    }
}
