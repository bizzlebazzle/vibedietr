<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

final class StoreUploadedRecipeImportRequest extends FormRequest
{
    public const DOCUMENT_MAX_BYTES = 2_097_152;

    public const IMAGE_MAX_BYTES = 20_971_520;

    private const DOCUMENT_EXTENSIONS = ['txt', 'md', 'html'];

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'heic', 'heif'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'recipe_file' => ['required', 'file', 'max:20480'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (count($this->allFiles()) !== 1) {
                $validator->errors()->add('recipe_file', 'Upload exactly one file.');
            }

            $file = $this->file('recipe_file');
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                return;
            }

            $extension = strtolower($file->getClientOriginalExtension());
            if (! in_array($extension, [...self::DOCUMENT_EXTENSIONS, ...self::IMAGE_EXTENSIONS], true)) {
                $validator->errors()->add('recipe_file', 'This file type is not supported.');

                return;
            }

            $bytes = $file->getSize();
            $document = in_array($extension, self::DOCUMENT_EXTENSIONS, true);
            $limit = $document ? self::DOCUMENT_MAX_BYTES : self::IMAGE_MAX_BYTES;
            if (! is_int($bytes) || $bytes > $limit) {
                $validator->errors()->add('recipe_file', $document
                    ? 'Documents must be no larger than 2 MiB.'
                    : 'Images must be no larger than 20 MiB.');
            }

            $browserMime = strtolower((string) $file->getClientMimeType());
            $families = $document ? ['text/', 'application/xhtml+xml'] : ['image/'];
            if ($browserMime !== '' && $browserMime !== 'application/octet-stream'
                && collect($families)->doesntContain(fn (string $family): bool => str_starts_with($browserMime, $family))) {
                $validator->errors()->add('recipe_file', 'The file extension and reported content type do not agree.');
            }
        }];
    }

    public function extension(): string
    {
        return strtolower($this->file('recipe_file')->getClientOriginalExtension());
    }

    public function isDocument(): bool
    {
        return in_array($this->extension(), self::DOCUMENT_EXTENSIONS, true);
    }
}
