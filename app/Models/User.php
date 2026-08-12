<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_administrator' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Determine whether the user has administrator access.
     */
    public function isAdministrator(): bool
    {
        return (bool) $this->is_administrator;
    }

    public function secondFactor(): HasOne
    {
        return $this->hasOne(SecondFactor::class);
    }

    public function secondFactorEnrollment(): HasOne
    {
        return $this->hasOne(SecondFactorEnrollment::class);
    }

    public function hasConfirmedSecondFactor(): bool
    {
        return $this->secondFactor()->whereNotNull('confirmed_at')->exists();
    }
}
