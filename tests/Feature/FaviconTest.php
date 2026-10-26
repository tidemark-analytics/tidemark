<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use App\Support\Favicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Site icons: fetched by the server from public https addresses only, kept small and raster, and remembered. */
class FaviconTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x10\x00\x00\x00\x10";

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
    }

    private function member(string $domain = 'example.com'): User
    {
        $user = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
        $site = Site::create(['domain' => $domain, 'timezone' => 'UTC']);
        $site->members()->attach($user->id, ['role' => 'owner', 'id' => (string) Str::uuid()]);

        return $user;
    }

    public function test_the_icon_named_in_the_page_is_served_with_the_type_of_its_bytes(): void
    {
        $html = '<html><head><link rel="shortcut icon" href="/static/logo.png"></head></html>';
        Http::fake(['https://example.com/' => Http::response($html), 'https://example.com/static/logo.png' => Http::response(self::PNG, 200, ['Content-Type' => 'text/html'])]);
        $this->actingAs($this->member());

        // gethostbynamel needs DNS for example.com; skip where the sandbox has none
        if (! gethostbynamel('example.com')) {
            $this->markTestSkipped('No DNS available.');
        }
        $this->get('/api/sites/example.com/favicon')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_site_without_a_usable_icon_answers_404_and_is_remembered(): void
    {
        Http::fake(['*' => Http::response('<html></html>', 200)]);
        $this->assertNull(Favicon::for('nothing-here.invalid'));
        $this->assertNull(Favicon::for('nothing-here.invalid'));
        Http::assertNothingSent();
    }

    public function test_addresses_on_private_networks_are_never_contacted(): void
    {
        Http::fake();
        foreach (['localhost', '127.0.0.1', '10.0.0.5', 'intranet'] as $host) {
            $this->assertNull(Favicon::for($host));
        }
        Http::assertNothingSent();
    }

    public function test_the_feature_can_be_turned_off(): void
    {
        config(['tidemark.favicons' => false]);
        Http::fake();
        $this->actingAs($this->member());
        $this->get('/api/sites/example.com/favicon')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_only_people_with_access_to_the_site_can_ask(): void
    {
        $this->member();
        $this->get('/api/sites/example.com/favicon')->assertUnauthorized();
        $this->actingAs(User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password']))->get('/api/sites/example.com/favicon')->assertForbidden();
    }

    public function test_scripts_and_vector_images_are_not_served(): void
    {
        Http::fake(function (Request $r) {
            return $r->url() === 'https://example.com/' ? Http::response('<link rel="icon" href="/i.svg">') : Http::response('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        });
        if (! gethostbynamel('example.com')) {
            $this->markTestSkipped('No DNS available.');
        }
        $this->assertNull(Favicon::for('example.com'));
    }

    public function test_an_icon_that_is_entirely_transparent_is_not_used(): void
    {
        $blank = "\x00\x00\x01\x00\x01\x00\x02\x02\x00\x00\x01\x00\x20\x00".pack('VV', 40 + 4 + 4, 22).pack('VllvvVVllVV', 40, 2, 4, 1, 32, 0, 16, 0, 0, 0, 0).str_repeat("\x00", 4 * 4);
        Http::fake(function (Request $r) use ($blank) {
            return match ($r->url()) {
                'https://example.com/' => Http::response('<link rel="icon" href="/favicon.ico">'),
                'https://example.com/favicon.ico' => Http::response($blank),
                default => Http::response('', 404),
            };
        });
        if (! gethostbynamel('example.com')) {
            $this->markTestSkipped('No DNS available.');
        }
        $this->assertNull(Favicon::for('example.com'));
    }

    public function test_a_png_is_preferred_over_the_classic_ico(): void
    {
        Http::fake(function (Request $r) {
            return match ($r->url()) {
                'https://example.com/' => Http::response('<link rel="icon" href="/favicon.ico"><link rel="apple-touch-icon" href="/touch.png">'),
                'https://example.com/touch.png' => Http::response(self::PNG),
                default => Http::response("\x00\x00\x01\x00".str_repeat('x', 40)),
            };
        });
        if (! gethostbynamel('example.com')) {
            $this->markTestSkipped('No DNS available.');
        }
        $this->assertSame('image/png', Favicon::for('example.com')['type']);
    }
}
