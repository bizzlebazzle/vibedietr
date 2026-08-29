<?php

namespace App\Models;

use Database\Factories\CatalogueItemVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $catalogue_item_id
 * @property int $version_number
 */
class CatalogueItemVersion extends Model
{
    /** @use HasFactory<CatalogueItemVersionFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['version_number' => 'integer'];
    }

    /** @return BelongsTo<CatalogueItem, $this> */
    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(CatalogueItem::class);
    }
}
