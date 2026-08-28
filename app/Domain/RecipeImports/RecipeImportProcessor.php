<?php

namespace App\Domain\RecipeImports;

use App\Domain\RecipeImports\Parsing\RecipeTextParser;
use App\Models\Recipe;
use App\Models\RecipeImport;
use Illuminate\Support\Facades\DB;

final class RecipeImportProcessor
{
    public function __construct(
        private readonly RecipeTextParser $parser,
        private readonly RecipeImportMaterializer $materializer,
    ) {}

    public function process(string $importId): ?Recipe
    {
        $import = DB::transaction(function () use ($importId): ?RecipeImport {
            $import = RecipeImport::query()->lockForUpdate()->find($importId);
            if ($import === null || $import->status === RecipeImportStatus::ReviewReady) {
                return $import;
            }

            $import->forceFill([
                'status' => RecipeImportStatus::Processing,
                'started_at' => $import->started_at ?? now()->utc(),
                'failed_at' => null,
            ])->save();

            return $import;
        });

        if ($import === null) {
            return null;
        }
        if ($import->status === RecipeImportStatus::ReviewReady) {
            return $import->recipe_id === null ? null : Recipe::query()->find($import->recipe_id);
        }

        return $this->materializer->materialize($importId, $this->parser->parse((string) $import->source_text));
    }
}
