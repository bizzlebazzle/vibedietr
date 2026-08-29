<?php

namespace App\Models;

use App\Domain\Catalogue\CatalogueItemOrigin;
use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Catalogue\CatalogueItemStatus;
use Carbon\CarbonImmutable;
use Database\Factories\CatalogueItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property CatalogueItemOrigin $origin
 * @property string|null $barcode
 * @property int|null $submitted_by_user_id
 * @property CatalogueItemSource $source
 * @property string|null $source_identifier
 * @property CarbonImmutable $introduced_at
 * @property CatalogueItemStatus $status
 * @property string|null $current_catalogue_item_version_id
 */
class CatalogueItem extends Model
{
    /** @use HasFactory<CatalogueItemFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::saving(function (CatalogueItem $item): void {
            if (! $item->isDirty('current_catalogue_item_version_id')
                || $item->current_catalogue_item_version_id === null) {
                return;
            }

            $belongsToItem = CatalogueItemVersion::query()
                ->whereKey($item->current_catalogue_item_version_id)
                ->where('catalogue_item_id', $item->getKey())
                ->exists();

            if (! $belongsToItem) {
                throw new LogicException('The current catalogue version must belong to the catalogue item.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'origin' => CatalogueItemOrigin::class,
            'source' => CatalogueItemSource::class,
            'introduced_at' => 'immutable_datetime',
            'status' => CatalogueItemStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /** @return HasMany<CatalogueItemVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(CatalogueItemVersion::class);
    }

    /** @return BelongsTo<CatalogueItemVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogueItemVersion::class, 'current_catalogue_item_version_id');
    }

    public function setCurrentVersion(CatalogueItemVersion $version): void
    {
        if (! $version->exists || $version->catalogue_item_id !== $this->getKey()) {
            throw new LogicException('The current catalogue version must belong to the catalogue item.');
        }

        $this->current_catalogue_item_version_id = $version->getKey();
        $this->save();
    }
}
