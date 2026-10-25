<?php

namespace App\Models;

use App\Contracts\AllowsBranding;
use App\Contracts\AllowsConversionReports;
use App\Contracts\AllowsExploration;
use App\Contracts\AllowsRollups;
use App\Contracts\Entitlements;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use HasUuids;

    protected $fillable = ['kind', 'rollup_all', 'domain', 'timezone', 'public', 'track_outbound', 'track_downloads', 'track_forms', 'brand_name', 'brand_hide_name', 'brand_color', 'brand_logo', 'brand_logo_mime', 'honor_gpc', 'honor_dnt', 'location_precision', 'url_params', 'referrer_domain_only', 'min_group_size', 'pii_scrub', 'bot_filter'];

    protected $hidden = ['brand_logo'];

    protected function casts(): array
    {
        return ['rollup_all' => 'boolean', 'public' => 'boolean', 'track_outbound' => 'boolean', 'track_downloads' => 'boolean', 'track_forms' => 'boolean', 'brand_hide_name' => 'boolean', 'honor_gpc' => 'boolean', 'honor_dnt' => 'boolean', 'referrer_domain_only' => 'boolean', 'pii_scrub' => 'boolean', 'bot_filter' => 'boolean', 'imported_start' => 'date', 'imported_end' => 'date'];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function isRollup(): bool
    {
        return $this->kind === 'rollup';
    }

    /** Sites that collect their own data, as opposed to rollups. */
    public function scopeReal($query)
    {
        return $query->where('kind', '!=', 'rollup');
    }

    public function scopeRollups($query)
    {
        return $query->where('kind', 'rollup');
    }

    /** The sites a rollup adds up (chosen ones, or all of the owner's sites). */
    public function rollupMembers(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'rollup_sites', 'rollup_id', 'site_id');
    }

    /**
     * The ids whose data this dashboard shows: its own for a site; for a rollup the member sites that its owner still belongs to (for "all", owns), so
     * a rollup never reaches data its owner can no longer see. A rollup with no members shows nothing.
     *
     * @return array<int,string>
     */
    public function memberSiteIds(): array
    {
        if (! $this->isRollup()) {
            return [$this->id];
        }
        $ownerId = $this->members()->wherePivot('role', 'owner')->value('users.id');
        if (! $ownerId) {
            return [];
        }
        // "all" means the sites the owner owns, so an operator's rollup never takes in customers' sites they can merely open; chosen sites
        // can be any the owner belongs to, because that is a deliberate pick
        $reachable = Site::real()->whereHas('members', fn ($q) => $q->where('users.id', $ownerId)->when($this->rollup_all, fn ($w) => $w->where('site_user.role', 'owner')));
        if (! $this->rollup_all) {
            $reachable->whereIn('id', $this->rollupMembers()->pluck('sites.id'));
        }

        return $reachable->pluck('id')->all();
    }

    /** Whether this person may create rollups (always true unless an extension limits it). */
    public static function rollupsAllowedFor(User $user): bool
    {
        $entitlements = app(Entitlements::class);

        return ! ($entitlements instanceof AllowsRollups) || $entitlements->allowsRollups($user);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function funnels(): HasMany
    {
        return $this->hasMany(Funnel::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(Segment::class);
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

    /** Whether this site may use funnels (always true unless an extension limits it). */
    public function funnelsAllowed(): bool
    {
        $entitlements = app(Entitlements::class);

        return ! ($entitlements instanceof AllowsConversionReports) || $entitlements->allowsFunnels($this);
    }

    /** Whether this site may report revenue on its goals (always true unless an extension limits it). */
    public function revenueAllowed(): bool
    {
        $entitlements = app(Entitlements::class);

        return ! ($entitlements instanceof AllowsConversionReports) || $entitlements->allowsRevenue($this);
    }

    /** Whether this site may save and use segments (always true unless an extension limits it). */
    public function segmentsAllowed(): bool
    {
        $entitlements = app(Entitlements::class);

        return ! ($entitlements instanceof AllowsExploration) || $entitlements->allowsSegments($this);
    }

    /** Whether this site may use the journeys explorer (always true unless an extension limits it). */
    public function journeysAllowed(): bool
    {
        $entitlements = app(Entitlements::class);

        return ! ($entitlements instanceof AllowsExploration) || $entitlements->allowsJourneys($this);
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
     * `hide_name` only applies when there is a logo: the name then becomes the logo's alt text instead of visible text.
     *
     * @return array{name:?string,color:?string,logo:?string,has_logo:bool,hide_name:bool}|null
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
            'hide_name' => (bool) $this->brand_hide_name && (bool) $this->brand_logo,
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
