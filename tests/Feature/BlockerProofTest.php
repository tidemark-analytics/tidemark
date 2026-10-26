<?php

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Nothing the browser loads or sends should carry a name that content blockers match on sight. */
class BlockerProofTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    /** Words that blocklists (EasyPrivacy and friends) match in file names. The tracker itself is deliberately not in the dashboard's assets. */
    private const BLOCKED_WORDS = ['plausible', 'analytics', 'tracker', 'tracking', 'track', 'pageview', 'beacon', 'telemetry', 'matomo', 'piwik', 'umami', 'fathom', 'hotjar', 'gtag', 'adsense', 'doubleclick'];

    public function test_no_file_the_dashboard_loads_has_a_blocklisted_name(): void
    {
        $offenders = [];
        foreach (['assets', 'js'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(public_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $relative = str_replace(public_path().'/', '', $file->getPathname());
                if ($relative === 'js/script.js' || str_ends_with($relative, 'LICENSE.txt')) {
                    continue; // the classic tracker file name stays for sites that already use it
                }
                foreach (self::BLOCKED_WORDS as $word) {
                    if (str_contains(strtolower($relative), $word)) {
                        $offenders[] = "{$relative} ({$word})";
                    }
                }
            }
        }
        $this->assertSame([], $offenders, 'These files would be blocked by common content blockers: '.implode(', ', $offenders));
    }

    public function test_a_sites_code_is_stable_distinct_and_not_its_domain(): void
    {
        $a = Site::create(['domain' => 'example.com']);
        $b = Site::create(['domain' => 'example.org']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{10}$/', $a->trackerCode());
        $this->assertSame($a->trackerCode(), Site::find($a->id)->trackerCode());
        $this->assertNotSame($a->trackerCode(), $b->trackerCode());
    }

    public function test_the_snippet_file_name_uses_the_sites_code_and_keeps_the_variants(): void
    {
        $site = Site::create(['domain' => 'example.com', 'track_outbound' => true, 'track_downloads' => true]);
        $this->assertSame($site->trackerCode().'.outbound-links.file-downloads.js', $site->scriptName());
    }

    public function test_the_script_is_served_under_the_sites_code_and_the_old_name_still_works(): void
    {
        $code = Site::create(['domain' => 'example.com'])->trackerCode();
        $this->get("/js/{$code}.js")->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
        $this->get("/js/{$code}.outbound-links.tagged-events.js")->assertOk();
        $this->get('/js/script.outbound-links.js')->assertOk();
        $this->get('/js/abc.js')->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_events_posted_to_the_sites_address_are_collected_without_cookies(): void
    {
        $site = Site::create(['domain' => 'example.com']);
        $headers = $this->transformHeadersToServerVars(['Accept-Language' => 'en-US,en;q=0.9', 'User-Agent' => self::CHROME, 'Content-Type' => 'text/plain']);
        $body = json_encode(['n' => 'pageview', 'u' => 'https://example.com/pricing', 'd' => 'example.com', 'w' => 1920]);

        $this->call('OPTIONS', "/e/{$site->trackerCode()}")->assertStatus(204)->assertHeader('Access-Control-Allow-Origin', '*');
        $this->call('POST', "/e/{$site->trackerCode()}", [], [], [], $headers, $body)->assertStatus(202)->assertHeaderMissing('Set-Cookie');
        $this->assertSame('/pricing', DB::table('events')->value('pathname'));
        $this->call('POST', '/api/event', [], [], [], $headers, $body)->assertStatus(202); // the classic address is unchanged
        $this->assertNotSame(202, $this->call('POST', '/e/not-a-code', [], [], [], $headers, $body)->getStatusCode()); // only well-formed codes collect
    }

    public function test_the_script_posts_to_the_address_that_matches_its_file_name(): void
    {
        $js = file_get_contents(public_path('js/script.js'));
        $this->assertStringContainsString("'/e/' + alias", $js);
        $this->assertStringContainsString("'/api/event'", $js);
    }
}
