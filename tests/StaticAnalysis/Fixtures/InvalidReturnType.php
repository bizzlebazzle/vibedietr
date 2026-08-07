<?php

declare(strict_types=1);

namespace StaticAnalysisFixture;

// This fixture is intentionally invalid and is excluded from normal analysis.
final class InvalidReturnType
{
    public function value(): int
    {
        return 'not an integer';
    }
}
