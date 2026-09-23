<?php

namespace App\Models;

use Database\Factories\NutritionTargetProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class NutritionTargetProfile extends Model
{
    /** @use HasFactory<NutritionTargetProfileFactory> */
    use HasFactory;

    public const DEFAULT_NAME = 'Daily targets';

    protected $fillable = ['name'];

    protected static function booted(): void
    {
        static::deleting(function (NutritionTargetProfile $profile): void {
            if ($profile->is_default) {
                throw ValidationException::withMessages([
                    'profile' => 'Choose another default profile before deleting this profile.',
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<NutritionTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(NutritionTarget::class);
    }
}
