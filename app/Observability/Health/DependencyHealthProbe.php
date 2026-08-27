<?php

namespace App\Observability\Health;

interface DependencyHealthProbe
{
    /** @return list<HealthCheckResult> */
    public function check(): array;
}
