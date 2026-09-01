<?php

namespace App\Models;

use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Catalogue\PackageStructure;
use App\Domain\Catalogue\ServingAmountBasis;
use App\Domain\Measurements\StandardUnit;
use Database\Factories\CatalogueItemVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $catalogue_item_id
 * @property int $version_number
 * @property string|null $name
 * @property list<string>|null $keywords
 * @property list<string>|null $categories
 * @property string|null $image_url
 * @property int|null $package_count
 * @property string|null $item_type
 * @property string|null $amount_per_item
 * @property StandardUnit|null $amount_per_item_unit
 * @property string|null $servings_per_item
 * @property string|null $serving_amount
 * @property StandardUnit|null $serving_amount_unit
 * @property ServingAmountBasis|null $serving_amount_basis
 * @property CatalogueItemSource|null $name_source
 * @property CatalogueItemSource|null $keywords_source
 * @property CatalogueItemSource|null $categories_source
 * @property CatalogueItemSource|null $package_source
 * @property CatalogueItemSource|null $serving_source
 * @property CatalogueItemSource|null $image_source
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
            'keywords' => 'array',
            'categories' => 'array',
            'package_count' => 'integer',
            'amount_per_item' => 'decimal:18',
            'amount_per_item_unit' => StandardUnit::class,
            'servings_per_item' => 'decimal:18',
            'serving_amount' => 'decimal:18',
            'serving_amount_unit' => StandardUnit::class,
            'serving_amount_basis' => ServingAmountBasis::class,
            'name_source' => CatalogueItemSource::class,
            'keywords_source' => CatalogueItemSource::class,
            'categories_source' => CatalogueItemSource::class,
            'package_source' => CatalogueItemSource::class,
            'serving_source' => CatalogueItemSource::class,
            'image_source' => CatalogueItemSource::class,
        ];
    }

    /** @return BelongsTo<CatalogueItem, $this> */
    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(CatalogueItem::class);
    }

    /** @return HasMany<CatalogueNutrientObservation, $this> */
    public function nutrientObservations(): HasMany
    {
        return $this->hasMany(CatalogueNutrientObservation::class);
    }

    /** @return HasMany<CatalogueNutrientValue, $this> */
    public function nutrientValues(): HasMany
    {
        return $this->hasMany(CatalogueNutrientValue::class);
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
