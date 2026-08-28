<?php

namespace App\Http\Controllers;

use App\Domain\RecipeImports\RecipeImportStatus;
use App\Domain\RecipeImports\RecipeImportType;
use App\Http\Requests\StorePastedRecipeImportRequest;
use App\Http\Requests\StoreUploadedRecipeImportRequest;
use App\Http\Requests\StoreWebpageRecipeImportRequest;
use App\Jobs\ProcessPastedRecipeImport;
use App\Jobs\ProcessUploadedRecipeImport;
use App\Jobs\ProcessWebpageRecipeImport;
use App\Models\RecipeImport;
use App\Models\User;
use App\Observability\CorrelationContext;
use App\Security\Uploads\TransientInputStore;
use App\Security\Uploads\UploadValidationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    public function storeWebpage(
        StoreWebpageRecipeImportRequest $request,
        CorrelationContext $correlation,
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }
        $import = new RecipeImport;
        $import->id = (string) Str::ulid();
        $import->forceFill([
            'type' => RecipeImportType::WebpageUrl,
            'source_format' => 'html',
            'source_text' => null,
            'submitted_url' => $request->validated('source_url'),
            'status' => RecipeImportStatus::Pending,
            'correlation_id' => $correlation->get(),
            'idempotency_key' => ProcessWebpageRecipeImport::OPERATION_TYPE.'|'.$import->id,
            'requires_review' => true,
        ]);
        $import->owner()->associate($user);
        $import->save();
        ProcessWebpageRecipeImport::dispatch($import->id, $import->correlation_id);

        return redirect()->route('recipe-imports.show', $import)
            ->with('status', 'Webpage import submitted. Fetching and extraction will run in the background.');
    }

    public function storeUpload(
        StoreUploadedRecipeImportRequest $request,
        CorrelationContext $correlation,
        TransientInputStore $inputs,
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }
        $extension = $request->extension();
        $document = $request->isDocument();
        $allowedMimes = $document
            ? ($extension === 'html' ? ['text/html', 'application/xhtml+xml'] : ['text/plain', 'text/markdown'])
            : match ($extension) {
                'jpg', 'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'heic', 'heif' => ['image/heic', 'image/heif'],
                default => throw ValidationException::withMessages(['recipe_file' => 'The selected recipe file type is unsupported.']),
            };
        try {
            $handle = $inputs->store(
                $request->file('recipe_file'),
                $document ? StoreUploadedRecipeImportRequest::DOCUMENT_MAX_BYTES : StoreUploadedRecipeImportRequest::IMAGE_MAX_BYTES,
                $allowedMimes,
            );
        } catch (UploadValidationException $exception) {
            throw ValidationException::withMessages(['recipe_file' => $exception->getMessage()]);
        }

        try {
            $import = new RecipeImport;
            $import->id = (string) Str::ulid();
            $import->forceFill([
                'type' => $document ? RecipeImportType::UploadedText : RecipeImportType::UploadedImage,
                'source_format' => match ($extension) {
                    'txt' => 'plain_text', 'md' => 'markdown', default => $extension,
                },
                'source_disk' => $handle->disk, 'source_key' => $handle->key,
                'source_mime' => $handle->detectedMime, 'source_bytes' => $handle->bytes,
                'source_extension' => $extension, 'source_stored_at' => now()->utc(),
                'status' => RecipeImportStatus::Pending,
                'correlation_id' => $correlation->get(),
                'idempotency_key' => ProcessUploadedRecipeImport::OPERATION_TYPE.'|'.$import->id,
                'requires_review' => true,
            ]);
            $import->owner()->associate($user);
            $import->save();
            ProcessUploadedRecipeImport::dispatch($import->id, $import->correlation_id);
        } catch (\Throwable $exception) {
            $inputs->cleanup($handle);
            throw $exception;
        }

        return redirect()->route('recipe-imports.show', $import)
            ->with('status', 'Recipe upload submitted. Private extraction will run in the background.');
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

        match ($import->type) {
            RecipeImportType::PastedText => ProcessPastedRecipeImport::dispatch($import->id, $import->correlation_id),
            RecipeImportType::WebpageUrl => ProcessWebpageRecipeImport::dispatch($import->id, $import->correlation_id),
            RecipeImportType::UploadedText,
            RecipeImportType::UploadedImage => abort(409, 'A cleaned upload must be submitted again.'),
        };

        return redirect()->route('recipe-imports.show', $import)
            ->with('status', 'Recipe import retry queued.');
    }
}
