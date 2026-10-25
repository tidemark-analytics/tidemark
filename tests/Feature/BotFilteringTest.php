<?php

namespace Tests\Feature;

use App\Analytics\Support\DatacenterRanges;
use App\Analytics\Support\FilteredHits;
use App\Analytics\Support\ReferrerSpam;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** What counts as a person's visit: crawlers, headless browsers, referrer spam, data center traffic and floods are counted apart. */
class BotFilteringTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->site = Site::create(['domain' => 'example.com']);
    }

    private function hit(array $payload = [], array $headers = [], string $ua = self::CHROME)
    {
        return $this->call('POST', '/api/event', [], [], [], $this->transformHeadersToServerVars($headers + ['Accept-Language' => 'en-US,en;q=0.9', 'User-Agent' => $ua, 'Content-Type' => 'text/plain']),
            json_encode($payload + ['n' => 'pageview', 'u' => 'https://example.com/', 'd' => 'example.com', 'w' => 1920]));
    }

    private function filtered(): array
    {
        return collect(FilteredHits::recent($this->site->id))->pluck('count', 'reason')->all();
    }

    public function test_a_normal_visit_is_recorded_and_nothing_is_filtered(): void
    {
        $this->hit(['r' => 'https://www.google.com/'], ['X-Forwarded-For' => '203.0.113.9'])->assertStatus(202);
        $this->assertSame(1, DB::table('events')->count());
        $this->assertSame([], $this->filtered());
    }

    public function test_crawlers_are_left_out_and_counted(): void
    {
        $this->hit([], [], 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')->assertStatus(202);
        $this->hit([], [], '')->assertStatus(202);
        $this->assertSame(0, DB::table('events')->count());
        $this->assertSame(['crawler' => 2], $this->filtered());
    }

    public function test_headless_browsers_and_clients_without_a_language_are_left_out(): void
    {
        $this->hit([], ['Sec-CH-UA' => '"HeadlessChrome";v="124", "Chromium";v="124"'])->assertStatus(202);
        $this->hit([], ['Accept-Language' => ''])->assertStatus(202);
        $this->assertSame(0, DB::table('events')->count());
        $this->assertSame(['headless' => 2], $this->filtered());

        $this->hit([], ['Sec-CH-UA' => '"Google Chrome";v="124", "Chromium";v="124"'])->assertStatus(202);
        $this->assertSame(1, DB::table('events')->count());
    }

    public function test_referrer_spam_is_left_out_wherever_the_referrer_comes_from(): void
    {
        $spam = array_key_first(require ReferrerSpam::path());
        $this->hit(['r' => "https://{$spam}/offer"]);
        $this->hit(['r' => "https://shop.{$spam}/"]);
        $this->hit(['u' => "https://example.com/?utm_source={$spam}"]);
        $this->assertSame(0, DB::table('events')->count());
        $this->assertSame(['referrer_spam' => 3], $this->filtered());

        $this->hit(['r' => "https://not{$spam}/"]); // only the domain and its subdomains match
        $this->assertSame(1, DB::table('events')->count());
    }

    public function test_the_referrer_spam_list_matches_domains_not_lookalikes(): void
    {
        $spam = array_key_first(require ReferrerSpam::path());
        $this->assertTrue(ReferrerSpam::matches($spam));
        $this->assertTrue(ReferrerSpam::matches("https://www.{$spam}/x?y=1"));
        $this->assertFalse(ReferrerSpam::matches("https://{$spam}.example.org/"));
        $this->assertFalse(ReferrerSpam::matches('https://www.google.com/'));
        $this->assertFalse(ReferrerSpam::matches(null));
    }

    public function test_data_center_addresses_are_left_out(): void
    {
        [$start] = (require DatacenterRanges::path())['v4'][0];
        $this->assertTrue(DatacenterRanges::contains(long2ip($start)));
        $this->hit([], ['X-Forwarded-For' => long2ip($start)]);
        $this->assertSame(0, DB::table('events')->count());
        $this->assertSame(['datacenter' => 1], $this->filtered());

        $this->hit([], ['X-Forwarded-For' => '203.0.113.9']);
        $this->assertSame(1, DB::table('events')->count());
    }

    public function test_ranges_are_merged_and_matched_for_both_address_families(): void
    {
        $data = DatacenterRanges::build(['203.0.113.0/25', '203.0.113.128/25', '198.51.100.0/24', '2001:db8:abcd::/48', 'junk', '10.0.0.0/4']);
        $this->assertCount(2, $data['v4'], 'the two halves of a /24 become one range');
        foreach (['203.0.113.0', '203.0.113.255', '198.51.100.77', '2001:db8:abcd:1234::1', '::ffff:203.0.113.5'] as $ip) {
            $this->assertTrue(DatacenterRanges::matches($ip, $data), $ip);
        }
        foreach (['203.0.114.0', '203.0.112.255', '198.51.101.1', '2001:db8:abce::1', 'not an address'] as $ip) {
            $this->assertFalse(DatacenterRanges::matches($ip, $data), $ip);
        }
    }

    public function test_a_flood_from_one_visitor_is_cut_off(): void
    {
        config(['tidemark.flood_per_minute' => 3]);
        foreach (range(1, 5) as $i) {
            $this->hit([], ['X-Forwarded-For' => '203.0.113.9']);
        }
        $this->assertSame(3, DB::table('events')->count());
        $this->assertSame(['flood' => 2], $this->filtered());

        $this->hit([], ['X-Forwarded-For' => '203.0.113.10']); // somebody else is unaffected
        $this->assertSame(4, DB::table('events')->count());
    }

    public function test_the_site_can_turn_filtering_off(): void
    {
        $this->site->update(['bot_filter' => false]);
        $spam = array_key_first(require ReferrerSpam::path());
        $this->hit([], [], 'Mozilla/5.0 (compatible; Googlebot/2.1)');
        $this->hit(['r' => "https://{$spam}/"]);
        $this->hit([], ['Accept-Language' => '']);
        $this->assertSame(3, DB::table('events')->count());
        $this->assertSame([], $this->filtered());
    }

    public function test_managers_see_the_counts_and_can_switch_filtering(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'o@example.com', 'password' => 'password']);
        $viewer = User::create(['name' => 'Viewer', 'email' => 'v@example.com', 'password' => 'password']);
        $this->site->members()->attach($owner->id, ['role' => 'owner', 'id' => (string) Str::uuid()]);
        $this->site->members()->attach($viewer->id, ['role' => 'viewer', 'id' => (string) Str::uuid()]);
        $this->hit([], [], 'Googlebot/2.1');
        $this->hit([], ['Accept-Language' => '']);
        $this->hit([], [], 'Googlebot/2.1');

        $this->actingAs($viewer)->withHeader('X-Requested-With', 'XMLHttpRequest')->getJson('/api/sites/example.com/filtered')->assertForbidden();
        $this->actingAs($owner)->withHeader('X-Requested-With', 'XMLHttpRequest')->getJson('/api/sites/example.com/filtered')->assertOk()
            ->assertJson(['days' => 30, 'results' => [['reason' => 'crawler', 'count' => 2], ['reason' => 'headless', 'count' => 1]]]);

        $this->actingAs($owner)->withHeader('X-Requested-With', 'XMLHttpRequest')->patchJson('/api/sites/example.com', ['bot_filter' => false])->assertOk()->assertJson(['data' => ['bot_filter' => false]]);
        $this->hit([], [], 'Googlebot/2.1');
        $this->assertSame(1, DB::table('events')->count(), 'the change applies at once, not after the settings cache expires');

        $this->actingAs($owner)->withHeader('X-Requested-With', 'XMLHttpRequest')->deleteJson('/api/sites/example.com')->assertOk();
        $this->assertSame(0, DB::table('filtered_hits')->count());
    }
}
