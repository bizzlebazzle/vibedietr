<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $expires_at
 * @property int $target_user_id
 * @property string $status
 * @property string $correlation_id
 */
final class AdministratorPromotionRequest extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
