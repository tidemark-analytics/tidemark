<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('tidemark:demo', ['--days' => 14, '--visitors' => 30, '--email' => 'demo@example.com'])->assertSuccessful();
        $this->user = User::where('email', 'demo@example.com')->firstOrFail();
        $this->actingAs($this->user);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
    }

    private function url(string $path, array $q = []): string
    {
        return "/api/sites/demo.example.com/stats/{$path}?".http_build_query($q + ['period' => '30d']);
    }

    public function test_top_stats_has_metrics_and_comparison(): void
    {
        $res = $this->getJson($this->url('top', ['comparison' => 'previous_period']))->assertOk();
        $res->assertJsonStructure(['stats', 'period']);
        $this->assertGreaterThan(0, $res->json('stats.visitors.value'));
    }

    public function test_last_n_days_end_yesterday_like_plausible(): void
    {
        $yesterday = now('America/New_York')->subDay()->toDateString();
        foreach (['7d' => 7, '28d' => 28, '91d' => 91] as $period => $days) {
            $p = $this->getJson($this->url('top', ['period' => $period]))->assertOk()->json('period');
            $this->assertSame($yesterday, substr($p['to'], 0, 10), $period);
            $this->assertSame(now('America/New_York')->subDays($days)->toDateString(), substr($p['from'], 0, 10), $period);
        }
        $today = $this->getJson($this->url('top', ['period' => 'month']))->json('period');
        $this->assertSame(now('America/New_York')->toDateString(), substr($today['to'], 0, 10));
    }

    public function test_graph_returns_a_bucket_per_interval(): void
    {
        $res = $this->getJson($this->url('graph', ['metric' => 'visitors', 'interval' => 'day']))->assertOk();
        $this->assertGreaterThanOrEqual(30, count($res->json('labels')));
    }

    public function test_every_breakdown_dimension_responds(): void
    {
        foreach (['source', 'channel', 'page', 'entry_page', 'exit_page', 'country', 'region', 'city', 'browser', 'os', 'device', 'utm_source', 'utm_campaign'] as $dim) {
            $this->getJson($this->url("breakdown/{$dim}"))->assertOk();
        }
    }

    public function test_filters_narrow_results_including_negation_and_goals(): void
    {
        $all = $this->getJson($this->url('top'))->json('stats.visitors.value');
        $filtered = $this->getJson($this->url('top', ['filters' => json_encode([['is', 'country', ['ZZ']]])]))->assertOk()->json('stats.visitors.value');
        $this->assertGreaterThan(0, $all);
        $this->assertSame(0, $filtered);
        $chromeOnly = $this->getJson($this->url('top', ['filters' => json_encode([['is', 'browser', ['Chrome']]])]))->json('stats.visitors.value');
        $notChrome = $this->getJson($this->url('top', ['filters' => json_encode([['is_not', 'browser', ['Chrome']]])]))->json('stats.visitors.value');
        $this->assertLessThan($all, $chromeOnly);
        $this->assertLessThan($all, $notChrome);
        foreach ([['is_not', 'browser', ['Chrome']], ['contains', 'page', ['/pri']], ['is', 'goal', ['Signup']], ['is', 'source', ['Direct / None']], ['is', 'page', ['/blog/*']]] as $rule) {
            $this->getJson($this->url('breakdown/page', ['filters' => json_encode([$rule])]))->assertOk();
        }
    }

    public function test_goals_and_props(): void
    {
        $this->getJson($this->url('goals'))->assertOk();
        $this->getJson($this->url('props', ['filters' => json_encode([['is', 'goal', ['Signup']]])]))->assertOk();
    }

    public function test_realtime_and_export(): void
    {
        $this->getJson($this->url('top', ['period' => 'realtime']))->assertOk();
        $this->getJson($this->url('current-visitors'))->assertOk();
        $this->get($this->url('export'))->assertOk();
    }

    public function test_all_periods_resolve(): void
    {
        foreach (['day', '24h', '7d', '28d', '30d', '91d', 'month', '6mo', '12mo', 'year', 'all'] as $p) {
            $this->getJson($this->url('top', ['period' => $p]))->assertOk();
        }
        $this->getJson($this->url('top', ['period' => 'custom', 'from' => '2025-01-01', 'to' => '2025-01-31']))->assertOk();
    }

    public function test_access_control_and_public_dashboards(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password']);
        $this->actingAs($other)->getJson($this->url('top'))->assertForbidden();
        Site::where('domain', 'demo.example.com')->update(['public' => true]);
        $this->actingAs($other)->getJson($this->url('top'))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url('top'))->assertOk();
        $this->postJson('/api/sites/demo.example.com/reset')->assertUnauthorized();
    }

    public function test_site_crud_goals_and_stats_api_key(): void
    {
        $this->postJson('/api/sites', ['domain' => 'new.example.org', 'timezone' => 'Europe/Berlin'])->assertSuccessful();
        $this->postJson('/api/sites/new.example.org/goals', ['type' => 'event', 'value' => 'Signup'])->assertSuccessful();
        $key = $this->postJson('/api/me/keys', ['name' => 'ci'])->assertSuccessful()->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($key)->getJson('/api/v1/stats/aggregate?site_id=demo.example.com&period=30d&metrics=visitors,pageviews')->assertOk();
        $this->withToken($key)->getJson('/api/v1/stats/breakdown?site_id=demo.example.com&period=30d&property=visit:source')->assertOk();
        $this->withToken($key)->getJson('/api/v1/stats/timeseries?site_id=demo.example.com&period=7d')->assertOk();
        $this->withToken($key)->getJson('/api/v1/stats/realtime/visitors?site_id=demo.example.com')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withoutToken()->getJson('/api/v1/stats/aggregate?site_id=demo.example.com')->assertUnauthorized();
    }

    public function test_reset_clears_stats(): void
    {
        $this->postJson('/api/sites/demo.example.com/reset')->assertSuccessful();
        $this->assertSame(0, DB::table('visits')->count());
    }

    public function test_shared_links_grant_access_and_enforce_passwords(): void
    {
        $open = $this->postJson('/api/sites/demo.example.com/shared-links', ['name' => 'Open'])->assertCreated()->json('data.slug');
        $locked = $this->postJson('/api/sites/demo.example.com/shared-links', ['name' => 'Locked', 'password' => 'hunter22'])->assertCreated()->json('data.slug');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');

        $this->getJson($this->url('top'))->assertUnauthorized();
        $this->withHeader('X-Share-Auth', $open)->getJson($this->url('top'))->assertOk();
        $this->withHeader('X-Share-Auth', $locked)->getJson($this->url('top'))->assertUnauthorized()->assertJson(['error' => 'password_required']);
        $this->withHeaders(['X-Share-Auth' => $locked, 'X-Share-Password' => 'wrong'])->getJson($this->url('top'))->assertUnauthorized();
        $this->withHeaders(['X-Share-Auth' => $locked, 'X-Share-Password' => 'hunter22'])->getJson($this->url('top'))->assertOk();
        $this->withHeader('X-Share-Auth', 'not-a-slug')->getJson($this->url('top'))->assertUnauthorized();
    }
}
