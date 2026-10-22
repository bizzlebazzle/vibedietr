<?php

namespace App\Domain\RecipeImports\Images;

interface ImageCanonicalizerContract
{
    public function canonicalize(string $bytes, string $extension, string $detectedMime): CanonicalImage;
}
