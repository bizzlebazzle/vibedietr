<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int $user_id
 * @property string $account_generation
 * @property string $state
 * @property string $reason
 * @property string $operation_id
 * @property string $correlation_id
 * @property CarbonImmutable $deadline
 * @property CarbonImmutable $requested_at
 * @property array<string, mixed>|null $recovery_state
 */
final class AccountDeletion extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected $hidden = ['recovery_state', 'user_id', 'account_generation'];

    protected function casts(): array
    {
        return [
            'deadline' => 'immutable_datetime',
            'requested_at' => 'immutable_datetime',
            'recovery_state' => 'encrypted:array',
        ];
    }
}
