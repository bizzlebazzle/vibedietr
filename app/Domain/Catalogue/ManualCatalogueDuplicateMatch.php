<?php

namespace App\Domain\Catalogue;

final readonly class ManualCatalogueDuplicateMatch
{
    public function __construct(
        public int $itemId,
        public string $versionId,
        public string $name,
        public CatalogueDuplicateEvidence $evidence,
    ) {}

    /** @return array{item_id:int, version_id:string, name:string, evidence:string} */
    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'version_id' => $this->versionId,
            'name' => $this->name,
            'evidence' => $this->evidence->value,
        ];
    }
}
