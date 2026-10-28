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

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'avatar'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'avatar_updated_at' => 'datetime',
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

    /** Teams this person belongs to. */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')->withTimestamps();
    }

    /** Organizations this person owns. */
    public function ownedOrganizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'owner_id');
    }

    /** Organizations this person is an admin of (they do not own them). */
    public function administeredOrganizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_user')->withTimestamps();
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /** Where the profile photo is served from (the version in the URL changes with each upload so browsers refetch it), or null. */
    public function avatarUrl(): ?string
    {
        return $this->avatar_mime ? url('/api/avatars/users/'.$this->id).'?v='.($this->avatar_updated_at?->timestamp ?? 0) : null;
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }
}
