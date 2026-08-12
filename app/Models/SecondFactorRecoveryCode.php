<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SecondFactorRecoveryCode extends Model
{
    use HasUlids;

    protected $guarded = ['id', 'code_hash'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['used_at' => 'immutable_datetime'];
    }
}
