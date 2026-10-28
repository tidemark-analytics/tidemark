<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who sites belong to. It has a name, one owner (who also holds the plan and billing on Cloud) and any number of admins who manage it with
 * the owner. Teams and sites belong to it. People are not "accounts"; they sign in, and belong to organizations through these roles.
 */
class Organization extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'owner_id', 'personal', 'transfer_to', 'transfer_expires_at'];

    protected function casts(): array
    {
        return ['transfer_expires_at' => 'datetime', 'personal' => 'boolean'];
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

    /** The Personal organization every person has: created with their sign-in (or the first time something needs it). */
    public static function personalFor(User $user): self
    {
        return static::where('owner_id', $user->id)->where('personal', true)->first()
            ?? static::create(['owner_id' => $user->id, 'name' => 'Personal', 'personal' => true]);
    }

    /** Organizations people create themselves (not their Personal one) that they own. */
    public static function ownedCount(User $user): int
    {
        return static::where('owner_id', $user->id)->where('personal', false)->count();
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    public function isAdministeredBy(User $user): bool
    {
        return $this->admins()->where('users.id', $user->id)->exists();
    }

    /**
     * Whether this person may move a site out of $this and into $to: true, or the reason it is refused.
     * They must run both organizations (owner or admin). Taking a site out is the sensitive half, so unless both organizations have the
     * same owner, only the source's owner may do it: an admin cannot walk a site out of the owner's organization into one with another owner.
     */
    public function mayMoveSiteTo(self $to, User $user): true|string
    {
        if ($this->id === $to->id) {
            return 'The site is already in this organization.';
        }
        $runs = fn (self $o) => $o->isOwnedBy($user) || $o->isAdministeredBy($user);
        if (! $runs($to)) {
            return 'You need to be an owner or admin of the organization you move it to.';
        }
        if (! $runs($this)) {
            return 'You need to be an owner or admin of the organization it is in.';
        }
        if ($this->owner_id !== $to->owner_id && ! $this->isOwnedBy($user)) {
            return 'Only the owner of '.$this->name.' can move a site to an organization with a different owner.';
        }

        return true;
    }

    /** Ids of every organization this person owns or administers. */
    public static function idsFor(User $user): array
    {
        return static::where('owner_id', $user->id)->orWhereIn('id', \DB::table('organization_user')->where('user_id', $user->id)->select('organization_id'))->pluck('id')->all();
    }
}
