<?php

namespace App\Domain\Catalogue;

use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;

final readonly class CatalogueMatchCandidate
{
    public function __construct(
        public int $itemId,
        public string $versionId,
        public string $name,
        public ?string $barcode,
        public bool $pending,
    ) {}

    public static function fromCatalogueItem(CatalogueItem $item): self
    {
        $version = $item->currentVersion;
        assert($version instanceof CatalogueItemVersion);
        $projection = CatalogueItemReadModel::fromCatalogueItem($item);

        return new self(
            itemId: (int) $item->getKey(),
            versionId: (string) $version->getKey(),
            name: $projection->name,
            barcode: $projection->barcode,
            pending: $projection->pending,
        );
    }

    /** @return array{item_id: int, version_id: string, name: string, barcode: string|null, pending: bool} */
    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'version_id' => $this->versionId,
            'name' => $this->name,
            'barcode' => $this->barcode,
            'pending' => $this->pending,
        ];
    }
}
