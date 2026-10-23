<?php

namespace App\Models;

use App\Contracts\AllowsBranding;
use App\Contracts\Entitlements;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use HasUuids;

    protected $fillable = ['domain', 'timezone', 'public', 'track_outbound', 'track_downloads', 'track_forms', 'brand_name', 'brand_color', 'brand_logo', 'brand_logo_mime'];

    protected $hidden = ['brand_logo'];

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

    /** Whether this site may show its own branding on public pages (always true unless an extension limits it). */
    public function brandingAllowed(): bool
    {
        $entitlements = app(Entitlements::class);

        return ! ($entitlements instanceof AllowsBranding) || $entitlements->allowsBranding($this);
    }

    /**
     * The branding public pages should show, or null for the default Tidemark look. The logo is a data URI so it travels with
     * the site's own (already access-checked) response.
     *
     * @return array{name:?string,color:?string,logo:?string}|null
     */
    public function brand(bool $withLogo = true): ?array
    {
        if (! $this->brandingAllowed() || ! ($this->brand_name || $this->brand_color || $this->brand_logo)) {
            return null;
        }

        return [
            'name' => $this->brand_name,
            'color' => $this->brand_color,
            'logo' => $withLogo && $this->brand_logo ? 'data:'.$this->brand_logo_mime.';base64,'.$this->brand_logo : null,
            'has_logo' => (bool) $this->brand_logo,
        ];
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
