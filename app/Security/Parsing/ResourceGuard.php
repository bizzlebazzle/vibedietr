<?php

namespace App\Security\Parsing;

final class ResourceGuard
{
    public function assertInput(string $input, ParsingBudget $budget): void
    {
        if (strlen($input) > $budget->maxBytes || mb_strlen($input) > $budget->maxChars) {
            throw new ResourceLimitException;
        }
    }

    public function assertItems(int $items, ParsingBudget $budget): void
    {
        if ($items < 0 || $items > $budget->maxItems) {
            throw new ResourceLimitException;
        }
    }

    public function assertDepth(int $depth, ParsingBudget $budget): void
    {
        if ($depth < 0 || $depth > $budget->maxDepth) {
            throw new ResourceLimitException;
        }
    }

    public function assertElapsed(int $startedAtNanoseconds, ParsingBudget $budget): void
    {
        if ((hrtime(true) - $startedAtNanoseconds) / 1_000_000 > $budget->maxMilliseconds) {
            throw new ResourceLimitException;
        }
    }
}
