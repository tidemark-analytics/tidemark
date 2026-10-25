<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A note on a day, shown on the graph ("launched v2", "newsletter went out"). Private notes are the author's own, team notes are for
 * everyone on the site, public notes are also shown on public dashboards and shared links.
 */
class Annotation extends Model
{
    use HasUuids;

    public const MAX_PER_SITE = 1000;

    public const VISIBILITIES = ['private', 'team', 'public'];

    protected $fillable = ['site_id', 'user_id', 'date', 'note', 'visibility'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    /** What a viewer may see: members get team and public notes plus their own private ones; public and shared views only public ones. */
    public function scopeVisibleTo(Builder $query, ?User $user, ?string $role): Builder
    {
        if ($role === null) {
            return $query->where('visibility', 'public');
        }

        return $query->where(fn ($q) => $q->whereIn('visibility', ['team', 'public'])->when($user, fn ($w) => $w->orWhere(fn ($x) => $x->where('visibility', 'private')->where('user_id', $user->id))));
    }

    /** Whether this person may change or delete the note: the author always, owners and admins for notes the whole site sees. */
    public function editableBy(?User $user, ?string $role): bool
    {
        if (! $user || $role === null) {
            return false;
        }

        return $this->user_id === $user->id || ($this->visibility !== 'private' && in_array($role, ['owner', 'admin'], true));
    }
}
