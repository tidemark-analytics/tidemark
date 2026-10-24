<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A saved set of dashboard filters. A site segment is shared with everyone who can see the site, a personal one only with its creator. */
class Segment extends Model
{
    use HasUuids;

    public const MAX_PER_SITE = 100;

    protected $fillable = ['site_id', 'user_id', 'type', 'name', 'filters'];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }

    /** Segments a viewer may use: all site segments, plus their own personal ones when signed in. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return $query->where(fn ($q) => $q->where('type', 'site')->when($user, fn ($w) => $w->orWhere(fn ($x) => $x->where('type', 'personal')->where('user_id', $user->id))));
    }
}
