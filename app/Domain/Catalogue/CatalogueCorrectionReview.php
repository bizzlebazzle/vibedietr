<?php

namespace App\Domain\Catalogue;

use App\Models\CatalogueCorrectionProposal;

final readonly class CatalogueCorrectionReview
{
    /** @param list<array{field:string,before:mixed,current:mixed,proposed:mixed,conflict:bool}> $changes */
    public function __construct(
        public CatalogueCorrectionProposal $proposal,
        public ?string $currentVersionId,
        public bool $stale,
        public array $changes,
    ) {}

    /** @return list<string> */
    public function conflicts(): array
    {
        return array_values(array_map(
            fn (array $change): string => $change['field'],
            array_filter($this->changes, fn (array $change): bool => $change['conflict']),
        ));
    }
}
