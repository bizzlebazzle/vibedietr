<?php

namespace App\Http\Controllers;

use App\Domain\RecipeImports\RecipeImportStatus;
use App\Domain\RecipeImports\RecipeImportType;
use App\Http\Requests\StorePastedRecipeImportRequest;
use App\Jobs\ProcessPastedRecipeImport;
use App\Models\RecipeImport;
use App\Models\User;
use App\Observability\CorrelationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecipeImportController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', RecipeImport::class);

        return view('recipe-imports.create');
    }

    public function store(
        StorePastedRecipeImportRequest $request,
        CorrelationContext $correlation,
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $import = new RecipeImport;
        $import->id = (string) Str::ulid();
        $import->forceFill([
            'type' => RecipeImportType::PastedText,
            'source_format' => $request->validated('source_format'),
            'source_text' => $request->validated('source_text'),
            'status' => RecipeImportStatus::Pending,
            'correlation_id' => $correlation->get(),
            'idempotency_key' => 'recipe_import.process|'.$import->id,
            'requires_review' => true,
        ]);
        $import->owner()->associate($user);
        $import->save();

        ProcessPastedRecipeImport::dispatch($import->id, $import->correlation_id);

        return redirect()->route('recipe-imports.show', $import)
            ->with('status', 'Recipe import submitted. Parsing will run in the background.');
    }

    public function show(RecipeImport $recipeImport): View
    {
        $this->authorize('view', $recipeImport);
        $recipeImport->load('recipe');

        return view('recipe-imports.show', ['import' => $recipeImport]);
    }

    public function retry(Request $request, RecipeImport $recipeImport): RedirectResponse
    {
        $this->authorize('retry', $recipeImport);

        $import = DB::transaction(function () use ($recipeImport): RecipeImport {
            $import = RecipeImport::query()->lockForUpdate()->findOrFail($recipeImport->id);
            $this->authorize('retry', $import);
            $import->forceFill([
                'status' => RecipeImportStatus::Pending,
                'failure_category' => null,
                'failure_code' => null,
                'failed_at' => null,
                'manual_retry_count' => $import->manual_retry_count + 1,
            ])->save();

            return $import;
        });

        ProcessPastedRecipeImport::dispatch($import->id, $import->correlation_id);

        return redirect()->route('recipe-imports.show', $import)
            ->with('status', 'Recipe import retry queued.');
    }
}
