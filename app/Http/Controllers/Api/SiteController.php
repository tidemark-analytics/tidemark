<?php

namespace App\Http\Controllers\Api;

use App\Analytics\Stats\Stats;
use App\Analytics\Support\Sql;
use App\Analytics\Support\UrlParams;
use App\Contracts\Entitlements;
use App\Contracts\LimitsSiteList;
use App\Events\SiteCreated;
use App\Http\Controllers\Controller;
use App\Models\FunnelStep;
use App\Models\Goal;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SiteController extends Controller
{
    /** The user's sites with their last-24h numbers (the "My personal sites" screen). */
    public function index(Request $request)
    {
        $user = $request->user();
        $entitlements = app(Entitlements::class);
        $otherPath = $entitlements instanceof LimitsSiteList ? $entitlements->otherSitesPath($user) : null;
        // an extension can keep an administrator's list to their own sites; the rest are browsed elsewhere
        $query = $otherPath !== null ? Site::query()->whereHas('members', fn ($q) => $q->where('users.id', $user->id)) : Site::visibleTo($user)->where(fn ($q) => $q->where('kind', '!=', 'rollup')->orWhereHas('members', fn ($m) => $m->where('users.id', $user->id)));
        $sites = $query->orderBy('domain')->get()->map(function (Site $s) use ($user) {
            $r = $s->only(['id', 'domain', 'public']) + ['role' => $s->roleOf($user), 'created_at' => $s->created_at, 'rollup' => $s->isRollup()] + ($s->isRollup() ? ['members' => count($s->memberSiteIds())] : []);
            $r += (new Stats($s))->last24h();

            return $r;
        });

        return ['data' => $sites, 'other_sites' => $otherPath ? ['path' => $otherPath, 'count' => max(0, Site::count() - $sites->count())] : null];
    }

    public static function normalizeDomain(string $raw): string
    {
        $d = strtolower(trim($raw));
        $d = preg_replace('#^https?://#', '', $d);
        $d = preg_replace('#^www\.#', '', $d);

        return rtrim(explode('/', $d)[0], '.');
    }

    public function store(Request $request)
    {
        $data = $request->validate(['domain' => 'required|string|max:200', 'timezone' => 'nullable|string|max:64']);
        $domain = self::normalizeDomain($data['domain']);
        if (! preg_match('/^(localhost|[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+)(:\d+)?$/', $domain)) {
            throw ValidationException::withMessages(['domain' => 'Enter just the domain, e.g. example.com (no “https://”, no path).']);
        }
        if (in_array($domain, ['sites', 'settings', 'login', 'register', 'share', 'install', 'api', 'js', 'assets', 'up', 'admin'], true) || Site::where('domain', $domain)->exists()) {
            throw ValidationException::withMessages(['domain' => 'This domain is already being tracked.']);
        }
        $tz = $data['timezone'] ?? 'UTC';
        if (! in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            $tz = 'UTC';
        }
        $user = $request->user();
        if (config('tidemark.verify_email') && ! $user->hasVerifiedEmail()) {
            return response()->json(['error' => 'email_unverified', 'message' => 'Verify your email address before adding a site. We sent you a link.'], 403);
        }
        $allowed = app(Entitlements::class)->canCreateSite($user);
        if ($allowed !== true) {
            throw ValidationException::withMessages(['domain' => $allowed]);
        }

        $site = Site::create(['domain' => $domain, 'timezone' => $tz]);
        $site->members()->attach($user->id, ['role' => 'owner', 'id' => (string) Str::uuid()]);
        SiteCreated::dispatch($site, $user);

        return response()->json(['data' => $this->present($site->fresh(), $user)], 201); // fresh, so columns that take their default in the database are filled in
    }

    public function show(Request $request)
    {
        return ['data' => $this->present($request->attributes->get('site'), $request->user(), $request->attributes->get('role'))];
    }

    public function update(Request $request)
    {
        $site = $request->attributes->get('site');
        $data = $request->validate([
            'timezone' => ['sometimes', 'string', fn ($a, $v, $fail) => in_array($v, \DateTimeZone::listIdentifiers(), true) || $fail('Unknown timezone')],
            'public' => 'sometimes|boolean',
            'track_outbound' => 'sometimes|boolean', 'track_downloads' => 'sometimes|boolean', 'track_forms' => 'sometimes|boolean',
            'honor_gpc' => 'sometimes|boolean', 'honor_dnt' => 'sometimes|boolean', 'referrer_domain_only' => 'sometimes|boolean', 'pii_scrub' => 'sometimes|boolean',
            'location_precision' => 'sometimes|in:city,region,country',
            'min_group_size' => ['sometimes', 'integer', 'between:0,1000', fn ($a, $v, $fail) => (int) $v === 1 ? $fail('Use 0 to turn this off, or at least 2.') : null],
            'url_params' => ['sometimes', 'nullable', 'string', 'max:2000', fn ($a, $v, $fail) => ($problem = UrlParams::problem($v)) ? $fail($problem) : null],
        ]);
        if (array_key_exists('url_params', $data)) {
            $data['url_params'] = UrlParams::normalize($data['url_params']);
        }
        $site->update($data);
        // the default tracking extensions need matching goals so their events show up in the dashboard
        foreach (Site::extensionGoals() as $flag => $goalName) {
            if (! empty($data[$flag])) {
                Goal::firstOrCreate(['site_id' => $site->id, 'type' => 'event', 'value' => $goalName]);
            }
        }
        Cache::forget('tdm:site:'.$site->domain);

        return ['data' => $this->present($site->fresh(), $request->user(), $request->attributes->get('role'))];
    }

    public function destroy(Request $request)
    {
        $site = $request->attributes->get('site');
        $this->wipe($site->id);
        PlausibleImportController::clear($site);
        FunnelStep::whereIn('funnel_id', $site->funnels()->pluck('id'))->delete();
        DB::table('annotations')->where('site_id', $site->id)->delete();
        DB::table('report_subscriptions')->where('site_id', $site->id)->delete();
        DB::table('alert_rules')->where('site_id', $site->id)->delete();
        DB::table('alert_webhooks')->where('site_id', $site->id)->delete();
        foreach (['funnels', 'segments', 'goals', 'sharedLinks', 'shields'] as $rel) {
            $site->{$rel}()->delete();
        }
        $site->members()->detach();
        Cache::forget('tdm:site:'.$site->domain);
        $site->delete();

        return ['ok' => true];
    }

    /** Delete all collected data, keep the site. */
    public function reset(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_if($site->isRollup(), 422, 'A rollup has no data of its own to reset. Reset its sites instead.');
        $this->wipe($site->id);
        PlausibleImportController::clear($site);

        return ['ok' => true];
    }

    private function wipe(string $id): void
    {
        DB::table('pii_masks')->where('site_id', $id)->delete();
        $db = Sql::connection();
        foreach (['event_props', 'events', 'visits'] as $t) {
            $db->table($t)->where('site_id', $id)->delete();
        }
    }

    public function present(Site $site, $user = null, ?string $role = null): array
    {
        $role ??= $site->roleOf($user);
        $first = (new Stats($site))->firstVisitAt();

        return $site->only(['id', 'domain', 'timezone', 'public', 'track_outbound', 'track_downloads', 'track_forms', 'honor_gpc', 'honor_dnt', 'location_precision', 'url_params', 'referrer_domain_only', 'min_group_size', 'pii_scrub']) + ['rollup' => $site->isRollup() ? ['all' => $site->rollup_all, 'sites' => $site->rollupMembers()->orderBy('domain')->get(['sites.id', 'sites.domain'])->map->only(['id', 'domain'])->all(), 'count' => count($site->memberSiteIds())] : null] + [
            'role' => $role,
            'can_manage' => in_array($role, ['owner', 'admin'], true),
            'is_owner' => $role === 'owner',
            'script' => $site->scriptName(),
            'has_data' => $first !== null,
            'first_visit_at' => $first?->toIso8601String(),
            'brand' => $site->brand(),
            'features' => ['funnels' => $site->funnelsAllowed(), 'revenue' => $site->revenueAllowed(), 'segments' => $site->segmentsAllowed(), 'journeys' => $site->journeysAllowed()],
            'imported' => $site->imported_from ? ['from' => $site->imported_from, 'start' => $site->imported_start?->toDateString(), 'end' => $site->imported_end?->toDateString()] : null,
        ] + (in_array($role, ['owner', 'admin'], true) ? ['branding' => [
            'allowed' => $site->brandingAllowed(), 'name' => $site->brand_name, 'color' => $site->brand_color, 'has_logo' => (bool) $site->brand_logo, 'hide_name' => (bool) $site->brand_hide_name,
        ]] : []);
    }
}
