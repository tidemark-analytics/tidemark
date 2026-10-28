<?php

namespace Tests\Feature;

use App\Analytics\Ingest\DatabaseSink;
use App\Analytics\Support\Sources;
use App\Jobs\IngestJob;
use App\Models\ShieldRule;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CollectTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    private function site(array $attrs = []): Site
    {
        return Site::create($attrs + ['domain' => 'example.com']);
    }

    private function hit(array $payload = [], array $headers = [], string $ua = self::CHROME)
    {
        return $this->call('POST', '/api/event', [], [], [], $this->transformHeadersToServerVars($headers + ['Accept-Language' => 'en-US,en;q=0.9', 'User-Agent' => $ua, 'Content-Type' => 'text/plain']),
            json_encode($payload + ['n' => 'pageview', 'u' => 'https://example.com/', 'd' => 'example.com', 'w' => 1920]));
    }

    public function test_pageview_creates_visit_and_event_with_plausible_payload(): void
    {
        $this->site();
        $this->hit(['u' => 'https://example.com/pricing?utm_source=news&utm_medium=email&utm_campaign=spring', 'r' => 'https://www.google.com/search?q=x'],
            ['CF-IPCountry' => 'de', 'CF-IPCity' => 'Berlin'])->assertStatus(202);

        $v = DB::table('visits')->first();
        $this->assertSame('/pricing', $v->entry_page);
        $this->assertSame('news', strtolower($v->utm_source));
        $this->assertSame('Email', $v->channel);
        $this->assertSame('DE', $v->country);
        $this->assertSame('Berlin', $v->city);
        $this->assertSame('Chrome', $v->browser);
        $this->assertSame('Mac', $v->os);
        $this->assertSame('Desktop', $v->device);
        $this->assertSame(1, (int) $v->pageviews);
        $this->assertSame(['pageview', '/pricing'], [DB::table('events')->value('name'), DB::table('events')->value('pathname')]);
    }

    public function test_hits_within_thirty_minutes_form_one_visit_and_bounce_is_cleared(): void
    {
        $this->site();
        $this->hit(['u' => 'https://example.com/a']);
        $this->assertTrue((bool) DB::table('visits')->value('is_bounce'));
        $this->hit(['u' => 'https://example.com/b']);
        $this->assertSame(1, DB::table('visits')->count());
        $v = DB::table('visits')->first();
        $this->assertFalse((bool) $v->is_bounce);
        $this->assertSame('/a', $v->entry_page);
        $this->assertSame('/b', $v->exit_page);
        $this->assertSame(2, (int) $v->pageviews);
        $this->assertSame(2, DB::table('events')->count());
    }

    public function test_a_non_interactive_event_is_stored_but_leaves_the_visit_as_it_was(): void
    {
        $this->site();
        $this->hit(['u' => 'https://example.com/post']);
        $before = DB::table('visits')->first();
        $this->hit(['n' => 'Engagement', 'u' => 'https://example.com/post', 'i' => 0, 'p' => ['depth' => '75', 'time' => '30-60s']])->assertStatus(202);

        $v = DB::table('visits')->first();
        $this->assertSame(1, DB::table('visits')->count());
        $this->assertTrue((bool) $v->is_bounce, 'an engagement measurement is not an interaction');
        $this->assertSame([(int) $before->events, (int) $before->pageviews, $before->ended_at], [(int) $v->events, (int) $v->pageviews, $v->ended_at]);
        $this->assertSame(['pageview', 'Engagement'], DB::table('events')->orderBy('id')->pluck('name')->all());
        $this->assertSame(['depth' => '75', 'time' => '30-60s'], DB::table('event_props')->where('event_id', DB::table('events')->where('name', 'Engagement')->value('id'))->pluck('value', 'key')->all());

        $this->hit(['u' => 'https://example.com/next']);
        $this->assertFalse((bool) DB::table('visits')->value('is_bounce'), 'a later pageview still ends the bounce');
    }

    public function test_a_repeated_measurement_replaces_the_earlier_one_for_the_same_page_view(): void
    {
        $this->site();
        $props = fn () => DB::table('event_props')->join('events', 'events.id', '=', 'event_props.event_id')->where('events.name', 'Engagement')->orderBy('events.id')->get(['events.id', 'key', 'value'])
            ->groupBy('id')->map(fn ($rows) => $rows->pluck('value', 'key')->all())->values()->all();

        $this->hit(['u' => 'https://example.com/post']);
        $this->hit(['n' => 'Engagement', 'u' => 'https://example.com/post', 'i' => 0, 'p' => ['depth' => '0', 'time' => '0-10s']]);
        $this->hit(['n' => 'Engagement', 'u' => 'https://example.com/post', 'i' => 0, 'p' => ['depth' => '75', 'time' => '1-3m']]);

        $this->assertSame([['depth' => '75', 'time' => '1-3m']], $props(), 'the latest totals replace the first');
        $this->assertSame(1, DB::table('events')->where('name', 'Engagement')->count());

        // the same page viewed again later in the visit is a new page view with its own measurement
        $this->hit(['u' => 'https://example.com/other']);
        $this->hit(['u' => 'https://example.com/post']);
        $this->hit(['n' => 'Engagement', 'u' => 'https://example.com/post', 'i' => 0, 'p' => ['depth' => '25', 'time' => '0-10s']]);

        $this->assertSame([['depth' => '75', 'time' => '1-3m'], ['depth' => '25', 'time' => '0-10s']], $props());
    }

    public function test_a_non_interactive_event_never_starts_a_visit(): void
    {
        $this->site();
        $this->hit(['n' => 'Engagement', 'i' => 0, 'p' => ['depth' => '100', 'time' => '3m+']])->assertStatus(202);
        $this->hit(['n' => 'Engagement', 'interactive' => false])->assertStatus(202);

        $this->assertSame(0, DB::table('visits')->count());
        $this->assertSame(0, DB::table('events')->count());
    }

    public function test_a_pageview_is_always_interactive(): void
    {
        $this->site();
        $this->hit(['i' => 0])->assertStatus(202);

        $this->assertSame(1, DB::table('visits')->count());
        $this->assertSame(1, DB::table('events')->count());
    }

    public function test_inactivity_over_thirty_minutes_starts_a_new_visit(): void
    {
        $this->site();
        $this->hit();
        DB::table('visits')->update(['ended_at' => now()->subMinutes(45)->format('Y-m-d H:i:s'), 'started_at' => now()->subMinutes(45)->format('Y-m-d H:i:s')]);
        \Cache::flush();
        $this->hit();
        $this->assertSame(2, DB::table('visits')->count());
    }

    public function test_custom_event_props_are_stored_as_strings(): void
    {
        $this->site();
        $this->hit(['n' => 'Signup', 'p' => ['plan' => 'pro', 'trial' => true, 'seats' => 3, 'nested' => ['x' => 1]]]);
        $props = DB::table('event_props')->pluck('value', 'key')->all();
        $this->assertSame(['plan' => 'pro', 'trial' => 'true', 'seats' => '3'], $props);
        $this->assertSame(0, (int) DB::table('visits')->value('pageviews'));
        $this->hit(['n' => 'Signup', 'p' => json_encode(['plan' => 'team'])]); // props may also be a JSON string
        $this->assertContains('team', DB::table('event_props')->pluck('value')->all());
    }

    public function test_bad_requests_and_unknown_sites(): void
    {
        $this->site();
        $this->hit(['d' => 'nope.org'])->assertStatus(404);
        $this->call('POST', '/api/event', [], [], [], $this->transformHeadersToServerVars(['Accept-Language' => 'en-US,en;q=0.9', 'User-Agent' => self::CHROME]), '{"n":"pageview"}')->assertStatus(400);
        $this->hit(['u' => 'javascript:alert(1)'])->assertStatus(400);
        $this->assertSame(0, DB::table('events')->count());
    }

    public function test_bots_are_accepted_but_not_recorded(): void
    {
        $this->site();
        $this->hit([], [], 'Googlebot/2.1 (+http://www.google.com/bot.html)')->assertStatus(202);
        $this->assertSame(0, DB::table('events')->count());
    }

    public function test_www_and_multiple_domains_resolve_to_the_site(): void
    {
        $this->site();
        $this->hit(['d' => 'www.example.com'])->assertStatus(202);
        $this->hit(['d' => 'other.org,example.com'])->assertStatus(202);
        $this->assertSame(2, DB::table('events')->count());
    }

    public function test_shields_block_ip_country_page_and_hostname(): void
    {
        $site = $this->site();
        foreach ([['ip', '203.0.113.0/24'], ['country', 'FR'], ['page', '/admin/*'], ['hostname', 'example.com']] as [$t, $v]) {
            ShieldRule::create(['site_id' => $site->id, 'type' => $t, 'value' => $v]);
        }
        $this->hit([], ['X-Real-IP' => '203.0.113.9'])->assertStatus(202);          // blocked IP
        $this->hit([], ['CF-IPCountry' => 'FR'])->assertStatus(202);                // blocked country
        $this->hit(['u' => 'https://example.com/admin/users'])->assertStatus(202);   // blocked page
        $this->hit(['u' => 'https://staging.example.com/'])->assertStatus(202);      // hostname not in allow-list
        $this->assertSame(0, DB::table('events')->count());
        $this->hit(['u' => 'https://example.com/ok'], ['X-Real-IP' => '198.51.100.7'])->assertStatus(202);
        $this->assertSame(1, DB::table('events')->count());
    }

    public function test_cors_preflight_and_tracker_variants_are_served(): void
    {
        $this->call('OPTIONS', '/api/event')->assertStatus(204)->assertHeader('Access-Control-Allow-Origin', '*');
        $this->get('/js/script.js')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
        $this->get('/js/script.outbound-links.file-downloads.js')->assertOk();
        $this->get('/js/script.engagement.js')->assertOk();
        $this->get('/js/other.js')->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_visitor_id_is_stable_within_a_day_and_never_contains_the_ip(): void
    {
        $this->site();
        $this->hit([], ['X-Real-IP' => '198.51.100.7']);
        $this->hit(['u' => 'https://example.com/b'], ['X-Real-IP' => '198.51.100.7']);
        $this->assertSame(1, DB::table('events')->distinct()->count('visitor_id'));
        $dump = json_encode(DB::table('visits')->get()).json_encode(DB::table('events')->get());
        $this->assertStringNotContainsString('198.51.100.7', $dump);
        $this->hit([], ['X-Real-IP' => '198.51.100.8']);
        $this->assertSame(2, DB::table('events')->distinct()->count('visitor_id'));
    }

    public function test_source_and_channel_classification(): void
    {
        $c = fn ($ref, $utm = [], $host = 'example.com') => Sources::classify($ref, $utm, $host);
        $this->assertSame(['Direct', null], [$c(null)['channel'], $c(null)['source']]);
        $this->assertSame(['Organic Search', 'Google'], [$c('https://www.google.de/')['channel'], $c('https://www.google.de/')['source']]);
        $this->assertSame('Organic Social', $c('https://news.ycombinator.com/item?id=1')['channel']);
        $this->assertSame('Hacker News', $c('https://news.ycombinator.com/item?id=1')['source']);
        $this->assertSame('Referral', $c('https://blog.someone.dev/post')['channel']);
        $this->assertSame('Paid Search', $c('https://www.google.com/', ['medium' => 'cpc', 'source' => 'google'])['channel']);
        $this->assertSame('Paid Social', $c(null, ['medium' => 'cpc', 'source' => 'facebook'])['channel']);
        $this->assertSame('Email', $c(null, ['medium' => 'email', 'source' => 'newsletter'])['channel']);
        $this->assertSame('Direct', $c('https://example.com/other')['channel']);        // internal navigation
        $this->assertSame('Direct', $c('https://app.example.com/x')['channel']);        // own subdomain
        $this->assertSame('ChatGPT', $c('https://chatgpt.com/')['source']);
    }

    public function test_queue_ingest_defers_writes(): void
    {
        \Queue::fake();
        config(['tidemark.ingest' => 'queue']);
        $this->site();
        $this->hit()->assertStatus(202);
        \Queue::assertPushed(IngestJob::class);
        $this->assertSame(0, DB::table('events')->count());
        \Queue::pushed(IngestJob::class)->first()->handle(app(DatabaseSink::class));
        $this->assertSame(1, DB::table('events')->count());
    }

    public function test_collection_never_sets_a_cookie_or_starts_a_session(): void
    {
        $this->site();
        $res = $this->hit()->assertStatus(202);
        $this->assertEmpty($res->headers->getCookies());
        $this->assertFalse($res->headers->has('Set-Cookie'));
        $this->call('OPTIONS', '/api/event')->assertHeaderMissing('Set-Cookie');
    }
}
