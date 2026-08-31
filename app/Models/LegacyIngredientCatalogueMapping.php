<?php

namespace App\Models;

use App\Domain\Catalogue\LegacyIngredientClassification;
use App\Domain\Catalogue\LegacyIngredientReviewReason;
use Carbon\CarbonImmutable;
use Database\Factories\LegacyIngredientCatalogueMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ingredient_id
 * @property int|null $legacy_user_id
 * @property int|null $catalogue_item_id
 * @property LegacyIngredientClassification $classification
 * @property LegacyIngredientReviewReason|null $review_reason
 * @property array<string, mixed> $legacy_snapshot
 * @property string $legacy_checksum
 * @property int $backfill_version
 * @property CarbonImmutable $backfilled_at
 */
class LegacyIngredientCatalogueMapping extends Model
{
    /** @use HasFactory<LegacyIngredientCatalogueMappingFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'classification' => LegacyIngredientClassification::class,
            'review_reason' => LegacyIngredientReviewReason::class,
            'legacy_snapshot' => 'array',
            'backfill_version' => 'integer',
            'backfilled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function legacyUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'legacy_user_id');
    }

    /** @return BelongsTo<CatalogueItem, $this> */
    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(CatalogueItem::class);
    }
}
