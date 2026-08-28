<?php

namespace App\Domain\RecipeImports\Images;

final readonly class CanonicalImage
{
    public function __construct(
        public string $bytes,
        public string $mime,
        public int $width,
        public int $height,
        public string $preprocessingVersion,
    ) {}
}
