<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CatalogueReferenceMove extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Reference migration evidence is append-only.'));
        static::deleting(fn () => throw new LogicException('Reference migration evidence is append-only.'));
    }
}
