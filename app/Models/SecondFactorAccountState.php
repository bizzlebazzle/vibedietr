<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecondFactorAccountState extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['consecutive_failures' => 'integer', 'next_attempt_at' => 'immutable_datetime', 'locked_until' => 'immutable_datetime'];
    }
}
