<?php

namespace App\Security\SecondFactor;

final readonly class EnrollmentPresentation
{
    public function __construct(public string $manualKey, public string $provisioningUri, public string $qrSvg) {}
}
