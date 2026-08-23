<?php

namespace App\Models\Concerns;

trait NormalizesPrivateOrganizationName
{
    public function setNameAttribute(string $name): void
    {
        $trimmed = trim($name);
        $this->attributes['name'] = $trimmed;
        $this->attributes['normalized_name'] = mb_strtolower($trimmed);
    }
}
