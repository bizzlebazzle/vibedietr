<?php

namespace App\Logging;

use App\Security\Redaction\SensitiveDataRedactor;
use Monolog\Logger;
use Monolog\LogRecord;

final class RedactSensitiveContext
{
    public function __invoke(Logger $logger): void
    {
        $redactor = app(SensitiveDataRedactor::class);
        $logger->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
            context: $redactor->redact($record->context),
            extra: $redactor->redact($record->extra),
        ));
    }
}
