<?php

namespace App\Security\Uploads;

use finfo;

final class ContentTypeInspector
{
    /** @var array<string, list<string>> */
    private const EXTENSION_MIMES = [
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown'],
        'html' => ['text/html', 'application/xhtml+xml'],
        'htm' => ['text/html', 'application/xhtml+xml'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'heic' => ['image/heic', 'image/heif'],
        'heif' => ['image/heic', 'image/heif'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'json' => ['application/json', 'text/plain'],
    ];

    public function inspect(string $path, ?string $browserMime = null, ?string $extension = null): ContentTypeInspection
    {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($detected) || $detected === '') {
            throw UploadValidationException::invalidContent();
        }

        $browser = $this->normalize($browserMime);
        $extension = $extension === null ? null : strtolower(ltrim($extension, '.'));
        $knownExtensionMimes = $extension === null ? null : (self::EXTENSION_MIMES[$extension] ?? null);
        $extensionMatches = $knownExtensionMimes === null || in_array($detected, $knownExtensionMimes, true);
        $browserMatches = $browser === null || $this->sameFamily($browser, $detected);

        return new ContentTypeInspection($detected, $browser, $extension, $extensionMatches && $browserMatches);
    }

    /** @param list<string> $allowedMimes */
    public function assertAccepted(ContentTypeInspection $inspection, array $allowedMimes = []): void
    {
        if (! $inspection->compatible
            || ($allowedMimes !== [] && ! in_array($inspection->detectedMime, $allowedMimes, true))) {
            throw UploadValidationException::invalidContent();
        }
    }

    private function normalize(?string $mime): ?string
    {
        $mime = strtolower(trim((string) $mime));

        return $mime === '' || $mime === 'application/octet-stream' ? null : explode(';', $mime, 2)[0];
    }

    private function sameFamily(string $declared, string $detected): bool
    {
        return $declared === $detected
            || (str_starts_with($declared, 'text/') && str_starts_with($detected, 'text/'));
    }
}
