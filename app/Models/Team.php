<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/** A named group of people an organization shares sites with in one step. The role (viewer or admin) is set per site, not per team. */
class Team extends Model
{
    use HasUuids;

    protected $fillable = ['organization_id', 'name'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->withTimestamps();
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_team')->withPivot('role')->withTimestamps();
    }

    /** Removes the team, what it shared and its pending invitations; nobody loses access they have in another way. */
    public function erase(): void
    {
        DB::table('site_team')->where('team_id', $this->id)->delete();
        DB::table('team_user')->where('team_id', $this->id)->delete();
        Invitation::where('team_id', $this->id)->delete();
        $this->delete();
    }
}
