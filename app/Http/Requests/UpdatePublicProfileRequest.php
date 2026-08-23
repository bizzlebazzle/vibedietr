<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePublicProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'profile_enabled' => $this->boolean('profile_enabled'),
            'show_public_recipes' => $this->boolean('show_public_recipes'),
            'show_public_remixes' => $this->boolean('show_public_remixes'),
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'attribution_name' => [
                Rule::requiredIf(fn (): bool => $this->boolean('profile_enabled')),
                'nullable',
                'string',
                'max:80',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    if (trim($value) === '') {
                        $fail('The public attribution name cannot be blank.');
                    }

                    if (str_contains($value, '<') || str_contains($value, '>')) {
                        $fail('The public attribution name cannot contain HTML.');
                    }

                    if (filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false) {
                        $fail('Email addresses cannot be used as public attribution.');
                    }
                },
            ],
            'profile_enabled' => ['required', 'boolean'],
            'show_public_recipes' => ['required', 'boolean'],
            'show_public_remixes' => ['required', 'boolean'],
            'user_id' => ['prohibited'],
            'owner_id' => ['prohibited'],
            'public_profile_id' => ['prohibited'],
            'email' => ['prohibited'],
        ];
    }
}
