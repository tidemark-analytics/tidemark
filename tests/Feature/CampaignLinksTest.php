<?php

namespace Tests\Feature;

use App\Contracts\AllowsCampaignLinks;
use App\Contracts\Entitlements;
use App\Models\CampaignLink;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Campaign links and tracking pixels: managing them, forwarding with UTMs, counting, and what a plan may do. */
class CampaignLinksTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    private User $owner;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->owner = User::create(['name' => 'O', 'email' => 'o@example.com', 'password' => 'password']);
        $this->site = Site::create(['domain' => 'example.com']);
        $this->site->members()->attach($this->owner->id, ['role' => 'owner', 'id' => (string) Str::uuid()]);
    }

    private function link(array $attrs = []): CampaignLink
    {
        return CampaignLink::create($attrs + ['site_id' => $this->site->id, 'slug' => CampaignLink::newSlug(), 'kind' => 'link', 'name' => 'Launch email',
            'destination' => 'https://example.com/pricing?plan=pro', 'utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'launch']);
    }

    private function browse(string $uri)
    {
        return $this->call('GET', $uri, [], [], [], $this->transformHeadersToServerVars(['User-Agent' => self::CHROME, 'Accept-Language' => 'en-US,en;q=0.9']));
    }

    public function test_an_owner_creates_lists_edits_and_deletes_links_and_pixels(): void
    {
        $base = '/api/sites/example.com/campaign-links';
        $made = $this->actingAs($this->owner)->postJson($base, ['kind' => 'link', 'name' => 'Launch', 'destination' => 'https://example.com/pricing', 'utm_source' => 'newsletter', 'utm_campaign' => 'launch'])
            ->assertCreated()->assertJsonPath('data.kind', 'link');
        $this->assertStringContainsString('/l/', $made->json('data.url'));
        $pixel = $this->actingAs($this->owner)->postJson($base, ['kind' => 'pixel', 'name' => 'Opens', 'utm_campaign' => 'launch'])->assertCreated();
        $this->assertStringEndsWith('.gif', $pixel->json('data.url'));

        $this->actingAs($this->owner)->getJson($base)->assertOk()->assertJsonPath('allowed', true)->assertJsonCount(2, 'data');
        $this->actingAs($this->owner)->patchJson($base.'/'.$made->json('data.id'), ['name' => 'Launch v2', 'destination' => 'https://example.com/pricing', 'utm_medium' => 'email'])->assertOk()->assertJsonPath('data.name', 'Launch v2');
        $this->actingAs($this->owner)->deleteJson($base.'/'.$made->json('data.id'))->assertOk();
        $this->assertSame(1, CampaignLink::count());
    }

    public function test_a_link_must_point_at_the_site_and_a_pixel_needs_a_campaign(): void
    {
        $base = '/api/sites/example.com/campaign-links';
        $this->actingAs($this->owner)->postJson($base, ['kind' => 'link', 'name' => 'Bad', 'destination' => 'https://evil.example.org/'])->assertUnprocessable()->assertJsonValidationErrors('destination');
        $this->actingAs($this->owner)->postJson($base, ['kind' => 'link', 'name' => 'Bad', 'destination' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->actingAs($this->owner)->postJson($base, ['kind' => 'link', 'name' => 'Sub', 'destination' => 'https://blog.example.com/post'])->assertCreated();
        $this->actingAs($this->owner)->postJson($base, ['kind' => 'pixel', 'name' => 'No campaign'])->assertUnprocessable()->assertJsonValidationErrors('utm_campaign');
    }

    public function test_only_owners_and_admins_manage_links_and_only_their_own_sites(): void
    {
        $viewer = User::create(['name' => 'V', 'email' => 'v@example.com', 'password' => 'password']);
        $this->site->members()->attach($viewer->id, ['role' => 'viewer', 'id' => (string) Str::uuid()]);
        $this->actingAs($viewer)->getJson('/api/sites/example.com/campaign-links')->assertForbidden();
        $this->actingAs($this->owner)->postJson('/api/sites/other.com/campaign-links', [])->assertNotFound();
    }

    public function test_a_link_forwards_with_its_utm_values_keeps_the_address_query_and_counts_the_click(): void
    {
        $link = $this->link();

        $r = $this->browse('/l/'.$link->slug)->assertRedirect();
        $location = $r->headers->get('Location');
        $this->assertStringStartsWith('https://example.com/pricing?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $this->assertSame(['plan' => 'pro', 'utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'launch'], $q);
        $this->assertSame(1, (int) DB::table('campaign_link_counts')->where('link_id', $link->id)->value('hits'));
        $this->assertSame(0, DB::table('visits')->count(), 'the visit is recorded by the destination page, which reads the UTM values from the address');
        $this->actingAs($this->owner)->getJson('/api/sites/example.com/campaign-links')->assertJsonPath('data.0.total', 1)->assertJsonPath('data.0.last_30_days', 1);
    }

    public function test_a_pixel_answers_with_a_transparent_gif_and_counts_a_view_without_recording_a_visit(): void
    {
        $pixel = $this->link(['kind' => 'pixel', 'destination' => null, 'name' => 'Opens']);

        $r = $this->browse('/p/'.$pixel->slug.'.gif')->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertStringStartsWith('GIF89a', $r->getContent());
        $this->assertSame(1, (int) DB::table('campaign_link_counts')->where('link_id', $pixel->id)->value('hits'));
        $this->assertSame(0, DB::table('visits')->count(), 'opens are counted as totals, not as visitors');
    }

    public function test_unknown_links_and_the_wrong_kind_are_not_found(): void
    {
        $pixel = $this->link(['kind' => 'pixel', 'destination' => null]);
        $this->browse('/l/nothere1')->assertNotFound();
        $this->browse('/l/'.$pixel->slug)->assertNotFound();
        $this->browse('/p/nothere1.gif')->assertNotFound();
    }

    public function test_a_plan_without_campaign_links_cannot_create_them_but_existing_ones_keep_forwarding_uncounted(): void
    {
        $link = $this->link();
        $this->app->instance(Entitlements::class, new class extends DefaultEntitlements implements AllowsCampaignLinks
        {
            public function allowsCampaignLinks(Site $site): bool
            {
                return false;
            }
        });

        $this->actingAs($this->owner)->getJson('/api/sites/example.com/campaign-links')->assertOk()->assertJsonPath('allowed', false)->assertJsonCount(1, 'data');
        $this->actingAs($this->owner)->postJson('/api/sites/example.com/campaign-links', ['kind' => 'link', 'name' => 'N', 'destination' => 'https://example.com/'])->assertForbidden();
        $this->actingAs($this->owner)->patchJson('/api/sites/example.com/campaign-links/'.$link->id, ['name' => 'N', 'destination' => 'https://example.com/'])->assertForbidden();
        $this->browse('/l/'.$link->slug)->assertRedirect();
        $this->assertSame(0, DB::table('campaign_link_counts')->count());
        $this->assertSame(0, DB::table('visits')->count());
        $this->actingAs($this->owner)->deleteJson('/api/sites/example.com/campaign-links/'.$link->id)->assertOk();
    }
}
