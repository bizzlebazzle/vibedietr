<?php

namespace App\Models;

use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Catalogue\CatalogueProviderRefreshState;
use Carbon\CarbonImmutable;
use Database\Factories\CatalogueProviderRefreshFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * @property string $id
 * @property int $catalogue_item_id
 * @property string $base_catalogue_item_version_id
 * @property CatalogueItemSource $provider
 * @property string $source_identifier
 * @property string $correlation_id
 * @property CatalogueProviderRefreshState $state
 * @property string|null $active_key
 * @property string|null $failure_code
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property-read CatalogueItem $catalogueItem
 * @property-read CatalogueItemVersion $baseVersion
 * @property-read CatalogueCorrectionProposal|null $proposal
 */
class CatalogueProviderRefresh extends Model
{
    /** @use HasFactory<CatalogueProviderRefreshFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (self $refresh): void {
            $allowed = ['state', 'active_key', 'failure_code', 'started_at', 'completed_at', 'updated_at'];

            if (array_diff(array_keys($refresh->getDirty()), $allowed) !== []) {
                throw new LogicException('Provider refresh identity and base evidence are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Provider refresh history is retained operational evidence.'));
    }

    protected function casts(): array
    {
        return [
            'provider' => CatalogueItemSource::class,
            'state' => CatalogueProviderRefreshState::class,
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<CatalogueItem, $this> */
    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(CatalogueItem::class);
    }

    /** @return BelongsTo<CatalogueItemVersion, $this> */
    public function baseVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogueItemVersion::class, 'base_catalogue_item_version_id');
    }

    /** @return HasOne<CatalogueCorrectionProposal, $this> */
    public function proposal(): HasOne
    {
        return $this->hasOne(CatalogueCorrectionProposal::class, 'provider_refresh_id');
    }
}
