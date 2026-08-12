<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SecondFactorEnrollment extends Model
{
    use HasUlids;

    protected $guarded = ['id', 'encrypted_secret'];

    protected $hidden = ['encrypted_secret'];

    protected function casts(): array
    {
        return ['verified_timestep' => 'integer', 'recovery_codes_generated_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(SecondFactorRecoveryCode::class, 'enrollment_id');
    }
}
