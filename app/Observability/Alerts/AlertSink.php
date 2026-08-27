<?php

namespace App\Observability\Alerts;

interface AlertSink
{
    public function send(string $category, string $state, array $context = []): void;
}
