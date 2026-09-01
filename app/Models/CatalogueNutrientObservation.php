<?php

namespace App\Models;

use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientBasis;
use App\Domain\Nutrition\NutrientProvenance;
use App\Domain\Nutrition\NutrientUnit;
use App\Domain\Nutrition\NutrientValueStatus;
use Carbon\CarbonImmutable;
use Database\Factories\CatalogueNutrientObservationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $catalogue_item_version_id
 * @property Nutrient $nutrient
 * @property NutrientBasis $basis
 * @property string|null $value
 * @property string|null $threshold_value
 * @property NutrientUnit $unit
 * @property NutrientValueStatus $status
 * @property NutrientProvenance $provenance
 * @property CatalogueItemSource|null $source
 * @property string|null $source_field
 * @property int|null $source_scale
 * @property bool $precision_reduced
 * @property CarbonImmutable|null $source_observed_at
 * @property CarbonImmutable|null $imported_at
 * @property int $normalization_policy_version
 */
class CatalogueNutrientObservation extends Model
{
    /** @use HasFactory<CatalogueNutrientObservationFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::saving(function (self $observation): void {
            if ($observation->exists) {
                throw new LogicException('Catalogue nutrient observations are immutable.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Catalogue nutrient observations are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'nutrient' => Nutrient::class,
            'basis' => NutrientBasis::class,
            'value' => 'decimal:18',
            'threshold_value' => 'decimal:18',
            'unit' => NutrientUnit::class,
            'status' => NutrientValueStatus::class,
            'provenance' => NutrientProvenance::class,
            'source' => CatalogueItemSource::class,
            'source_scale' => 'integer',
            'precision_reduced' => 'boolean',
            'source_observed_at' => 'immutable_datetime',
            'imported_at' => 'immutable_datetime',
            'normalization_policy_version' => 'integer',
        ];
    }

    /** @return BelongsTo<CatalogueItemVersion, $this> */
    public function catalogueItemVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogueItemVersion::class);
    }
}
