<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SecondFactor extends Model
{
    use HasUlids;

    protected $guarded = ['id', 'encrypted_secret'];

    protected $hidden = ['encrypted_secret'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'immutable_datetime', 'recovery_codes_acknowledged_at' => 'immutable_datetime', 'locked_until' => 'immutable_datetime', 'last_consumed_timestep' => 'integer', 'consecutive_failures' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(SecondFactorRecoveryCode::class, 'factor_id');
    }
}
