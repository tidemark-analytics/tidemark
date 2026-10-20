<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use HasUuids;

    protected $fillable = ['domain', 'timezone', 'public', 'track_outbound', 'track_downloads', 'track_forms'];

    protected function casts(): array
    {
        return ['public' => 'boolean', 'track_outbound' => 'boolean', 'track_downloads' => 'boolean', 'track_forms' => 'boolean'];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function sharedLinks(): HasMany
    {
        return $this->hasMany(SharedLink::class);
    }

    public function shields(): HasMany
    {
        return $this->hasMany(ShieldRule::class);
    }

    /** Sites a user may open: ones they are a member of, or everything for admins. */
    public function scopeVisibleTo($query, User $user)
    {
        return $user->is_admin ? $query : $query->whereHas('members', fn ($q) => $q->where('users.id', $user->id));
    }

    public function roleOf(?User $user): ?string
    {
        if (! $user) {
            return null;
        }
        $role = $this->members()->where('users.id', $user->id)->first()?->pivot->role;

        return $role ?? ($user->is_admin ? 'owner' : null);
    }

    /** Script file name for the snippet: Plausible-style extensions in the file name, e.g. script.outbound-links.js */
    public function scriptName(): string
    {
        $parts = array_filter([
            $this->track_outbound ? 'outbound-links' : null,
            $this->track_downloads ? 'file-downloads' : null,
            $this->track_forms ? 'form-submissions' : null,
        ]);

        return 'script'.($parts ? '.'.implode('.', $parts) : '').'.js';
    }

    /** Goals that the default tracking extensions need. */
    public static function extensionGoals(): array
    {
        return ['track_outbound' => 'Outbound Link: Click', 'track_downloads' => 'File Download', 'track_forms' => 'Form: Submission'];
    }
}
