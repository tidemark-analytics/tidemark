<?php

namespace Tests\Feature;

use App\Alerts\RuleEvaluator;
use App\Alerts\WebhookUrl;
use App\Jobs\DeliverWebhook;
use App\Mail\AlertMail;
use App\Models\AlertRule;
use App\Models\AlertWebhook;
use App\Models\Goal;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Alert rules and webhooks: who manages them, when a rule fires, and how an alert is delivered. */
class AlertsTest extends TestCase
{
    use RefreshDatabase;

    private const HOOK = 'https://93.184.216.34/hook/abcdef'; // a public address, so no DNS lookup is needed

    private Site $site;

    private User $owner;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp']);
        Mail::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->site = Site::create(['domain' => 'example.com', 'timezone' => 'UTC']);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
        $this->viewer = User::create(['name' => 'Viewer', 'email' => 'viewer@example.com', 'password' => 'password']);
        $this->site->members()->attach($this->owner->id, ['role' => 'owner', 'id' => (string) Str::uuid()]);
        $this->site->members()->attach($this->viewer->id, ['role' => 'viewer', 'id' => (string) Str::uuid()]);
        $this->at('2026-11-11 12:00:00'); // a Wednesday
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $when): void
    {
        CarbonImmutable::setTestNow($when);
        Carbon::setTestNow($when);
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withHeader('X-Requested-With', 'XMLHttpRequest');
    }

    private function visits(string $day, int $n, string $source = 'Google', ?string $goal = null): void
    {
        for ($i = 0; $i < $n; $i++) {
            $vid = (string) Str::uuid();
            $visitor = md5($vid);
            DB::table('visits')->insert(['id' => $vid, 'site_id' => $this->site->id, 'visitor_id' => $visitor, 'started_at' => "$day 09:00:00", 'ended_at' => "$day 09:05:00", 'pageviews' => 1, 'is_bounce' => false, 'duration' => 60, 'entry_page' => '/', 'exit_page' => '/', 'country' => 'DE', 'source' => $source]);
            DB::table('events')->insert(['site_id' => $this->site->id, 'visit_id' => $vid, 'visitor_id' => $visitor, 'name' => 'pageview', 'pathname' => '/', 'created_at' => "$day 09:00:00"]);
            if ($goal) {
                DB::table('events')->insert(['site_id' => $this->site->id, 'visit_id' => $vid, 'visitor_id' => $visitor, 'name' => $goal, 'pathname' => '/', 'created_at' => "$day 09:01:00"]);
            }
        }
    }

    /** The same weekday on each of the four weeks before the test's "today". */
    private function usual(int $perDay): void
    {
        foreach (['2026-11-04', '2026-10-28', '2026-10-21', '2026-10-14'] as $d) {
            $this->visits($d, $perDay);
        }
    }

    private function rule(string $type, array $config = [], array $extra = []): AlertRule
    {
        return AlertRule::create($extra + ['site_id' => $this->site->id, 'type' => $type, 'config' => $config, 'email' => true, 'webhook_ids' => []]);
    }

    // ------------------------------------------------------------------------------------------------ management
    public function test_only_managers_manage_alerts(): void
    {
        $this->as($this->viewer)->getJson('/api/sites/example.com/alerts')->assertForbidden();
        $this->as($this->viewer)->postJson('/api/sites/example.com/alert-rules', ['type' => 'traffic_spike', 'email' => true])->assertForbidden();
        $this->as($this->owner)->getJson('/api/sites/example.com/alerts')->assertOk()->assertJson(['mail' => true, 'rules' => [], 'webhooks' => []]);
    }

    public function test_webhook_addresses_must_be_public_and_secure(): void
    {
        foreach (['http://93.184.216.34/x', 'https://127.0.0.1/x', 'https://10.0.0.5/x', 'https://192.168.1.2/x', 'https://169.254.169.254/latest', 'https://user:pw@93.184.216.34/x', 'ftp://93.184.216.34/x', 'not a url'] as $url) {
            $this->assertNotNull(WebhookUrl::problem($url), $url);
            $this->as($this->owner)->postJson('/api/sites/example.com/alert-webhooks', ['name' => 'Bad', 'kind' => 'generic', 'url' => $url])->assertStatus(422)->assertJsonValidationErrors('url');
        }
        $this->assertNull(WebhookUrl::problem(self::HOOK));

        config(['tidemark.webhooks.allow_private' => true]);
        $this->assertNull(WebhookUrl::problem('http://192.168.1.2:8123/api/webhook/x'));
    }

    public function test_creating_a_webhook_shows_the_secret_once_and_hides_the_address(): void
    {
        $r = $this->as($this->owner)->postJson('/api/sites/example.com/alert-webhooks', ['name' => 'Automations', 'kind' => 'generic', 'url' => self::HOOK])->assertCreated();
        $this->assertStringStartsWith('whsec_', $r->json('data.secret'));
        $this->assertSame('https://93.184.216.34/…', $r->json('data.url'));
        $list = $this->as($this->owner)->getJson('/api/sites/example.com/alerts')->json('webhooks');
        $this->assertArrayNotHasKey('secret', $list[0]);
        $this->assertStringNotContainsString('abcdef', json_encode($list));
        $this->assertNotSame(self::HOOK, DB::table('alert_webhooks')->value('url'), 'stored encrypted');

        $chat = $this->as($this->owner)->postJson('/api/sites/example.com/alert-webhooks', ['name' => 'Team chat', 'kind' => 'slack', 'url' => self::HOOK])->assertCreated();
        $this->assertNull($chat->json('data.secret'));
    }

    public function test_rules_are_validated_per_type(): void
    {
        $goal = Goal::create(['site_id' => $this->site->id, 'type' => 'event', 'value' => 'Signup']);
        $base = '/api/sites/example.com/alert-rules';
        $this->as($this->owner)->postJson($base, ['type' => 'traffic_spike', 'config' => ['percent' => 80], 'email' => false])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->as($this->owner)->postJson($base, ['type' => 'nope', 'email' => true])->assertStatus(422);
        $this->as($this->owner)->postJson($base, ['type' => 'goal_milestone', 'config' => ['count' => 5], 'email' => true])->assertStatus(422)->assertJsonValidationErrors('config.goal_id');
        $this->as($this->owner)->postJson($base, ['type' => 'traffic_spike', 'email' => true, 'quiet_start' => '22:00'])->assertStatus(422)->assertJsonValidationErrors('quiet_start');
        $this->as($this->owner)->postJson($base, ['type' => 'traffic_spike', 'email' => true, 'webhook_ids' => [(string) Str::uuid()]])->assertStatus(422)->assertJsonValidationErrors('webhook_ids');

        $r = $this->as($this->owner)->postJson($base, ['type' => 'goal_milestone', 'config' => ['goal_id' => $goal->id, 'count' => 25, 'junk' => 1], 'email' => true, 'quiet_start' => '22:00', 'quiet_end' => '07:00'])->assertCreated();
        $this->assertSame(['goal_id' => $goal->id, 'count' => 25], $r->json('data.config'));

        $spike = $this->as($this->owner)->postJson($base, ['type' => 'traffic_spike', 'config' => ['percent' => 99999], 'email' => true])->assertCreated();
        $this->assertSame(1000, $spike->json('data.config.percent'));

        $this->as($this->owner)->patchJson("$base/{$spike->json('data.id')}", ['enabled' => false])->assertOk()->assertJson(['data' => ['enabled' => false, 'type' => 'traffic_spike']]);
        $this->as($this->owner)->deleteJson("$base/{$spike->json('data.id')}")->assertOk();
        $this->assertSame(1, AlertRule::count());
    }

    public function test_deleting_a_webhook_removes_it_from_rules_and_deleting_the_site_removes_everything(): void
    {
        $hook = $this->as($this->owner)->postJson('/api/sites/example.com/alert-webhooks', ['name' => 'H', 'kind' => 'generic', 'url' => self::HOOK])->json('data.id');
        $this->as($this->owner)->postJson('/api/sites/example.com/alert-rules', ['type' => 'traffic_spike', 'email' => true, 'webhook_ids' => [$hook]])->assertCreated();
        $this->as($this->owner)->deleteJson("/api/sites/example.com/alert-webhooks/$hook")->assertOk();
        $this->assertSame([], AlertRule::first()->webhook_ids);

        $this->as($this->owner)->deleteJson('/api/sites/example.com')->assertOk();
        $this->assertSame(0, AlertRule::count());
        $this->assertSame(0, AlertWebhook::count());
    }

    // ------------------------------------------------------------------------------------------------ rules firing
    public function test_a_traffic_spike_fires_once_and_then_waits(): void
    {
        $this->usual(10);
        $this->visits('2026-11-11', 100);
        $rule = $this->rule('traffic_spike', ['percent' => 100, 'min_visitors' => 50]);

        $this->artisan('alerts:run')->assertSuccessful();
        Mail::assertSent(AlertMail::class, fn (AlertMail $m) => $m->hasTo('owner@example.com') && $m->payload['event'] === 'traffic.spike' && $m->payload['data']['visitors_today'] === 100 && $m->payload['data']['change_percent'] === 900.0);
        Mail::assertNotSent(AlertMail::class, fn ($m) => $m->hasTo('viewer@example.com')); // owners and admins only
        $this->assertNotNull($rule->refresh()->last_fired_at);

        $this->artisan('alerts:run');
        Mail::assertSent(AlertMail::class, 1); // the cooldown holds it back

        $this->at('2026-11-11 19:00:00'); // six hours later it may speak again
        $this->artisan('alerts:run');
        Mail::assertSent(AlertMail::class, 2);
    }

    public function test_a_normal_day_is_not_a_spike_and_a_small_site_is_not_noisy(): void
    {
        $this->usual(10);
        $this->visits('2026-11-11', 14);
        $this->rule('traffic_spike', ['percent' => 100, 'min_visitors' => 5]);
        $this->artisan('alerts:run');
        Mail::assertNothingSent();

        $this->visits('2026-11-11', 20); // 34 visitors against 10 is big in percent, but under the minimum jump
        AlertRule::query()->update(['config' => ['percent' => 100, 'min_visitors' => 50]]);
        $this->artisan('alerts:run');
        Mail::assertNothingSent();
    }

    public function test_a_traffic_drop_needs_enough_of_the_day_and_a_real_baseline(): void
    {
        $this->usual(100);
        $this->visits('2026-11-11', 20);
        $this->rule('traffic_drop', ['percent' => 50, 'min_visitors' => 50]);

        $this->at('2026-11-11 05:00:00');
        $this->artisan('alerts:run');
        Mail::assertNothingSent(); // too early to call it

        $this->at('2026-11-11 12:00:00');
        $this->artisan('alerts:run');
        Mail::assertSent(AlertMail::class, fn (AlertMail $m) => $m->payload['event'] === 'traffic.drop' && $m->payload['data']['change_percent'] === -80.0);
    }

    public function test_a_goal_milestone_fires_once_a_day(): void
    {
        $goal = Goal::create(['site_id' => $this->site->id, 'type' => 'event', 'value' => 'Signup']);
        $this->visits('2026-11-11', 4, goal: 'Signup');
        $this->rule('goal_milestone', ['goal_id' => $goal->id, 'count' => 5]);
        $this->artisan('alerts:run');
        Mail::assertNothingSent();

        $this->visits('2026-11-11', 2, goal: 'Signup');
        $this->artisan('alerts:run');
        $this->artisan('alerts:run');
        Mail::assertSent(AlertMail::class, 1);
        Mail::assertSent(AlertMail::class, fn (AlertMail $m) => $m->payload['event'] === 'goal.milestone' && $m->payload['data']['conversions_today'] === 6);

        $this->at('2026-11-12 12:00:00');
        $this->visits('2026-11-12', 5, goal: 'Signup');
        $this->artisan('alerts:run');
        Mail::assertSent(AlertMail::class, 2); // a new day, a new milestone
    }

    public function test_goal_conversions_report_only_what_is_new(): void
    {
        $goal = Goal::create(['site_id' => $this->site->id, 'type' => 'event', 'value' => 'Signup']);
        $this->visits('2026-11-11', 3, goal: 'Signup');
        $this->rule('goal_conversions', ['goal_id' => $goal->id]);

        $this->artisan('alerts:run');
        Mail::assertNothingSent(); // earlier conversions aren't announced when the rule starts

        $this->visits('2026-11-11', 2, goal: 'Signup');
        $this->artisan('alerts:run');
        Mail::assertSent(AlertMail::class, fn (AlertMail $m) => $m->payload['event'] === 'goal.conversions' && $m->payload['data']['new_conversions'] === 2 && $m->payload['data']['conversions_today'] === 5);

        $this->artisan('alerts:run');
        Mail::assertSent(AlertMail::class, 1);
    }

    public function test_a_new_top_source_is_announced_once(): void
    {
        $this->visits('2026-11-05', 30, 'Google');
        $this->visits('2026-11-11', 10, 'Google');
        $this->visits('2026-11-11', 25, 'Hacker News');
        $this->rule('new_top_referrer', ['min_visitors' => 20]);

        $this->artisan('alerts:run');
        Mail::assertSent(AlertMail::class, fn (AlertMail $m) => $m->payload['event'] === 'traffic.new_source' && $m->payload['data']['sources'][0]['name'] === 'Hacker News' && count($m->payload['data']['sources']) === 1);
        $this->artisan('alerts:run');
        Mail::assertSent(AlertMail::class, 1);
    }

    public function test_quiet_hours_and_disabled_rules_stay_silent(): void
    {
        $this->usual(10);
        $this->visits('2026-11-11', 100);
        $rule = $this->rule('traffic_spike', ['percent' => 100, 'min_visitors' => 50], ['quiet_start' => '11:00', 'quiet_end' => '13:00']);
        $this->artisan('alerts:run');
        Mail::assertNothingSent();

        $this->assertTrue(RuleEvaluator::inQuietHours($rule->refresh()->forceFill(['quiet_start' => '22:00', 'quiet_end' => '07:00']), CarbonImmutable::parse('2026-11-11 23:30:00')), 'quiet hours may cross midnight');
        $this->assertFalse(RuleEvaluator::inQuietHours($rule, CarbonImmutable::parse('2026-11-11 12:00:00')));

        $rule->update(['quiet_start' => null, 'quiet_end' => null, 'enabled' => false]);
        $this->artisan('alerts:run');
        Mail::assertNothingSent();

        $rule->update(['enabled' => true]);
        $this->artisan('alerts:run');
        Mail::assertSent(AlertMail::class, 1);
    }

    // ------------------------------------------------------------------------------------------------ delivery
    public function test_a_generic_webhook_gets_a_signed_payload(): void
    {
        $this->usual(10);
        $this->visits('2026-11-11', 100);
        $hook = AlertWebhook::create(['site_id' => $this->site->id, 'name' => 'Automations', 'kind' => 'generic', 'url' => self::HOOK, 'secret' => 'whsec_test']);
        $this->rule('traffic_spike', ['percent' => 100, 'min_visitors' => 50], ['email' => false, 'webhook_ids' => [$hook->id]]);

        $this->artisan('alerts:run');
        Mail::assertNothingSent();
        Http::assertSent(function (Request $r) {
            [$t, $v1] = array_map(fn ($p) => explode('=', $p, 2)[1], explode(',', $r->header('Tidemark-Signature')[0]));
            $body = $r->body();
            $json = json_decode($body, true);

            return $r->url() === self::HOOK
                && hash_equals(hash_hmac('sha256', "$t.$body", 'whsec_test'), $v1)
                && $r->header('Tidemark-Event')[0] === 'traffic.spike'
                && $json['site']['domain'] === 'example.com' && $json['data']['visitors_today'] === 100 && isset($json['id'], $json['created_at'], $json['summary'])
                && ! str_contains($body, 'visitor_id');
        });
        $this->assertSame(200, $hook->refresh()->last_status);
        $this->assertNull($hook->last_error);
    }

    public function test_slack_and_discord_get_their_own_shape(): void
    {
        $payload = ['id' => 'x', 'event' => 'test', 'created_at' => 'now', 'site' => ['id' => 'a', 'domain' => 'example.com', 'url' => 'https://t.example/example.com'], 'summary' => 'Hello', 'data' => []];
        $slack = AlertWebhook::create(['site_id' => $this->site->id, 'name' => 'S', 'kind' => 'slack', 'url' => self::HOOK]);
        $discord = AlertWebhook::create(['site_id' => $this->site->id, 'name' => 'D', 'kind' => 'discord', 'url' => self::HOOK]);
        DeliverWebhook::deliver($slack, $payload);
        DeliverWebhook::deliver($discord, $payload);
        Http::assertSent(fn (Request $r) => isset($r->data()['text']) && ! $r->hasHeader('Tidemark-Signature') && str_contains($r->data()['text'], 'Hello'));
        Http::assertSent(fn (Request $r) => isset($r->data()['content']) && str_contains($r->data()['content'], '**Hello**'));
    }

    public function test_a_failing_webhook_is_recorded_and_retried(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('nope', 500)]);
        $hook = AlertWebhook::create(['site_id' => $this->site->id, 'name' => 'H', 'kind' => 'generic', 'url' => self::HOOK, 'secret' => 's']);
        $job = new DeliverWebhook($hook->id, ['id' => 'x', 'event' => 'test', 'summary' => 's', 'site' => ['id' => 'a', 'domain' => 'example.com', 'url' => 'u'], 'created_at' => 'now', 'data' => []]);
        try {
            $job->handle();
            $this->fail('A server error should be retried');
        } catch (\RuntimeException) {
        }
        $this->assertSame([60, 300, 900, 3600], $job->backoff());
        $this->assertSame(500, $hook->refresh()->last_status);
        $this->assertSame('It answered with HTTP 500.', $hook->last_error);

        Http::swap(new Factory);
        Http::fake(['*' => Http::response('gone', 404)]);
        $job->handle(); // a 4xx will not get better, so it is not retried
        $this->assertSame(404, $hook->refresh()->last_status);
    }

    public function test_the_test_button_reports_the_answer_and_never_follows_private_addresses(): void
    {
        $hook = $this->as($this->owner)->postJson('/api/sites/example.com/alert-webhooks', ['name' => 'H', 'kind' => 'generic', 'url' => self::HOOK])->json('data.id');
        $this->as($this->owner)->postJson("/api/sites/example.com/alert-webhooks/$hook/test")->assertOk()->assertJson(['ok' => true, 'status' => 200]);
        Http::assertSent(fn (Request $r) => $r->header('Tidemark-Event')[0] === 'test');

        // an address that turned private after it was saved is refused when sending
        AlertWebhook::find($hook)->update(['url' => 'https://127.0.0.1/x']);
        Http::swap(new Factory);
        Http::fake();
        $this->as($this->owner)->postJson("/api/sites/example.com/alert-webhooks/$hook/test")->assertOk()->assertJson(['ok' => false, 'status' => 0]);
        Http::assertNothingSent();
    }
}
