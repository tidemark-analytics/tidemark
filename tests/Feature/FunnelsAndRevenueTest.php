<?php

namespace Tests\Feature;

use App\Analytics\Support\ExchangeRates;
use App\Contracts\AllowsConversionReports;
use App\Contracts\Entitlements;
use App\Models\Goal;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FunnelsAndRevenueTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
        $this->actingAs($owner);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->postJson('/api/sites', ['domain' => 'example.com'])->assertCreated();
        $this->site = Site::where('domain', 'example.com')->firstOrFail();
        Cache::flush();
    }

    private function hit(string $visitor, array $payload = [])
    {
        return $this->call('POST', '/api/event', [], [], [], $this->transformHeadersToServerVars(['User-Agent' => self::CHROME, 'Content-Type' => 'text/plain', 'X-Forwarded-For' => $visitor]),
            json_encode($payload + ['n' => 'pageview', 'u' => 'https://example.com/', 'd' => 'example.com', 'w' => 1920]));
    }

    /** One visit with the given events, in order, a second apart. @param array<int,array{0:string,1?:string,2?:array}> $events [name, path, revenue] */
    private function visit(string $visitor, array $events): void
    {
        $at = now()->subHour()->max(now()->startOfDay()); // never yesterday, so the suite also passes between 00:00 and 01:00 UTC
        $visit = (string) Str::uuid();
        DB::table('visits')->insert([
            'id' => $visit, 'site_id' => $this->site->id, 'visitor_id' => str_pad($visitor, 32, '0'), 'started_at' => $at, 'ended_at' => $at, 'entry_page' => '/', 'exit_page' => '/',
            'channel' => 'Direct', 'country' => 'DE', 'pageviews' => 1, 'events' => count($events), 'is_bounce' => false, 'duration' => 10,
        ]);
        foreach ($events as $i => [$name, $path, $revenue]) {
            DB::table('events')->insert([
                'site_id' => $this->site->id, 'visit_id' => $visit, 'visitor_id' => str_pad($visitor, 32, '0'), 'name' => $name, 'pathname' => $path,
                'created_at' => $at->copy()->addSeconds($i), 'revenue' => $revenue[0] ?? null, 'currency' => $revenue[1] ?? null,
            ]);
        }
    }

    private function url(string $path, array $q = []): string
    {
        return "/api/sites/example.com/stats/{$path}?".http_build_query($q + ['period' => 'day']);
    }

    public function test_events_keep_the_revenue_they_carry(): void
    {
        $this->hit('1.1.1.1', ['n' => 'Purchase', 'revenue' => ['amount' => '29.99', 'currency' => 'usd']])->assertStatus(202);
        $this->hit('1.1.1.2', ['n' => 'Purchase', '$' => ['amount' => 10, 'currency' => 'EUR']])->assertStatus(202);
        $this->hit('1.1.1.3', ['n' => 'Purchase', 'revenue' => ['amount' => 'lots', 'currency' => 'USD']])->assertStatus(202);
        $this->hit('1.1.1.4', ['n' => 'pageview', 'revenue' => ['amount' => 5, 'currency' => 'USD']])->assertStatus(202);

        $rows = DB::table('events')->orderBy('id')->get(['name', 'revenue', 'currency']);
        $this->assertSame(['USD', 29.99], [$rows[0]->currency, (float) $rows[0]->revenue]);
        $this->assertSame(['EUR', 10.0], [$rows[1]->currency, (float) $rows[1]->revenue]);
        $this->assertNull($rows[2]->revenue);
        $this->assertNull($rows[3]->revenue);
    }

    public function test_revenue_is_converted_into_the_goal_currency_when_recorded(): void
    {
        ExchangeRates::store('<Cube currency="USD" rate="1.25"/><Cube currency="GBP" rate="0.80"/>');
        $this->postJson('/api/sites/example.com/goals', ['type' => 'event', 'value' => 'Purchase', 'currency' => 'usd'])->assertCreated()->assertJsonPath('data.currency', 'USD');
        Cache::forget('tdm:site:example.com');

        $this->hit('2.2.2.1', ['n' => 'Purchase', 'revenue' => ['amount' => 20, 'currency' => 'GBP']]);
        $this->hit('2.2.2.2', ['n' => 'Purchase', 'revenue' => ['amount' => 10, 'currency' => 'USD']]);

        $this->assertEqualsWithDelta(31.25, (float) DB::table('events')->orderBy('id')->value('revenue_reporting'), 0.001);
        $total = $this->getJson($this->url('top', ['filters' => json_encode([['is', 'goal', ['Purchase']]])]))->assertOk();
        $total->assertJsonPath('currency', 'USD')->assertJsonPath('stats.total_revenue.value', 41.25)->assertJsonPath('stats.average_revenue.value', 20.63);
    }

    public function test_goal_reports_revenue_in_every_view(): void
    {
        $goal = Goal::create(['site_id' => $this->site->id, 'type' => 'event', 'value' => 'Purchase', 'currency' => 'USD']);
        $this->visit('a', [['pageview', '/', null], ['Purchase', '/checkout', [30, 'USD']]]);
        $this->visit('b', [['pageview', '/', null], ['Purchase', '/checkout', [70, 'USD']], ['Purchase', '/checkout', [50, 'USD']]]);
        $this->visit('c', [['pageview', '/', null]]);
        $filters = json_encode([['is', 'goal', ['Purchase']]]);

        $goals = $this->getJson($this->url('goals'))->assertOk()->json('results.0');
        $this->assertSame(['USD', 150.0, 50.0, 2, 3], [$goals['currency'], (float) $goals['revenue'], (float) $goals['average_revenue'], $goals['visitors'], $goals['events']]);

        $top = $this->getJson($this->url('top', ['filters' => $filters]))->json('stats');
        $this->assertSame([150.0, 50.0], [(float) $top['total_revenue']['value'], (float) $top['average_revenue']['value']]);

        $graph = $this->getJson($this->url('graph', ['filters' => $filters]))->assertOk();
        $this->assertEqualsWithDelta(150.0, array_sum($graph->json('plot.total_revenue')), 0.001);

        $rows = $this->getJson($this->url('breakdown/page', ['filters' => $filters]))->json('results');
        $this->assertEqualsWithDelta(150.0, $rows[0]['revenue'], 0.001);

        $this->get($this->url('export'))->assertOk();
        $this->assertNotNull($goal->id);
    }

    public function test_goals_without_a_currency_report_no_revenue(): void
    {
        Goal::create(['site_id' => $this->site->id, 'type' => 'event', 'value' => 'Signup']);
        $this->visit('a', [['Signup', '/', [5, 'USD']]]);

        $this->assertArrayNotHasKey('revenue', $this->getJson($this->url('goals'))->json('results.0'));
        $this->assertArrayNotHasKey('total_revenue', $this->getJson($this->url('top', ['filters' => json_encode([['is', 'goal', ['Signup']]])]))->json('stats'));
    }

    public function test_a_funnel_counts_visitors_who_complete_the_steps_in_order(): void
    {
        $pricing = Goal::create(['site_id' => $this->site->id, 'type' => 'page', 'value' => '/pricing']);
        $signup = Goal::create(['site_id' => $this->site->id, 'type' => 'event', 'value' => 'Signup']);
        $paid = Goal::create(['site_id' => $this->site->id, 'type' => 'event', 'value' => 'Purchase']);
        $this->visit('a', [['pageview', '/', null], ['pageview', '/pricing', null], ['pageview', '/docs', null], ['Signup', '/', null], ['Purchase', '/', null]]);
        $this->visit('b', [['pageview', '/pricing', null], ['Signup', '/', null]]);
        $this->visit('c', [['Signup', '/', null], ['pageview', '/pricing', null]]);
        $this->visit('d', [['pageview', '/docs', null]]);

        $id = $this->postJson('/api/sites/example.com/funnels', ['name' => 'Buy', 'steps' => [$pricing->id, $signup->id, $paid->id]])->assertCreated()->json('data.id');
        $res = $this->getJson($this->url("funnels/{$id}"))->assertOk();

        $this->assertSame([3, 2, 1], array_column($res->json('steps'), 'visitors'));
        $this->assertSame([100.0, 66.7, 33.3], array_map('floatval', array_column($res->json('steps'), 'conversion_rate')));
        $this->assertSame([0, 1, 1], array_column($res->json('steps'), 'dropoff'));
        $this->assertEquals(33.3, $res->json('conversion_rate'));
        $this->assertSame(['Visit /pricing', 'Signup', 'Purchase'], array_column($res->json('steps'), 'name'));

        $narrowed = $this->getJson($this->url("funnels/{$id}", ['filters' => json_encode([['is', 'country', ['FR']]])]))->assertOk();
        $this->assertSame([0, 0, 0], array_column($narrowed->json('steps'), 'visitors'));
        $this->getJson($this->url('funnels'))->assertOk()->assertJsonPath('allowed', true)->assertJsonPath('results.0.steps', 3);
    }

    public function test_funnels_can_be_edited_and_goals_in_use_cannot_be_deleted(): void
    {
        $a = Goal::create(['site_id' => $this->site->id, 'type' => 'page', 'value' => '/a']);
        $b = Goal::create(['site_id' => $this->site->id, 'type' => 'page', 'value' => '/b']);
        $c = Goal::create(['site_id' => $this->site->id, 'type' => 'page', 'value' => '/c']);
        $id = $this->postJson('/api/sites/example.com/funnels', ['name' => 'Path', 'steps' => [$a->id, $b->id]])->assertCreated()->json('data.id');

        $this->deleteJson("/api/sites/example.com/goals/{$a->id}")->assertUnprocessable();
        $this->putJson("/api/sites/example.com/funnels/{$id}", ['name' => 'Longer', 'steps' => [$a->id, $b->id, $c->id]])->assertOk();
        $this->getJson('/api/sites/example.com/funnels')->assertJsonPath('data.0.name', 'Longer')->assertJsonCount(3, 'data.0.steps');
        $this->postJson('/api/sites/example.com/funnels', ['name' => 'Short', 'steps' => [$a->id]])->assertUnprocessable();
        $this->postJson('/api/sites/example.com/funnels', ['name' => 'Foreign', 'steps' => [$a->id, (string) Str::uuid()]])->assertUnprocessable();

        $this->deleteJson("/api/sites/example.com/funnels/{$id}")->assertOk();
        $this->deleteJson("/api/sites/example.com/goals/{$a->id}")->assertOk();
    }

    public function test_an_extension_can_limit_funnels_and_revenue(): void
    {
        $goal = Goal::create(['site_id' => $this->site->id, 'type' => 'event', 'value' => 'Purchase', 'currency' => 'USD']);
        $other = Goal::create(['site_id' => $this->site->id, 'type' => 'event', 'value' => 'Signup']);
        $this->visit('a', [['Purchase', '/', [10, 'USD']]]);
        $this->app->instance(Entitlements::class, new class extends DefaultEntitlements implements AllowsConversionReports
        {
            public function allowsFunnels(Site $site): bool
            {
                return false;
            }

            public function allowsRevenue(Site $site): bool
            {
                return false;
            }
        });

        $this->getJson('/api/sites/example.com')->assertJsonPath('data.features', ['funnels' => false, 'revenue' => false, 'segments' => true, 'journeys' => true]);
        $this->postJson('/api/sites/example.com/funnels', ['name' => 'X', 'steps' => [$goal->id, $other->id]])->assertForbidden();
        $this->getJson($this->url('funnels'))->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('results', []);
        $this->patchJson("/api/sites/example.com/goals/{$other->id}", ['currency' => 'EUR'])->assertUnprocessable();
        $this->patchJson("/api/sites/example.com/goals/{$goal->id}", ['currency' => null])->assertOk();
        $this->assertArrayNotHasKey('revenue', $this->getJson($this->url('goals'))->json('results.1') ?? []);
    }

    public function test_a_goal_can_start_and_stop_reporting_revenue(): void
    {
        $goal = Goal::create(['site_id' => $this->site->id, 'type' => 'event', 'value' => 'Purchase']);
        $page = Goal::create(['site_id' => $this->site->id, 'type' => 'page', 'value' => '/thanks']);

        $this->patchJson("/api/sites/example.com/goals/{$goal->id}", ['currency' => 'eur'])->assertOk()->assertJsonPath('data.currency', 'EUR');
        $this->patchJson("/api/sites/example.com/goals/{$goal->id}", ['currency' => 'euro'])->assertUnprocessable();
        $this->patchJson("/api/sites/example.com/goals/{$page->id}", ['currency' => 'EUR'])->assertUnprocessable();
        $this->patchJson("/api/sites/example.com/goals/{$goal->id}", ['currency' => null])->assertOk()->assertJsonPath('data.currency', null);
    }
}
