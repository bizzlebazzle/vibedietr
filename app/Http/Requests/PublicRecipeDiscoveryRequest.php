<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublicRecipeDiscoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'view' => ['sometimes', 'string', 'in:discover,bookmarks,mine,drafts'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function search(): string
    {
        return trim((string) ($this->validated('q') ?? ''));
    }
}
