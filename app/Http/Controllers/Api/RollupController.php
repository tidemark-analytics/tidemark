<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Rollups: a read-only dashboard that adds up the data of several of a person's sites. A rollup is a site of its own (kind `rollup`) with
 * its own goals, funnels, segments and timezone, so the dashboard works as it does for any site; only the data comes from its members.
 */
class RollupController extends Controller
{
    private const RESERVED = ['sites', 'settings', 'login', 'register', 'share', 'install', 'api', 'js', 'assets', 'up', 'storage', 'build', 'rollups'];

    /** The sites a person could put in a rollup: the ones they belong to. */
    public function candidates(Request $r)
    {
        return ['data' => $this->reachable($r->user())->orderBy('domain')->get(['id', 'domain', 'timezone'])->all()];
    }

    public function store(Request $r)
    {
        $user = $r->user();
        if (! Site::rollupsAllowedFor($user)) {
            return response()->json(['error' => 'rollups_unavailable', 'message' => 'Rollups are included with Pro.'], 403);
        }
        $data = $r->validate(['name' => 'required|string|max:63', 'timezone' => 'nullable|string|max:64', 'all' => 'sometimes|boolean', 'sites' => 'sometimes|array|max:500', 'sites.*' => 'string']);
        $name = strtolower(trim($data['name']));
        if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $name)) {
            throw ValidationException::withMessages(['name' => 'Use letters, numbers and hyphens only, for example all-sites.']);
        }
        if (in_array($name, self::RESERVED, true) || Site::where('domain', $name)->exists()) {
            throw ValidationException::withMessages(['name' => 'That name is already taken.']);
        }
        $all = (bool) ($data['all'] ?? false);
        $members = $all ? collect() : $this->membersFor($user, (array) ($data['sites'] ?? []));
        if (! $all && $members->isEmpty()) {
            throw ValidationException::withMessages(['sites' => 'Choose at least one site, or include all of your sites.']);
        }
        $timezone = $data['timezone'] ?? null;
        if (! $timezone || ! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $timezone = $this->commonTimezone($all ? Site::real()->whereHas('members', fn ($q) => $q->where('users.id', $user->id)->where('site_user.role', 'owner'))->get() : $members);
        }

        $rollup = DB::transaction(function () use ($name, $timezone, $all, $members, $user) {
            $rollup = Site::create(['kind' => 'rollup', 'domain' => $name, 'timezone' => $timezone, 'rollup_all' => $all]);
            $rollup->members()->attach($user->id, ['role' => 'owner', 'id' => (string) Str::uuid()]);
            $rollup->rollupMembers()->sync($members->pluck('id')->all());

            return $rollup;
        });

        return response()->json(['data' => app(SiteController::class)->present($rollup, $user)], 201);
    }

    /** Change which sites a rollup adds up. */
    public function update(Request $r)
    {
        /** @var Site $rollup */
        $rollup = $r->attributes->get('site');
        abort_unless($rollup->isRollup(), 404, 'This site is not a rollup.');
        $data = $r->validate(['all' => 'sometimes|boolean', 'sites' => 'sometimes|array|max:500', 'sites.*' => 'string']);
        $owner = $rollup->members()->wherePivot('role', 'owner')->first();
        $all = (bool) ($data['all'] ?? $rollup->rollup_all);
        $members = $all ? collect() : $this->membersFor($owner, array_key_exists('sites', $data) ? $data['sites'] : $rollup->rollupMembers()->pluck('domain')->all());
        if (! $all && $members->isEmpty()) {
            throw ValidationException::withMessages(['sites' => 'Choose at least one site, or include all of your sites.']);
        }
        $rollup->update(['rollup_all' => $all]);
        $rollup->rollupMembers()->sync($members->pluck('id')->all());

        return ['data' => app(SiteController::class)->present($rollup->fresh(), $r->user(), $r->attributes->get('role'))];
    }

    private function reachable($user)
    {
        return Site::real()->whereHas('members', fn ($q) => $q->where('users.id', $user->id));
    }

    /** @param array<int,string> $domains */
    private function membersFor($user, array $domains)
    {
        $domains = array_map(fn ($d) => strtolower(trim($d)), $domains);

        return $this->reachable($user)->whereIn('domain', $domains)->get(['id', 'domain', 'timezone']);
    }

    /** The timezone most of the sites use, like Plausible does for its consolidated view. */
    private function commonTimezone($sites): string
    {
        $counts = $sites->pluck('timezone')->filter()->countBy()->sortDesc();

        return $counts->keys()->first() ?? 'UTC';
    }
}
