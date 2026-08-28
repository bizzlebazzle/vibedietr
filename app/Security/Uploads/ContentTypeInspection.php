<?php

namespace App\Security\Uploads;

final readonly class ContentTypeInspection
{
    public function __construct(
        public string $detectedMime,
        public ?string $browserMime,
        public ?string $extension,
        public bool $compatible,
    ) {}
}
