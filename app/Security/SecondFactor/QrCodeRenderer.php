<?php

namespace App\Security\SecondFactor;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use SensitiveParameter;

final class QrCodeRenderer
{
    public function render(#[SensitiveParameter] string $provisioningUri): string
    {
        $renderer = new ImageRenderer(new RendererStyle(240, 2), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($provisioningUri);
    }
}
