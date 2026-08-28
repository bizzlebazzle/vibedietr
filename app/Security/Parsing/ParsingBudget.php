<?php

namespace App\Security\Parsing;

final readonly class ParsingBudget
{
    public function __construct(
        public int $maxBytes,
        public int $maxChars,
        public int $maxItems,
        public int $maxDepth,
        public int $maxMilliseconds,
    ) {}

    public static function defaults(): self
    {
        return new self(
            (int) config('security.parsing.max_bytes'),
            (int) config('security.parsing.max_chars'),
            (int) config('security.parsing.max_items'),
            (int) config('security.parsing.max_depth'),
            (int) config('security.parsing.max_milliseconds'),
        );
    }
}
