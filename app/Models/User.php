<?php

namespace App\Models;

use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    // The MustVerifyEmail trait (without its interface) gives us hasVerifiedEmail() and the verification notification while
    // keeping verification opt-in: Laravel only auto-sends it for models that implement the interface.
    use HasUuids, MustVerifyEmail, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'timezone', 'locale', 'is_admin'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'two_factor_secret' => 'encrypted',
        ];
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class)->withPivot('role')->withTimestamps();
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }
}
