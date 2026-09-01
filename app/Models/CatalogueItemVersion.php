<?php

namespace App\Models;

use App\Domain\Catalogue\PackageStructure;
use App\Domain\Catalogue\ServingAmountBasis;
use App\Domain\Measurements\StandardUnit;
use Database\Factories\CatalogueItemVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $catalogue_item_id
 * @property int $version_number
 * @property int|null $package_count
 * @property string|null $item_type
 * @property string|null $amount_per_item
 * @property StandardUnit|null $amount_per_item_unit
 * @property string|null $servings_per_item
 * @property string|null $serving_amount
 * @property StandardUnit|null $serving_amount_unit
 * @property ServingAmountBasis|null $serving_amount_basis
 */
class CatalogueItemVersion extends Model
{
    /** @use HasFactory<CatalogueItemVersionFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::saving(static function (CatalogueItemVersion $version): void {
            $version->packageStructure();
        });
    }

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'package_count' => 'integer',
            'amount_per_item' => 'decimal:18',
            'amount_per_item_unit' => StandardUnit::class,
            'servings_per_item' => 'decimal:18',
            'serving_amount' => 'decimal:18',
            'serving_amount_unit' => StandardUnit::class,
            'serving_amount_basis' => ServingAmountBasis::class,
        ];
    }

    /** @return BelongsTo<CatalogueItem, $this> */
    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(CatalogueItem::class);
    }

    public function packageStructure(): PackageStructure
    {
        return PackageStructure::fromPersisted(
            packageCount: $this->package_count,
            itemType: $this->item_type,
            amountPerItem: $this->amount_per_item,
            amountPerItemUnit: $this->amount_per_item_unit,
            servingsPerItem: $this->servings_per_item,
            servingAmount: $this->serving_amount,
            servingAmountUnit: $this->serving_amount_unit,
            servingAmountBasis: $this->serving_amount_basis,
        );
    }

    public function replacePackageStructure(PackageStructure $structure): void
    {
        $this->forceFill($structure->toAttributes());
        $this->save();
    }

    public function copyPackageStructureFrom(CatalogueItemVersion $version): void
    {
        $this->replacePackageStructure($version->packageStructure());
    }
}
