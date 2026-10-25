<?php

namespace Tests\Feature;

use App\Analytics\Support\UrlParams;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The per-site controls that let an owner collect less: GPC and Do Not Track, coarser locations, URL parameters, referrer paths. */
class PrivacyControlsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    private function site(array $attrs = []): Site
    {
        return Site::create($attrs + ['domain' => 'example.com']);
    }

    private function hit(array $payload = [], array $headers = [])
    {
        return $this->call('POST', '/api/event', [], [], [], $this->transformHeadersToServerVars($headers + ['Accept-Language' => 'en-US,en;q=0.9', 'User-Agent' => self::CHROME, 'Content-Type' => 'text/plain']),
            json_encode($payload + ['n' => 'pageview', 'u' => 'https://example.com/', 'd' => 'example.com', 'w' => 1920]));
    }

    public function test_nothing_changes_until_the_owner_turns_a_control_on(): void
    {
        $this->site();
        $this->hit(['u' => 'https://example.com/shop?page=2&email=a@b.co', 'r' => 'https://news.example.org/story/42'], ['Sec-GPC' => '1', 'DNT' => '1', 'CF-IPCountry' => 'DE', 'CF-IPCity' => 'Berlin', 'CF-Region-Code' => 'BE'])->assertStatus(202);

        $this->assertSame('/shop', DB::table('events')->value('pathname'));
        $this->assertSame('Berlin', DB::table('visits')->value('city'));
        $this->assertSame('news.example.org/story/42', DB::table('visits')->value('referrer'));
        $this->assertSame(1, DB::table('events')->count());
    }

    public function test_global_privacy_control_is_honored_when_enabled(): void
    {
        $this->site(['honor_gpc' => true]);

        $this->hit([], ['Sec-GPC' => '1'])->assertStatus(202);
        $this->assertSame(0, DB::table('events')->count());

        $this->hit([], ['Sec-GPC' => '0'])->assertStatus(202);
        $this->hit()->assertStatus(202);
        $this->assertSame(2, DB::table('events')->count());
    }

    public function test_do_not_track_is_honored_only_when_enabled_for_it(): void
    {
        $this->site(['honor_dnt' => true]);

        $this->hit([], ['DNT' => '1'])->assertStatus(202);
        $this->assertSame(0, DB::table('events')->count());

        // GPC is a separate switch, still off here
        $this->hit([], ['Sec-GPC' => '1'])->assertStatus(202);
        $this->assertSame(1, DB::table('events')->count());
    }

    public function test_locations_can_be_cut_to_country_or_region(): void
    {
        $this->site(['location_precision' => 'region']);
        $this->hit([], ['CF-IPCountry' => 'DE', 'CF-IPCity' => 'Berlin', 'CF-Region-Code' => 'BE']);
        $v = DB::table('visits')->first();
        $this->assertSame(['DE', 'DE-BE', null], [$v->country, $v->region, $v->city]);

        DB::table('events')->delete();
        DB::table('visits')->delete();
        Site::query()->update(['location_precision' => 'country']);
        Cache::flush();
        $this->hit([], ['CF-IPCountry' => 'DE', 'CF-IPCity' => 'Berlin', 'CF-Region-Code' => 'BE']);
        $v = DB::table('visits')->first();
        $this->assertSame(['DE', null, null], [$v->country, $v->region, $v->city]);
    }

    public function test_only_allow_listed_parameters_are_kept_and_sorted(): void
    {
        $this->site(['url_params' => 'page,lang']);

        $this->hit(['u' => 'https://example.com/shop?lang=de&utm_source=news&email=a@b.co&page=2&other=1']);
        $this->assertSame('/shop?lang=de&page=2', DB::table('events')->value('pathname'));
        // campaign parameters still classify the visit, they just are not stored with the page
        $this->assertSame('news', strtolower(DB::table('visits')->value('source')));
        $this->assertSame('/shop?lang=de&page=2', DB::table('visits')->value('entry_page'));
    }

    public function test_the_same_page_reports_as_one_row_whatever_the_parameter_order(): void
    {
        $this->site(['url_params' => 'page,lang']);

        $this->hit(['u' => 'https://example.com/shop?page=2&lang=de']);
        $this->hit(['u' => 'https://example.com/shop?lang=de&page=2']);

        $this->assertSame(1, DB::table('events')->distinct()->count('pathname'));
    }

    public function test_pages_without_allowed_parameters_stay_clean_and_hash_mode_keeps_its_fragment(): void
    {
        $this->site(['url_params' => 'page']);

        $this->hit(['u' => 'https://example.com/about?utm_source=x']);
        $this->hit(['u' => 'https://example.com/app?page=3#/inbox', 'h' => 1]);

        $this->assertSame(['/about', '/app?page=3#/inbox'], DB::table('events')->orderBy('id')->pluck('pathname')->all());
    }

    public function test_the_referrer_can_be_cut_to_its_domain(): void
    {
        $this->site(['referrer_domain_only' => true]);
        $this->hit(['r' => 'https://www.news.example.org/story/42?id=7']);

        $this->assertSame('news.example.org', DB::table('visits')->value('referrer'));
    }

    public function test_the_parameter_list_is_parsed_and_checked(): void
    {
        $this->assertSame(['page', 'lang', 'a.b'], UrlParams::parse("page, lang\npage a.b"));
        $this->assertSame('page,lang', UrlParams::normalize(" page,\nlang  page "));
        $this->assertNull(UrlParams::normalize('  '));
        $this->assertNull(UrlParams::problem('page, lang, utm_term'));
        $this->assertStringContainsString('valid parameter name', UrlParams::problem('bad name!'));
        $this->assertStringContainsString('personal data', UrlParams::problem('email'));
        $this->assertStringContainsString('personal data', UrlParams::problem('user_token'));
        $this->assertStringContainsString('at most', UrlParams::problem(implode(',', array_map(fn ($i) => 'p'.$i, range(1, 21)))));
    }

    public function test_owners_change_the_controls_through_the_settings_api(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
        $this->actingAs($owner)->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->postJson('/api/sites', ['domain' => 'example.com', 'timezone' => 'UTC'])->assertCreated()
            ->assertJsonPath('data.honor_gpc', false)->assertJsonPath('data.location_precision', 'city')->assertJsonPath('data.url_params', null);

        $this->patchJson('/api/sites/example.com', ['honor_gpc' => true, 'honor_dnt' => true, 'referrer_domain_only' => true, 'location_precision' => 'country', 'url_params' => " page \n lang,page"])
            ->assertOk()->assertJsonPath('data.honor_gpc', true)->assertJsonPath('data.honor_dnt', true)->assertJsonPath('data.referrer_domain_only', true)
            ->assertJsonPath('data.location_precision', 'country')->assertJsonPath('data.url_params', 'page,lang');

        $this->patchJson('/api/sites/example.com', ['location_precision' => 'street'])->assertUnprocessable();
        $this->patchJson('/api/sites/example.com', ['url_params' => 'email'])->assertUnprocessable()->assertJsonValidationErrors('url_params');
        $this->patchJson('/api/sites/example.com', ['url_params' => null])->assertOk()->assertJsonPath('data.url_params', null);
    }

    public function test_a_settings_change_reaches_collection_straight_away(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
        $this->actingAs($owner)->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->postJson('/api/sites', ['domain' => 'example.com', 'timezone' => 'UTC'])->assertCreated();

        $this->hit([], ['Sec-GPC' => '1']);
        $this->assertSame(1, DB::table('events')->count());

        $this->patchJson('/api/sites/example.com', ['honor_gpc' => true])->assertOk();
        $this->hit([], ['Sec-GPC' => '1']);
        $this->assertSame(1, DB::table('events')->count());
    }
}
