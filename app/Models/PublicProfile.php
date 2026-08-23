<?php

namespace App\Models;

use Database\Factories\PublicProfileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicProfile extends Model
{
    /** @use HasFactory<PublicProfileFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return [
            'profile_enabled' => 'boolean',
            'show_public_recipes' => 'boolean',
            'show_public_remixes' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
