<?php

namespace App\Domain\Catalogue;

use App\Integrations\OpenFoodFacts\OpenFoodFactsCatalogueMapper;
use App\Integrations\OpenFoodFacts\OpenFoodFactsClient;
use App\Integrations\OpenFoodFacts\OpenFoodFactsLookupResult;
use App\Integrations\OpenFoodFacts\OpenFoodFactsLookupStatus;
use App\Models\CatalogueItem;
use App\Models\User;
use App\Observability\OperationalTelemetry;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

final readonly class ImportBarcodeIntoCatalogue
{
    public function __construct(
        private OpenFoodFactsCatalogueMapper $mapper,
        private CatalogueImportCreator $creator,
        private OperationalTelemetry $telemetry,
    ) {}

    public function findExisting(string $barcode): ?CatalogueItem
    {
        return CatalogueItem::query()
            ->where('barcode', Barcode::normalize($barcode))
            ->first();
    }

    public function import(
        User $submitter,
        string $requestedBarcode,
        OpenFoodFactsLookupResult $result,
    ): CatalogueBarcodeImportResult {
        $barcode = Barcode::normalize($requestedBarcode);
        $product = $result->product;

        if ($result->status !== OpenFoodFactsLookupStatus::Success
            || $product === null
            || Barcode::normalize($product->code) !== $barcode
        ) {
            throw new InvalidArgumentException('A consistent successful provider result is required.');
        }

        $existing = $this->findExisting($barcode);

        if ($existing !== null) {
            $result = $this->existingResult($existing);
            $this->observe($result);

            return $result;
        }

        $mapped = $this->mapper->map($product);

        try {
            $result = $this->creator->createOrReuse($submitter, $barcode, $mapped);
        } catch (QueryException $exception) {
            $winner = CatalogueItem::query()->where('barcode', $barcode)->first();

            if ($winner === null) {
                throw $exception;
            }

            $result = $this->existingResult($winner);
        }

        $this->observe($result);

        return $result;
    }

    private function existingResult(CatalogueItem $item): CatalogueBarcodeImportResult
    {
        $status = $item->status === CatalogueItemStatus::Approved
            ? CatalogueBarcodeImportStatus::Reused
            : CatalogueBarcodeImportStatus::Unavailable;

        return new CatalogueBarcodeImportResult($status, $status === CatalogueBarcodeImportStatus::Reused ? $item : null);
    }

    private function observe(CatalogueBarcodeImportResult $result): void
    {
        $this->telemetry->counter('catalogue.barcode_import', [
            'provider' => OpenFoodFactsClient::PROVIDER,
            'result' => $result->status->value,
        ]);
    }
}
