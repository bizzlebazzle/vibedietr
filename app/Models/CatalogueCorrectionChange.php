<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $proposal_id
 * @property string $field_key
 * @property array<string, mixed>|null $before_value
 * @property array<string, mixed>|null $proposed_value
 * @property array<string, mixed>|null $provenance
 */
class CatalogueCorrectionChange extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Correction changes are immutable.'));
        static::deleting(fn () => throw new LogicException('Correction changes are retained moderation evidence.'));
    }

    protected function casts(): array
    {
        return ['before_value' => 'array', 'proposed_value' => 'array', 'provenance' => 'array'];
    }

    public function proposal(): BelongsTo
    /** @return BelongsTo<CatalogueCorrectionProposal, $this> */
    {
        return $this->belongsTo(CatalogueCorrectionProposal::class, 'proposal_id');
    }
}
