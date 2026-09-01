<?php

namespace App\Models;

use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientBasis;
use App\Domain\Nutrition\NutrientDerivation;
use App\Domain\Nutrition\NutrientNormalizationWarning;
use App\Domain\Nutrition\NutrientProvenance;
use App\Domain\Nutrition\NutrientUnit;
use App\Domain\Nutrition\NutrientValueStatus;
use Database\Factories\CatalogueNutrientValueFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $catalogue_item_version_id
 * @property string $source_observation_id
 * @property Nutrient $nutrient
 * @property NutrientBasis $basis
 * @property string|null $value
 * @property string|null $threshold_value
 * @property NutrientUnit $unit
 * @property NutrientValueStatus $status
 * @property NutrientProvenance $provenance
 * @property NutrientDerivation|null $derivation
 * @property NutrientNormalizationWarning|null $normalization_warning
 * @property int $normalization_policy_version
 */
class CatalogueNutrientValue extends Model
{
    /** @use HasFactory<CatalogueNutrientValueFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::saving(function (self $value): void {
            if ($value->exists) {
                throw new LogicException('Catalogue nutrient values are immutable.');
            }

            $belongsToVersion = CatalogueNutrientObservation::query()
                ->whereKey($value->source_observation_id)
                ->where('catalogue_item_version_id', $value->catalogue_item_version_id)
                ->exists();

            if (! $belongsToVersion) {
                throw new LogicException('The source observation must belong to the same catalogue version.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Catalogue nutrient values are immutable.');
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
            'derivation' => NutrientDerivation::class,
            'normalization_warning' => NutrientNormalizationWarning::class,
            'normalization_policy_version' => 'integer',
        ];
    }

    /** @return BelongsTo<CatalogueItemVersion, $this> */
    public function catalogueItemVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogueItemVersion::class);
    }

    /** @return BelongsTo<CatalogueNutrientObservation, $this> */
    public function sourceObservation(): BelongsTo
    {
        return $this->belongsTo(CatalogueNutrientObservation::class, 'source_observation_id');
    }
}
