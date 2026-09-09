<?php

namespace App\Models;

use App\Domain\Catalogue\CatalogueName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogueItemAlias extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::saving(function (CatalogueItemAlias $alias): void {
            $alias->alias = CatalogueName::display($alias->alias);
            $alias->normalized_alias = CatalogueName::normalize($alias->alias);
        });
    }

    protected function casts(): array
    {
        return ['approved_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<CatalogueItem, $this> */
    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(CatalogueItem::class);
    }
}
