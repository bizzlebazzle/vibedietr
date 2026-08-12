<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityNotificationIntent extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['destination_version', 'idempotency_key', 'provider_reference'];

    protected function casts(): array
    {
        return ['provider_accepted_at' => 'immutable_datetime', 'terminal_at' => 'immutable_datetime'];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
