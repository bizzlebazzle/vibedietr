<?php

namespace App\Http\Requests;

use App\Models\RecipeImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePastedRecipeImportRequest extends FormRequest
{
    public const MAX_SOURCE_BYTES = 2_097_152;

    public function authorize(): bool
    {
        return $this->user()?->can('create', RecipeImport::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source_text' => ['required', 'string'],
            'source_format' => ['required', Rule::in(['plain_text', 'markdown', 'html'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $source = $this->input('source_text');
            if (! is_string($source)) {
                return;
            }
            if (trim($source) === '') {
                $validator->errors()->add('source_text', 'Paste some recipe text to import.');
            }
            if (strlen($source) > self::MAX_SOURCE_BYTES) {
                $validator->errors()->add('source_text', 'The pasted recipe must not exceed 2 MiB.');
            }
            foreach (preg_split('/\R/u', $source) ?: [] as $line) {
                if (mb_strlen($line) > 10_000) {
                    $validator->errors()->add('source_text', 'Each source line must not exceed 10,000 characters.');
                    break;
                }
            }
        }];
    }
}
