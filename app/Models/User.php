<?php

namespace App\Models;

use App\Administrator\AdministratorPrivilegeMutation;
use App\Administrator\LastAdministratorGuard;
use Database\Factories\UserFactory;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use LogicException;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
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

    protected static function booted(): void
    {
        $protectPrivilege = function (User $user): void {
            if ($user->isDirty('is_administrator')
                && ! AdministratorPrivilegeMutation::isAuthorized()
                && ! app()->environment('testing')) {
                throw new LogicException('Administrator status may only be changed by the approved lifecycle services.');
            }
        };

        static::creating($protectPrivilege);
        static::updating($protectPrivilege);
        static::deleting(function (User $user): void {
            if ($user->isAdministrator()) {
                app(LastAdministratorGuard::class)->assertAccountDeletionAllowed($user);
            }
        });
    }

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

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    /** @return HasMany<Bookmark, $this> */
    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class);
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
