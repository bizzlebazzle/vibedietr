<?php

namespace App\Http\Requests;

use App\Integrations\RecipeWebpages\WebpageUrlValidator;
use App\Models\RecipeImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreWebpageRecipeImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', RecipeImport::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source_url' => ['required', 'string', 'max:'.(int) config('production.imports.max_url_length', 2048)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $value = $this->input('source_url');
            if (! is_string($value)) {
                return;
            }
            try {
                app(WebpageUrlValidator::class)->validate($value);
            } catch (\InvalidArgumentException) {
                $validator->errors()->add('source_url', 'Only public HTTP/HTTPS recipe pages on ports 80 and 443 are supported.');
            }
        }];
    }
}
