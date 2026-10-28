<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Entitlements;
use App\Events\SiteMoved;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moving a site between organizations. Everything attached to the site (statistics, goals, shared links, alerts, direct people) goes with it;
 * what comes from the old organization does not: its admins lose their implicit access, and the teams it was shared with are unshared (teams
 * belong to an organization). The destination's admins gain access, and when the owner differs the site now counts against that owner's plan.
 */
class SiteMoveController extends Controller
{
    /** The organizations this person may move the site to, each with what the move would change, so they can see it before confirming. */
    public function show(Request $r)
    {
        $site = $r->attributes->get('site');
        $from = $this->source($site);
        Organization::personalFor($r->user());
        $mine = Organization::whereIn('id', Organization::idsFor($r->user()))->with('owner')->orderByDesc('personal')->orderBy('name')->get();

        return [
            'organization' => ['id' => $from->id, 'name' => $from->name, 'personal' => $from->personal],
            'destinations' => $mine->filter(fn ($o) => $from->mayMoveSiteTo($o, $r->user()) === true)->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'personal' => $o->personal] + ['impact' => $this->impact($site, $from, $o)])->values(),
        ];
    }

    public function move(Request $r)
    {
        $site = $r->attributes->get('site');
        $data = $r->validate(['organization' => 'required|string']);
        $from = $this->source($site);
        Organization::personalFor($r->user());
        $to = Organization::whereIn('id', Organization::idsFor($r->user()))->find($data['organization']);
        if (! $to) {
            throw ValidationException::withMessages(['organization' => 'Choose one of your organizations.']);
        }
        if (($why = $from->mayMoveSiteTo($to, $r->user())) !== true) {
            throw ValidationException::withMessages(['organization' => $why]);
        }
        $ownerChanges = $from->owner_id !== $to->owner_id;
        if ($ownerChanges && ($why = app(Entitlements::class)->canCreateSite(User::findOrFail($to->owner_id))) !== true) {
            throw ValidationException::withMessages(['organization' => $why]); // the new owner's plan has to have room for it
        }

        DB::transaction(function () use ($site, $to, $ownerChanges) {
            DB::table('site_team')->where('site_id', $site->id)->delete(); // teams belong to an organization, so the old organization's teams lose it
            if ($ownerChanges) {
                DB::table('site_user')->where('site_id', $site->id)->where('user_id', $to->owner_id)->where('role', '!=', 'owner')->delete();
                DB::table('site_user')->where('site_id', $site->id)->where('role', 'owner')->update(['user_id' => $to->owner_id, 'updated_at' => now()]);
            }
            $site->update(['organization_id' => $to->id]);
        });
        Cache::forget('tdm:site:'.$site->domain); // the collector caches who owns the site
        SiteMoved::dispatch($site->fresh(), $from, $to, $r->user());

        return ['ok' => true, 'organization' => ['id' => $to->id, 'name' => $to->name]];
    }

    private function source(Site $site): Organization
    {
        abort_if($site->isRollup(), 422, 'A rollup is private to its owner and has no organization.');

        return $site->organization ?? abort(422, 'This site is not in an organization yet.');
    }

    /** What changes for people: who loses access, who gains it, what stays. */
    private function impact(Site $site, Organization $from, Organization $to): array
    {
        $people = fn (Organization $o) => collect([$o->owner])->merge($o->admins)->filter()->unique('id');
        $direct = $site->members()->pluck('users.id')->all();
        $teamMembers = $site->teams()->with('members')->get()->flatMap->members->pluck('id')->all();
        $keeps = $people($to)->pluck('id')->merge($direct)->all();
        $lost = $people($from)->reject(fn ($u) => in_array($u->id, $keeps, true))->values();
        $gained = $people($to)->reject(fn ($u) => in_array($u->id, [...$direct, ...$people($from)->pluck('id')->all()], true))->values();

        return [
            'teams' => $site->teams()->withCount('members')->get()->map(fn ($t) => ['name' => $t->name, 'members' => $t->members_count, 'role' => $t->pivot->role])->values(),
            'loses_access' => $lost->map(fn ($u) => $u->name)->values(),
            'gains_access' => $gained->map(fn ($u) => $u->name)->values(),
            'direct_people_kept' => count(array_filter($direct, fn ($id) => $id !== $from->owner_id)),
            'owner_changes' => $from->owner_id !== $to->owner_id,
            'new_owner' => $to->owner?->name,
        ];
    }
}
