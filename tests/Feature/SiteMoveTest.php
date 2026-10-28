<?php

namespace Tests\Feature;

use App\Events\SiteMoved;
use App\Models\Organization;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class SiteMoveTest extends TestCase
{
    use RefreshDatabase;

    private User $dana;

    private User $lee;

    private User $zed;

    private string $acme;

    private string $beta;

    private string $zeds;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
        [$this->dana, $this->lee, $this->zed] = array_map(fn ($n) => User::create(['name' => ucfirst($n), 'email' => "$n@example.com", 'password' => 'password']), ['dana', 'lee', 'zed']);
        $this->actingAs($this->dana);
        $this->acme = $this->postJson('/api/organizations', ['name' => 'Acme'])->json('data.id');
        $this->beta = $this->postJson('/api/organizations', ['name' => 'Beta'])->json('data.id');
        foreach ([$this->acme, $this->beta] as $org) {
            $this->postJson("/api/organizations/{$org}/admins", ['email' => 'lee@example.com'])->assertCreated();
        }
        $this->postJson('/api/sites', ['domain' => 'example.com', 'organization' => $this->acme])->assertCreated();
        $this->actingAs($this->zed);
        $this->zeds = $this->postJson('/api/organizations', ['name' => 'Zed Co'])->json('data.id');
        $this->postJson("/api/organizations/{$this->zeds}/admins", ['email' => 'dana@example.com'])->assertCreated();
        $this->actingAs($this->dana);
    }

    private function site(): Site
    {
        return Site::where('domain', 'example.com')->firstOrFail();
    }

    private function destinations(): array
    {
        return collect($this->getJson('/api/sites/example.com/move')->assertOk()->json('destinations'))->pluck('name')->all();
    }

    public function test_the_owner_moves_a_site_between_their_organizations_and_to_personal(): void
    {
        Event::fake([SiteMoved::class]);
        $this->assertEqualsCanonicalizing(['Personal', 'Beta', 'Zed Co'], $this->destinations()); // not the one it is in
        $this->postJson('/api/sites/example.com/move', ['organization' => $this->beta])->assertOk();
        $this->assertSame($this->beta, $this->site()->organization_id);
        $this->assertSame($this->dana->id, $this->site()->ownerId());
        Event::assertDispatched(SiteMoved::class, fn ($e) => $e->from->id === $this->acme && $e->to->id === $this->beta);
        $this->postJson('/api/sites/example.com/move', ['organization' => Organization::personalFor($this->dana)->id])->assertOk();
        $this->assertSame($this->dana->id, $this->site()->ownerId());
    }

    public function test_direct_people_and_everything_on_the_site_stay_and_team_shares_go(): void
    {
        $sam = User::create(['name' => 'Sam', 'email' => 'sam@example.com', 'password' => 'password']);
        $kim = User::create(['name' => 'Kim', 'email' => 'kim@example.com', 'password' => 'password']);
        $this->postJson('/api/sites/example.com/people', ['email' => 'sam@example.com', 'role' => 'viewer'])->assertCreated();
        $team = $this->postJson('/api/teams', ['name' => 'Staff', 'organization_id' => $this->acme])->json('data.id');
        $this->postJson("/api/teams/{$team}/members", ['email' => 'kim@example.com'])->assertCreated();
        $this->postJson('/api/sites/example.com/teams', ['team_id' => $team, 'role' => 'admin'])->assertCreated();
        $this->postJson('/api/sites/example.com/goals', ['type' => 'event', 'value' => 'Signup'])->assertCreated();
        $this->assertSame('admin', $this->site()->roleOf($kim));

        $impact = collect($this->getJson('/api/sites/example.com/move')->json('destinations'))->firstWhere('name', 'Beta')['impact'];
        $this->assertSame('Staff', $impact['teams'][0]['name']);
        $this->assertSame(1, $impact['direct_people_kept']);
        $this->assertFalse($impact['owner_changes']);

        $this->postJson('/api/sites/example.com/move', ['organization' => $this->beta])->assertOk();
        $this->assertSame('viewer', $this->site()->roleOf($sam)); // an explicit grant to the site is kept
        $this->assertNull($this->site()->roleOf($kim)); // the old organization's team is unshared
        $this->assertDatabaseCount('site_team', 0);
        $this->assertSame('admin', $this->site()->roleOf($this->lee)); // admin of both, so unchanged
        $this->assertSame(['Signup'], $this->getJson('/api/sites/example.com/goals')->json('data.*.value'));
        $this->assertTrue(Team::where('id', $team)->exists());
    }

    public function test_an_admin_of_both_may_move_only_between_organizations_with_the_same_owner(): void
    {
        $this->actingAs($this->lee);
        $this->assertEqualsCanonicalizing(['Beta'], $this->destinations()); // Acme -> Beta (same owner); not Personal, not other owners
        $this->postJson('/api/sites/example.com/move', ['organization' => $this->beta])->assertOk();
        $this->assertSame($this->beta, $this->site()->organization_id);

        $this->postJson('/api/sites/example.com/move', ['organization' => Organization::personalFor($this->lee)->id])->assertUnprocessable(); // cannot take it home
        $zed = Organization::find($this->zeds);
        $zed->admins()->attach($this->lee->id, ['id' => (string) Str::uuid()]);
        $res = $this->postJson('/api/sites/example.com/move', ['organization' => $this->zeds])->assertUnprocessable();
        $this->assertStringContainsString('Only the owner of Beta', $res->json('errors.organization.0')); // admin of both, but a different owner
        $this->assertSame($this->beta, $this->site()->organization_id);
    }

    public function test_the_owner_may_move_a_site_to_an_organization_they_only_admin_and_the_owner_row_follows(): void
    {
        $sam = User::create(['name' => 'Sam', 'email' => 'sam@example.com', 'password' => 'password']);
        $this->postJson('/api/sites/example.com/people', ['email' => 'zed@example.com', 'role' => 'viewer'])->assertCreated(); // Zed already had a direct grant
        $impact = collect($this->getJson('/api/sites/example.com/move')->json('destinations'))->firstWhere('name', 'Zed Co')['impact'];
        $this->assertTrue($impact['owner_changes']);
        $this->assertSame('Zed', $impact['new_owner']);
        $this->assertContains('Lee', $impact['loses_access']);

        $this->postJson('/api/sites/example.com/move', ['organization' => $this->zeds])->assertOk();
        $site = $this->site();
        $this->assertSame($this->zed->id, $site->ownerId()); // the site now counts against Zed's plan
        $this->assertSame(1, \DB::table('site_user')->where('site_id', $site->id)->where('role', 'owner')->count());
        $this->assertNull($site->roleOf($this->lee)); // admin of the old organization only
        $this->assertSame('admin', $site->roleOf($this->dana)); // the mover still runs Zed Co as an admin
        $this->assertNull($site->roleOf($sam));
    }

    public function test_the_new_owners_plan_limit_applies_when_the_owner_changes(): void
    {
        config(['tidemark.limits.sites_per_user' => 1]);
        $this->actingAs($this->zed);
        $this->postJson('/api/sites', ['domain' => 'zeds.com', 'organization' => $this->zeds])->assertCreated();
        $this->actingAs($this->dana);
        $this->postJson('/api/sites/example.com/move', ['organization' => $this->zeds])->assertUnprocessable();
        $this->assertSame($this->acme, $this->site()->organization_id);
        $this->postJson('/api/sites/example.com/move', ['organization' => $this->beta])->assertOk(); // same owner: no new site for anyone
    }

    public function test_the_collectors_cached_owner_is_refreshed(): void
    {
        Cache::put('tdm:site:example.com', ['owner_id' => $this->dana->id], 60);
        $this->postJson('/api/sites/example.com/move', ['organization' => $this->zeds])->assertOk();
        $this->assertFalse(Cache::has('tdm:site:example.com'));
    }

    public function test_strangers_other_peoples_organizations_and_rollups_are_refused(): void
    {
        $stranger = User::create(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'password']);
        $this->actingAs($stranger);
        $this->getJson('/api/sites/example.com/move')->assertForbidden();
        $this->postJson('/api/sites/example.com/move', ['organization' => Organization::personalFor($stranger)->id])->assertForbidden();

        $this->actingAs($this->dana);
        $other = Organization::personalFor($stranger)->id;
        $this->postJson('/api/sites/example.com/move', ['organization' => $other])->assertUnprocessable(); // not hers to move into
        $this->postJson('/api/sites/example.com/move', ['organization' => $this->acme])->assertUnprocessable(); // already there
        $this->postJson('/api/rollups', ['name' => 'all-sites', 'all' => true])->assertCreated();
        $this->getJson('/api/sites/all-sites/move')->assertUnprocessable();
        $this->assertSame($this->acme, $this->site()->organization_id);
    }

    public function test_a_direct_site_admin_who_is_not_in_the_organization_cannot_move_it(): void
    {
        $sam = User::create(['name' => 'Sam', 'email' => 'sam@example.com', 'password' => 'password']);
        $this->postJson('/api/sites/example.com/people', ['email' => 'sam@example.com', 'role' => 'admin'])->assertCreated();
        $this->actingAs($sam);
        $this->getJson('/api/sites/example.com/move')->assertForbidden();
    }
}
