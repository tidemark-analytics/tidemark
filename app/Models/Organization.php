<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Who sites belong to. It has a name, one owner (who also holds the plan and billing on Cloud) and any number of admins who manage it with
 * the owner. Teams and sites belong to it. People are not "accounts"; they sign in, and belong to organizations through these roles.
 */
class Organization extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'owner_id', 'transfer_to', 'transfer_expires_at'];

    protected function casts(): array
    {
        return ['transfer_expires_at' => 'datetime'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user')->withTimestamps();
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function transferTarget(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transfer_to');
    }

    /** A pending, unexpired offer of ownership. */
    public function hasPendingTransfer(): bool
    {
        return $this->transfer_to !== null && $this->transfer_expires_at?->isFuture() === true;
    }

    public static function defaultName(User $user): string
    {
        return Str::limit($user->name."'s organization", 100, '');
    }

    /** The organization this person owns, created on first need (sign-up, a first site) so nobody has to set one up before they start. */
    public static function forOwner(User $user, ?string $name = null): self
    {
        $org = static::where('owner_id', $user->id)->orderBy('created_at')->first();
        if ($org) {
            return $org;
        }

        return static::create(['owner_id' => $user->id, 'name' => trim((string) $name) !== '' ? trim((string) $name) : static::defaultName($user)]);
    }

    /** Ids of every organization this person owns or administers. */
    public static function idsFor(User $user): array
    {
        return static::where('owner_id', $user->id)->orWhereIn('id', \DB::table('organization_user')->where('user_id', $user->id)->select('organization_id'))->pluck('id')->all();
    }
}
