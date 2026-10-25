<?php

namespace Tests\Feature;

use App\Analytics\Support\PiiMasks;
use App\Analytics\Support\PiiScrubber;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Personal data that sites send by accident is masked before it is stored, and only how often is counted. */
class PiiScrubberTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    private function hit(array $payload = [], array $headers = [])
    {
        return $this->call('POST', '/api/event', [], [], [], $this->transformHeadersToServerVars($headers + ['User-Agent' => self::CHROME, 'Content-Type' => 'text/plain']),
            json_encode($payload + ['n' => 'pageview', 'u' => 'https://example.com/', 'd' => 'example.com', 'w' => 1920]));
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, int>}> */
    public static function values(): array
    {
        return [
            'email in a path' => ['/account/jane.doe+tag@example.co.uk/settings', '/account/[email]/settings', ['email' => 1]],
            'uuid' => ['/invoices/550e8400-e29b-41d4-a716-446655440000', '/invoices/[uuid]', ['uuid' => 1]],
            'card or account number' => ['/orders/4111111111111111/receipt', '/orders/[number]/receipt', ['number' => 1]],
            'reset token' => ['/reset-password/Xk3Zq9TbR7mLw2NcVd8Hs4YgFa6Jp1Qe', '/reset-password/[token]', ['token' => 1]],
            'token with a dash and underscore' => ['/t/xK3_zQ9-TbR7mLw2NcVd8Hs4YgFa6Jp1Qe', '/t/[token]', ['token' => 1]],
            'hex hash' => ['/t/3f9a2c7e1b8d4f6a0c5e9b2d7a4f1c8e', '/t/[token]', ['token' => 1]],
            'jwt in a query' => ['/cb?code=eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r', '/cb?code=[token]', ['token' => 1]],
            'two kinds at once' => ['/u/a@b.co/o/123456789012', '/u/[email]/o/[number]', ['email' => 1, 'number' => 1]],
        ];
    }

    /** @dataProvider values */
    #[DataProvider('values')]
    public function test_personal_data_is_masked(string $in, string $out, array $counts): void
    {
        $found = [];

        $this->assertSame($out, PiiScrubber::scrub($in, $found));
        $this->assertSame($counts, $found);
    }

    /** @return array<string, array{0: string}> */
    public static function harmless(): array
    {
        return [
            ['/pricing'], ['/page/2?lang=de'], ['/orders/10234'], ['/posts/1234567'], ['/blog/2026/11/07/how-we-built-privacy-first-analytics-without-cookies'],
            ['/blog/2026-11-07-announcing-the-next-major-release-of-tidemark-analytics'], ['/en/products/the-quick-brown-fox-jumps-over-the-lazy-dog-1234'],
            ['/docs/install-on-shared-hosting-with-sqlite-mysql-or-postgresql-databases'], ['/ProductDetailPageVariantB2'], ['/download/1.2.3/tidemark.zip'], ['/@handle/status'],
        ];
    }

    /** @dataProvider harmless */
    #[DataProvider('harmless')]
    public function test_ordinary_addresses_are_left_alone(string $path): void
    {
        $found = [];

        $this->assertSame($path, PiiScrubber::scrub($path, $found));
        $this->assertSame([], $found);
    }

    public function test_collection_masks_paths_referrers_campaigns_events_and_properties(): void
    {
        $site = Site::create(['domain' => 'example.com']);

        $this->hit(['u' => 'https://example.com/account/jane.doe@example.com/settings?utm_source=news&utm_content=jane.doe@example.com',
            'r' => 'https://forum.example.org/t/Xk3Zq9TbR7mLw2NcVd8Hs4YgFa6Jp1Qe'])->assertStatus(202);
        $this->hit(['n' => 'Signup for jane@example.com', 'p' => ['plan' => 'pro', 'email' => 'jane@example.com', 'order jane@x.co' => 'a', 'account' => '123456789012']])->assertStatus(202);

        $paths = DB::table('events')->orderBy('id')->pluck('pathname')->all();
        $this->assertSame(['/account/[email]/settings', '/'], $paths);
        $v = DB::table('visits')->orderBy('id')->first();
        $this->assertSame('forum.example.org/t/[token]', $v->referrer);
        $this->assertSame('[email]', $v->utm_content);
        $this->assertSame('Signup for [email]', DB::table('events')->where('name', 'like', 'Signup%')->value('name'));
        $props = DB::table('event_props')->pluck('value', 'key')->all();
        $this->assertSame('pro', $props['plan']);
        $this->assertSame('[email]', $props['email']);
        $this->assertSame('[number]', $props['account']);
        $this->assertArrayHasKey('order [email]', $props);

        // nothing recognisable is left in the database
        foreach (['jane.doe@example.com', 'jane@example.com', '123456789012', 'Xk3Zq9TbR7mLw2NcVd8Hs4YgFa6Jp1Qe'] as $secret) {
            foreach (['events' => ['pathname', 'name'], 'visits' => ['referrer', 'utm_content', 'entry_page', 'exit_page'], 'event_props' => ['key', 'value']] as $table => $cols) {
                foreach ($cols as $col) {
                    $this->assertSame(0, DB::table($table)->where($col, 'like', "%{$secret}%")->count(), "{$secret} in {$table}.{$col}");
                }
            }
        }
        // only counts were kept, by kind
        $counts = collect(PiiMasks::recent($site->id))->pluck('count', 'pattern')->all();
        ksort($counts);
        $this->assertSame(['email' => 5, 'number' => 1, 'token' => 1], $counts);
    }

    public function test_it_can_be_switched_off_for_a_site(): void
    {
        $site = Site::create(['domain' => 'example.com', 'pii_scrub' => false]);

        $this->hit(['u' => 'https://example.com/account/jane.doe@example.com/settings'])->assertStatus(202);

        $this->assertSame('/account/jane.doe@example.com/settings', DB::table('events')->value('pathname'));
        $this->assertSame([], PiiMasks::recent($site->id));
    }

    public function test_a_normal_visit_records_no_counts(): void
    {
        $site = Site::create(['domain' => 'example.com']);

        $this->hit(['u' => 'https://example.com/pricing?page=2', 'r' => 'https://news.example.org/story/42', 'p' => ['plan' => 'pro']])->assertStatus(202);

        $this->assertSame([], PiiMasks::recent($site->id));
        $this->assertSame(0, DB::table('pii_masks')->count());
    }

    public function test_counts_add_up_over_hits_and_only_the_last_thirty_days_are_shown(): void
    {
        $site = Site::create(['domain' => 'example.com']);
        $this->hit(['u' => 'https://example.com/a/x@y.co']);
        $this->hit(['u' => 'https://example.com/b/x@y.co']);
        DB::table('pii_masks')->insert(['site_id' => $site->id, 'day' => gmdate('Y-m-d', strtotime('-40 days')), 'pattern' => 'email', 'count' => 500]);

        $this->assertSame([['pattern' => 'email', 'count' => 2]], PiiMasks::recent($site->id));
    }

    public function test_owners_switch_it_and_read_the_counts_through_the_api(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
        $this->actingAs($owner)->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->postJson('/api/sites', ['domain' => 'example.com', 'timezone' => 'UTC'])->assertCreated()->assertJsonPath('data.pii_scrub', true);
        $this->hit(['u' => 'https://example.com/a/x@y.co']);

        $this->getJson('/api/sites/example.com/pii-masks')->assertOk()->assertJsonPath('days', 30)->assertJsonPath('results.0.pattern', 'email')->assertJsonPath('results.0.count', 1);
        $this->patchJson('/api/sites/example.com', ['pii_scrub' => false])->assertOk()->assertJsonPath('data.pii_scrub', false);

        // a viewer cannot read the counts
        $viewer = User::create(['name' => 'Viewer', 'email' => 'viewer@example.com', 'password' => 'password']);
        Site::where('domain', 'example.com')->first()->members()->attach($viewer->id, ['role' => 'viewer', 'id' => (string) Str::uuid()]);
        $this->actingAs($viewer)->getJson('/api/sites/example.com/pii-masks')->assertForbidden();
    }

    public function test_resetting_statistics_clears_the_counts_too(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
        $this->actingAs($owner)->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->postJson('/api/sites', ['domain' => 'example.com', 'timezone' => 'UTC'])->assertCreated();
        $this->hit(['u' => 'https://example.com/a/x@y.co']);
        $this->assertSame(1, DB::table('pii_masks')->count());

        $this->postJson('/api/sites/example.com/reset')->assertOk();

        $this->assertSame(0, DB::table('pii_masks')->count());
    }
}
