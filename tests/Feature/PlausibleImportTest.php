<?php

namespace Tests\Feature;

use App\Contracts\Entitlements;
use App\Contracts\LimitsImportedHistory;
use App\Models\Goal;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlausibleImportTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/sites/example.com';

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
        $this->actingAs($owner);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->postJson('/api/sites', ['domain' => 'example.com', 'timezone' => 'UTC'])->assertCreated();
    }

    /** Two days of a small Plausible export, in the shape the browser sends. */
    private function send(array $extra = []): void
    {
        $this->postJson(self::BASE.'/import/plausible/start')->assertOk();
        $this->postJson(self::BASE.'/import/plausible', $extra + [
            'daily' => [
                ['date' => '2024-01-01', 'visitors' => 100, 'pageviews' => 300, 'bounces' => 40, 'visits' => 120, 'visit_duration' => 12000],
                ['date' => '2024-01-02', 'visitors' => 50, 'pageviews' => 100, 'bounces' => 20, 'visits' => 60, 'visit_duration' => 3000],
            ],
            'sources' => [
                ['date' => '2024-01-01', 'source' => 'Google', 'referrer' => 'google.com', 'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '', 'utm_content' => '', 'utm_term' => '', 'pageviews' => 200, 'visitors' => 70, 'visits' => 80, 'visit_duration' => 9000, 'bounces' => 25],
                ['date' => '2024-01-01', 'source' => '', 'referrer' => '', 'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '', 'utm_content' => '', 'utm_term' => '', 'pageviews' => 100, 'visitors' => 30, 'visits' => 40, 'visit_duration' => 3000, 'bounces' => 15],
            ],
            'rows' => [
                ['date' => '2024-01-01', 'dim' => 'page', 'name' => '/pricing', 'visitors' => 60, 'visits' => 60, 'pageviews' => 90],
                ['date' => '2024-01-01', 'dim' => 'entry_page', 'name' => '/pricing', 'visitors' => 20, 'visits' => 20, 'bounces' => 10, 'pageviews' => 40],
                ['date' => '2024-01-01', 'dim' => 'country', 'name' => 'DE', 'visitors' => 40, 'visits' => 45, 'pageviews' => 120],
                ['date' => '2024-01-01', 'dim' => 'event', 'name' => 'Signup', 'visitors' => 5, 'visits' => 6],
                ['date' => '2024-01-01', 'dim' => 'bogus', 'name' => 'ignored', 'visitors' => 1],
            ],
        ]);
        $this->postJson(self::BASE.'/import/plausible/finish')->assertOk();
    }

    private function stats(string $path, array $q = []): array
    {
        return $this->getJson(self::BASE."/stats/{$path}?".http_build_query($q + ['period' => 'custom', 'from' => '2024-01-01', 'to' => '2024-01-03']))->assertOk()->json();
    }

    private function nativeVisit(string $startedAt, string $source = 'Bing'): void
    {
        $site = Site::first();
        DB::table('visits')->insert(['id' => (string) Str::uuid(), 'site_id' => $site->id, 'visitor_id' => md5($startedAt), 'started_at' => $startedAt, 'ended_at' => $startedAt, 'source' => $source, 'channel' => 'Organic Search', 'pageviews' => 1, 'events' => 0, 'is_bounce' => true, 'duration' => 0]);
    }

    public function test_imported_days_add_to_the_top_stats_graph_and_reports(): void
    {
        $this->send();

        $top = $this->stats('top');
        $this->assertSame(150, $top['stats']['visitors']['value']);
        $this->assertSame(180, $top['stats']['visits']['value']);
        $this->assertSame(400, $top['stats']['pageviews']['value']);
        $this->assertSame(33, $top['stats']['bounce_rate']['value']);

        $graph = $this->stats('graph');
        $this->assertSame([100, 50, 0], $graph['plot']['visitors']);

        $sources = collect($this->stats('breakdown/source')['results'])->keyBy('name');
        $this->assertSame(70, $sources['Google']['visitors']);
        $this->assertSame(30, $sources['Direct / None']['visitors']);
        $this->assertSame('Organic Search', collect($this->stats('breakdown/channel')['results'])->firstWhere('name', 'Organic Search')['name']);
        $this->assertSame(40, collect($this->stats('breakdown/country')['results'])->firstWhere('name', 'DE')['visitors']);
        $this->assertSame(90, collect($this->stats('breakdown/page')['results'])->firstWhere('name', '/pricing')['pageviews']);
        $this->assertSame(50, collect($this->stats('breakdown/page')['results'])->firstWhere('name', '/pricing')['bounce_rate']);
    }

    public function test_imported_events_count_toward_matching_goals(): void
    {
        Goal::create(['site_id' => Site::first()->id, 'type' => 'event', 'value' => 'Signup']);
        $this->send();

        $goal = collect($this->stats('goals')['results'])->firstWhere('name', 'Signup');
        $this->assertSame(5, $goal['visitors']);
        $this->assertSame(6, $goal['events']);
    }

    public function test_native_and_imported_rows_are_added_together_by_name(): void
    {
        $this->send();
        $this->nativeVisit('2024-01-03 10:00:00', 'Google');

        $google = collect($this->stats('breakdown/source')['results'])->firstWhere('name', 'Google');
        $this->assertSame(71, $google['visitors']);
    }

    public function test_filters_leave_imported_data_out(): void
    {
        $this->send();
        $this->nativeVisit('2024-01-03 10:00:00');

        $filtered = $this->stats('top', ['filters' => json_encode([['is', 'source', ['Bing']]])]);
        $this->assertSame(1, $filtered['stats']['visitors']['value']);
    }

    public function test_days_tidemark_already_has_are_not_imported_again(): void
    {
        $this->nativeVisit('2024-01-02 09:00:00');
        $this->postJson(self::BASE.'/import/plausible/start')->assertOk()->assertJsonPath('until', '2024-01-01');
        $res = $this->postJson(self::BASE.'/import/plausible', ['daily' => [
            ['date' => '2024-01-01', 'visitors' => 10, 'pageviews' => 10, 'bounces' => 1, 'visits' => 10, 'visit_duration' => 0],
            ['date' => '2024-01-02', 'visitors' => 99, 'pageviews' => 99, 'bounces' => 1, 'visits' => 99, 'visit_duration' => 0],
        ]])->assertOk();
        $this->assertSame(1, $res->json('days'));
        $this->assertSame(1, $res->json('skipped'));
    }

    public function test_importing_again_replaces_the_previous_import(): void
    {
        $this->send();
        $this->send();

        $this->assertSame(150, $this->stats('top')['stats']['visitors']['value']);
        $this->assertSame(2, DB::table('imported_visitors')->count());
    }

    public function test_the_plan_can_limit_how_far_back_an_import_goes(): void
    {
        $this->app->instance(Entitlements::class, new class extends DefaultEntitlements implements LimitsImportedHistory
        {
            public function importCutoff(Site $site): ?\DateTimeInterface
            {
                return new \DateTimeImmutable('2024-01-02');
            }
        });
        $this->send();

        $this->assertSame(['2024-01-02'], DB::table('imported_visitors')->pluck('date')->all());
    }

    public function test_imported_data_can_be_removed_and_follows_a_reset(): void
    {
        $this->send();
        $this->getJson(self::BASE)->assertJsonPath('data.imported.from', 'plausible');

        $this->deleteJson(self::BASE.'/import/plausible')->assertOk();
        $this->assertSame(0, DB::table('imported_visitors')->count());
        $this->assertSame(0, $this->stats('top')['stats']['visitors']['value']);
        $this->getJson(self::BASE)->assertJsonPath('data.imported', null);

        $this->send();
        $this->postJson(self::BASE.'/reset')->assertOk();
        $this->assertSame(0, DB::table('imported_breakdowns')->count());
    }

    public function test_only_managers_can_import(): void
    {
        $viewer = User::create(['name' => 'View', 'email' => 'view@example.com', 'password' => 'password']);
        $this->postJson(self::BASE.'/people', ['email' => 'view@example.com', 'role' => 'viewer'])->assertCreated();
        $this->actingAs($viewer)->postJson(self::BASE.'/import/plausible/start')->assertForbidden();
    }
}
