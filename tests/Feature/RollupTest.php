<?php

namespace Tests\Feature;

use App\Contracts\AllowsRollups;
use App\Contracts\AllowsRollupsIn;
use App\Contracts\Entitlements;
use App\Models\Goal;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RollupTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
        $this->actingAs($this->owner);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
    }

    private function site(string $domain, ?User $user = null, string $timezone = 'UTC'): Site
    {
        $user ??= $this->owner;
        $site = Site::create(['domain' => $domain, 'timezone' => $timezone, 'organization_id' => Organization::personalFor($user)->id]);
        $site->members()->attach($user->id, ['role' => 'owner', 'id' => (string) Str::uuid()]);

        return $site;
    }

    /** One visit per visitor with the given events, a second apart, started an hour ago. @param array<int,array{0:string,1:string}> $events */
    private function visit(Site $site, string $visitor, array $events = [['pageview', '/']]): void
    {
        $at = now()->subHour()->max(now()->startOfDay()); // never yesterday, so the suite also passes between 00:00 and 01:00 UTC
        $visit = (string) Str::uuid();
        DB::table('visits')->insert([
            'id' => $visit, 'site_id' => $site->id, 'visitor_id' => str_pad($visitor, 32, '0'), 'started_at' => $at, 'ended_at' => $at, 'entry_page' => $events[0][1], 'exit_page' => '/',
            'channel' => 'Direct', 'country' => 'DE', 'pageviews' => 1, 'events' => count($events), 'is_bounce' => false, 'duration' => 10,
        ]);
        foreach ($events as $i => [$name, $path]) {
            DB::table('events')->insert(['site_id' => $site->id, 'visit_id' => $visit, 'visitor_id' => str_pad($visitor, 32, '0'), 'name' => $name, 'pathname' => $path, 'created_at' => $at->copy()->addSeconds($i)]);
        }
    }

    private function visitors(string $domain, array $q = []): int
    {
        return $this->getJson("/api/sites/{$domain}/stats/top?".http_build_query($q + ['period' => 'day']))->assertOk()->json('stats.visitors.value');
    }

    public function test_a_rollup_cannot_take_the_name_of_an_app_page(): void
    {
        $this->site('a.example.com');

        foreach (['admin', 'settings', 'sites'] as $name) {
            $this->postJson('/api/rollups', ['name' => $name, 'all' => true])->assertStatus(422);
        }
    }

    public function test_configured_reserved_names_cannot_be_taken(): void
    {
        config(['tidemark.reserved_names' => ['Blog', 'docs']]);

        foreach (['blog', 'docs'] as $name) {
            $this->postJson('/api/rollups', ['name' => $name, 'all' => true])->assertStatus(422);
        }
        $this->postJson('/api/sites', ['domain' => 'docs'])->assertStatus(422);
    }

    public function test_a_rollup_adds_up_the_sites_it_includes(): void
    {
        $a = $this->site('a.example.com');
        $b = $this->site('b.example.com');
        $c = $this->site('c.example.com');
        $this->visit($a, '1');
        $this->visit($a, '2');
        $this->visit($b, '3');
        $this->visit($c, '4');

        $this->postJson('/api/rollups', ['name' => 'network', 'sites' => ['a.example.com', 'b.example.com']])->assertCreated()
            ->assertJsonPath('data.rollup.count', 2)->assertJsonPath('data.rollup.all', false);

        $this->assertSame(3, $this->visitors('network'));
        $pages = $this->getJson('/api/sites/network/stats/breakdown/page?period=day')->assertOk()->json('results');
        $this->assertSame(3, $pages[0]['visitors']);
        $this->assertSame(3, $this->getJson('/api/sites/network/stats/graph?period=day')->json('plot.visitors') ? array_sum($this->getJson('/api/sites/network/stats/graph?period=day')->json('plot.visitors')) : 0);

        $this->putJson('/api/sites/network/rollup', ['sites' => ['c.example.com']])->assertOk()->assertJsonPath('data.rollup.count', 1);
        $this->assertSame(1, $this->visitors('network'));
        $this->assertSame(2, $this->visitors('a.example.com'));
    }

    public function test_a_rollup_of_all_sites_follows_new_sites_and_skips_ones_in_other_organizations(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password']);
        $mine = $this->site('mine.example.com');
        $theirs = $this->site('theirs.example.com', $other);
        $this->visit($mine, '1');
        $this->visit($theirs, '2');
        $this->postJson('/api/rollups', ['name' => 'everything', 'all' => true])->assertCreated();

        $this->assertSame(1, $this->visitors('everything'));
        $later = $this->site('later.example.com');
        $this->visit($later, '3');
        $this->assertSame(2, $this->visitors('everything'));

        $later->update(['organization_id' => Organization::personalFor($other)->id]);
        $this->assertSame(1, $this->visitors('everything'), 'a site that leaves the organization leaves the rollup');
    }

    public function test_a_rollup_adds_up_only_the_sites_of_its_organization(): void
    {
        $customer = User::create(['name' => 'Customer', 'email' => 'customer@example.com', 'password' => 'password']);
        $mine = $this->site('mine.example.com');
        $theirs = $this->site('theirs.example.com', $customer);
        $theirs->members()->attach($this->owner->id, ['role' => 'viewer', 'id' => (string) Str::uuid()]);
        $this->visit($mine, '1');
        $this->visit($theirs, '2');

        $this->postJson('/api/rollups', ['name' => 'everything', 'all' => true])->assertCreated();
        $this->assertSame(1, $this->visitors('everything'));

        $this->postJson('/api/rollups', ['name' => 'picked', 'sites' => ['mine.example.com', 'theirs.example.com']])->assertCreated();
        $this->assertSame(1, $this->visitors('picked'), 'a rollup only adds up the sites of its own organization, whatever is picked');
    }

    public function test_goals_funnels_and_segments_work_across_the_member_sites(): void
    {
        $a = $this->site('a.example.com');
        $b = $this->site('b.example.com');
        $this->visit($a, '1', [['pageview', '/pricing'], ['Signup', '/']]);
        $this->visit($b, '2', [['pageview', '/pricing'], ['Signup', '/']]);
        $this->visit($b, '3', [['pageview', '/pricing']]);
        $this->postJson('/api/rollups', ['name' => 'network', 'all' => true])->assertCreated();
        $page = $this->postJson('/api/sites/network/goals', ['type' => 'page', 'value' => '/pricing'])->assertCreated()->json('data.id');
        $signup = $this->postJson('/api/sites/network/goals', ['type' => 'event', 'value' => 'Signup'])->assertCreated()->json('data.id');

        $goals = $this->getJson('/api/sites/network/stats/goals?period=day')->assertOk()->json('results');
        $this->assertSame(['Visit /pricing' => 3, 'Signup' => 2], array_column($goals, 'visitors', 'name'));

        $funnel = $this->postJson('/api/sites/network/funnels', ['name' => 'Buy', 'steps' => [$page, $signup]])->assertCreated()->json('data.id');
        $res = $this->getJson("/api/sites/network/stats/funnels/{$funnel}?period=day")->assertOk();
        $this->assertSame([3, 2], array_column($res->json('steps'), 'visitors'));
        $this->assertSame(0, Goal::where('site_id', $a->id)->count(), 'goals belong to the rollup, not to its members');

        $segment = $this->postJson('/api/sites/network/segments', ['name' => 'Germany', 'type' => 'site', 'filters' => [['is', 'country', ['DE']]]])->assertCreated()->json('data.id');
        $this->assertSame(3, $this->visitors('network', ['filters' => json_encode([['is', 'segment', [$segment]]])]));
    }

    public function test_a_rollup_goal_can_report_revenue_in_one_currency(): void
    {
        $a = $this->site('a.example.com');
        $b = $this->site('b.example.com');
        foreach ([[$a, '1', 20, 'USD'], [$a, '2', 5, 'EUR'], [$b, '3', 30, 'USD']] as [$site, $visitor, $amount, $currency]) {
            $this->visit($site, $visitor, [['pageview', '/'], ['Purchase', '/buy']]);
            DB::table('events')->where('site_id', $site->id)->where('visitor_id', str_pad($visitor, 32, '0'))->where('name', 'Purchase')->update(['revenue' => $amount, 'currency' => $currency]);
        }
        $this->postJson('/api/rollups', ['name' => 'network', 'all' => true])->assertCreated();
        $this->postJson('/api/sites/network/goals', ['type' => 'event', 'value' => 'Purchase', 'currency' => 'USD'])->assertCreated()->assertJsonPath('data.currency', 'USD');

        $top = $this->getJson('/api/sites/network/stats/top?'.http_build_query(['period' => 'day', 'filters' => json_encode([['is', 'goal', ['Purchase']]])]))->assertOk();
        $top->assertJsonPath('stats.unique_conversions.value', 3)->assertJsonPath('stats.total_revenue.value', 50)->assertJsonPath('currency', 'USD');
    }

    public function test_events_are_never_collected_for_a_rollup_and_its_data_cannot_be_changed(): void
    {
        $a = $this->site('a.example.com');
        $this->postJson('/api/rollups', ['name' => 'network', 'all' => true])->assertCreated();

        $this->call('POST', '/api/event', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => 'en-US', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh) Chrome/124.0 Safari/537.36', 'CONTENT_TYPE' => 'text/plain'], json_encode(['n' => 'pageview', 'u' => 'https://network/', 'd' => 'network']))->assertNotFound();
        $this->assertSame(0, DB::table('events')->count());

        $this->postJson('/api/sites/network/reset')->assertUnprocessable();
        $this->get('/api/sites/network/export/data')->assertUnprocessable();
        $this->postJson('/api/sites/network/import/plausible/start')->assertUnprocessable();
        $this->postJson('/api/sites/network/shields', ['type' => 'ip', 'value' => '1.2.3.4'])->assertUnprocessable();
        $this->postJson('/api/sites/network/people', ['email' => 'x@example.com', 'role' => 'viewer'])->assertUnprocessable();
        $this->assertNotNull($a->id);
    }

    public function test_the_sites_list_shows_rollups_and_deleting_one_keeps_the_data(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password', 'is_admin' => true]);
        $a = $this->site('a.example.com');
        $this->visit($a, '1');
        $this->postJson('/api/rollups', ['name' => 'network', 'all' => true])->assertCreated();

        $list = $this->getJson('/api/sites')->assertOk()->json('data');
        $row = collect($list)->firstWhere('domain', 'network');
        $this->assertTrue($row['rollup']);
        $this->assertSame(1, $row['members']);
        $this->assertFalse(collect($list)->firstWhere('domain', 'a.example.com')['rollup']);

        $this->app['auth']->forgetGuards();
        $adminList = collect($this->actingAs($other)->getJson('/api/sites')->assertOk()->json('data'))->pluck('domain')->all();
        $this->assertNotContains('network', $adminList, "an administrator's list doesn't show other people's rollups");

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner)->deleteJson('/api/sites/network')->assertOk();
        $this->assertSame(1, $this->visitors('a.example.com'));
        $this->assertSame(0, DB::table('rollup_sites')->count());
    }

    public function test_names_are_checked_and_an_extension_can_limit_rollups(): void
    {
        $this->site('a.example.com');
        $this->postJson('/api/rollups', ['name' => 'has.dot', 'all' => true])->assertUnprocessable();
        $this->postJson('/api/rollups', ['name' => 'sites', 'all' => true])->assertUnprocessable();
        $this->postJson('/api/rollups', ['name' => 'a.example.com', 'all' => true])->assertUnprocessable();
        $this->postJson('/api/rollups', ['name' => 'empty', 'sites' => []])->assertUnprocessable();
        $this->postJson('/api/rollups', ['name' => 'stranger', 'sites' => ['nope.example.com']])->assertUnprocessable();
        $this->getJson('/api/rollups/candidates')->assertOk()->assertJsonPath('data.0.domain', 'a.example.com');

        $this->app->instance(Entitlements::class, new class extends DefaultEntitlements implements AllowsRollups
        {
            public function allowsRollups(User $user): bool
            {
                return false;
            }
        });
        $this->postJson('/api/rollups', ['name' => 'network', 'all' => true])->assertForbidden()->assertJsonPath('error', 'rollups_unavailable');
    }

    public function test_the_default_timezone_is_the_one_most_member_sites_use(): void
    {
        $this->site('a.example.com', null, 'Europe/Berlin');
        $this->site('b.example.com', null, 'Europe/Berlin');
        $this->site('c.example.com', null, 'America/New_York');
        $this->postJson('/api/rollups', ['name' => 'network', 'all' => true])->assertCreated()->assertJsonPath('data.timezone', 'Europe/Berlin');
    }

    public function test_an_organization_admin_makes_a_rollup_that_belongs_to_the_organization_and_follows_its_owner(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
        $org = Organization::create(['name' => 'Agency', 'owner_id' => $this->owner->id]);
        $org->admins()->attach($admin->id, ['id' => (string) Str::uuid()]);
        foreach (['a.example.com', 'b.example.com'] as $d) {
            $s = Site::create(['domain' => $d, 'timezone' => 'UTC', 'organization_id' => $org->id]);
            $s->members()->attach($this->owner->id, ['role' => 'owner', 'id' => (string) Str::uuid()]);
            $this->visit($s, $d);
        }
        $personal = $this->site('mine.example.com');
        $this->visit($personal, 'p');

        $this->actingAs($admin);
        $this->getJson('/api/rollups/candidates?organization='.$org->id)->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/rollups', ['name' => 'agency', 'organization' => $org->id, 'all' => true])->assertCreated()->assertJsonPath('data.organization_id', $org->id);
        $rollup = Site::where('domain', 'agency')->first();
        $this->assertSame($this->owner->id, $rollup->ownerId(), 'owned by the organization\'s owner, like its sites');
        $this->assertSame(2, $this->visitors('agency'), 'the Personal site is not in it');
        $this->putJson('/api/sites/agency/rollup', ['all' => false, 'sites' => ['a.example.com']])->assertOk()->assertJsonPath('data.rollup.count', 1);

        $list = collect($this->getJson('/api/sites?organization='.$org->id)->json('data'))->pluck('domain');
        $this->assertTrue($list->contains('agency'));
        $this->assertFalse(collect($this->getJson('/api/sites?organization='.Organization::personalFor($admin)->id)->json('data'))->pluck('domain')->contains('agency'));

        $this->actingAs($this->owner);
        $this->assertFalse(collect($this->getJson('/api/sites?organization='.Organization::personalFor($this->owner)->id)->json('data'))->pluck('domain')->contains('agency'), 'it no longer sits in Personal');
        $this->postJson('/api/rollups', ['name' => 'nope', 'organization' => Organization::personalFor($admin)->id, 'all' => true])->assertUnprocessable();
    }

    public function test_deleting_an_organization_takes_its_rollups_and_ownership_transfer_moves_them(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password']);
        $org = Organization::create(['name' => 'Agency', 'owner_id' => $this->owner->id]);
        $org->admins()->attach($other->id, ['id' => (string) Str::uuid()]);
        $this->postJson('/api/rollups', ['name' => 'agency', 'organization' => $org->id, 'all' => true])->assertCreated();
        $this->assertSame(0, $this->getJson('/api/organizations')->json('data.0.sites') ?? 0);

        $org->update(['transfer_to' => $other->id, 'transfer_expires_at' => now()->addHour()]);
        $this->actingAs($other)->postJson("/api/organizations/{$org->id}/transfer/accept")->assertOk();
        $this->assertSame($other->id, Site::where('domain', 'agency')->first()->ownerId());

        $this->deleteJson("/api/organizations/{$org->id}", ['confirm' => 'Agency'])->assertOk();
        $this->assertNull(Site::where('domain', 'agency')->first());
    }

    public function test_an_extension_can_decide_rollups_per_organization(): void
    {
        $org = Organization::create(['name' => 'Agency', 'owner_id' => $this->owner->id]);
        $this->app->instance(Entitlements::class, new class extends DefaultEntitlements implements AllowsRollups, AllowsRollupsIn
        {
            public function allowsRollups(User $user): bool
            {
                return false;
            }

            public function allowsRollupsIn(Organization $organization): bool
            {
                return ! $organization->personal;
            }
        });
        $this->postJson('/api/rollups', ['name' => 'mine', 'all' => true])->assertForbidden();
        $this->postJson('/api/rollups', ['name' => 'agency', 'organization' => $org->id, 'all' => true])->assertCreated();
    }
}
