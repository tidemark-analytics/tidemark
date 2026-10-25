<?php

namespace Tests\Feature;

use App\Analytics\Support\SmallGroups;
use App\Models\SharedLink;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The minimum group size: public and shared views don't list rows under it, and can't filter their way down to a tiny group. */
class SmallGroupsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    private Site $site;

    private User $owner;

    /** Berlin 12 visitors, Paris 6, Reykjavik 2, Suva 1. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
        $this->site = Site::create(['domain' => 'example.com', 'timezone' => 'UTC', 'public' => true, 'min_group_size' => 5]);
        $this->site->members()->attach($this->owner->id, ['role' => 'owner', 'id' => (string) Str::uuid()]);
        $n = 0;
        foreach ([['DE', 'Berlin', 12], ['FR', 'Paris', 6], ['IS', 'Reykjavik', 2], ['FJ', 'Suva', 1]] as [$country, $city, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $this->call('POST', '/api/event', [], [], [], $this->transformHeadersToServerVars(['Accept-Language' => 'en-US,en;q=0.9', 'User-Agent' => self::CHROME, 'Content-Type' => 'text/plain', 'CF-Connecting-IP' => '203.0.113.'.(++$n), 'CF-IPCountry' => $country, 'CF-IPCity' => $city]),
                    json_encode(['n' => 'pageview', 'u' => 'https://example.com/'.strtolower($city), 'd' => 'example.com', 'w' => 1920]))->assertStatus(202);
            }
        }
    }

    private function path(string $path, array $q = []): string
    {
        return "/api/sites/example.com/stats/{$path}?".http_build_query($q + ['period' => 'day']);
    }

    private function asOwner()
    {
        return $this->actingAs($this->owner)->withHeader('X-Requested-With', 'XMLHttpRequest');
    }

    public function test_a_public_viewer_does_not_see_rows_under_the_minimum(): void
    {
        $all = $this->asOwner()->getJson($this->path('breakdown/city', ['limit' => 200]))->assertOk()->json('results');
        $this->assertGreaterThan(0, count(array_filter($all, fn ($r) => $r['visitors'] < 5)), 'the demo data needs some small groups for this test');
        $this->assertArrayNotHasKey('hidden_below', $this->asOwner()->getJson($this->path('breakdown/city', ['limit' => 200]))->json());

        $this->app['auth']->forgetGuards();
        $public = $this->getJson($this->path('breakdown/city', ['limit' => 200]))->assertOk();

        $this->assertSame(array_values(array_filter($all, fn ($r) => $r['visitors'] >= 5)), $public->json('results'));
        $this->assertSame(5, $public->json('hidden_below'));
        $this->assertSame(count($all) - count($public->json('results')), $public->json('hidden'));
        $this->assertFalse($public->json('has_more'));
    }

    public function test_it_is_off_by_default_and_when_set_to_zero(): void
    {
        $this->site->update(['min_group_size' => 0]);
        $all = $this->asOwner()->getJson($this->path('breakdown/city', ['limit' => 200]))->json('results');

        $this->app['auth']->forgetGuards();
        $public = $this->getJson($this->path('breakdown/city', ['limit' => 200]))->assertOk();
        $this->assertSame($all, $public->json('results'));
        $this->assertArrayNotHasKey('hidden_below', $public->json());
    }

    public function test_a_shared_link_is_held_to_the_minimum_too(): void
    {
        $this->site->update(['public' => false]);
        $link = SharedLink::create(['site_id' => $this->site->id, 'slug' => 'abc123abc123', 'name' => 'Team']);
        $all = $this->asOwner()->getJson($this->path('breakdown/city', ['limit' => 200]))->json('results');

        $this->app['auth']->forgetGuards();
        $res = $this->getJson($this->path('breakdown/city', ['limit' => 200]), ['X-Share-Auth' => $link->slug])->assertOk();
        $this->assertSame(array_values(array_filter($all, fn ($r) => $r['visitors'] >= 5)), $res->json('results'));
    }

    public function test_members_of_the_site_always_see_everything(): void
    {
        $res = $this->asOwner()->getJson($this->path('breakdown/country', ['limit' => 200]))->assertOk();

        $this->assertArrayNotHasKey('hidden_below', $res->json());
    }

    public function test_a_public_viewer_cannot_filter_down_to_a_tiny_group(): void
    {
        $rows = $this->asOwner()->getJson($this->path('breakdown/city', ['limit' => 200]))->json('results');
        $tiny = collect($rows)->first(fn ($r) => $r['visitors'] < 5 && $r['name'] !== null);
        $filters = json_encode([['is', 'city', [$tiny['name']]]]);
        $this->assertGreaterThan(0, $this->asOwner()->getJson($this->path('top', ['filters' => $filters]))->json('stats.visitors.value'));

        $this->app['auth']->forgetGuards();
        $this->assertSame(0, $this->getJson($this->path('top', ['filters' => $filters]))->assertOk()->json('stats.visitors.value'));
        $this->assertSame([], $this->getJson($this->path('breakdown/page', ['filters' => $filters]))->json('results'));

        // a filter that leaves a big enough group still works
        $big = collect($this->asOwner()->getJson($this->path('breakdown/country', ['limit' => 200]))->json('results'))->first(fn ($r) => $r['visitors'] >= 5);
        $this->app['auth']->forgetGuards();
        $this->assertGreaterThanOrEqual(5, $this->getJson($this->path('top', ['filters' => json_encode([['is', 'country', [$big['name']]]])]))->json('stats.visitors.value'));
    }

    public function test_the_csv_export_leaves_small_rows_out_for_a_public_viewer(): void
    {
        $this->app['auth']->forgetGuards();
        $res = $this->get($this->path('export'))->assertOk();
        $this->assertStringContainsString('zip', (string) $res->headers->get('Content-Type'));

        $tmp = tempnam(sys_get_temp_dir(), 'sg');
        file_put_contents($tmp, $res->streamedContent());
        $zip = new \ZipArchive;
        $zip->open($tmp);
        $rows = array_map('str_getcsv', array_filter(explode("\n", (string) $zip->getFromName('cities.csv'))));
        $zip->close();
        unlink($tmp);
        foreach (array_slice($rows, 1) as $row) {
            $this->assertGreaterThanOrEqual(5, (int) $row[1]);
        }
    }

    public function test_small_rows_are_trimmed_and_the_rest_marked(): void
    {
        $out = SmallGroups::trim(['results' => [['name' => 'a', 'visitors' => 9], ['name' => 'b', 'visitors' => 5], ['name' => 'c', 'visitors' => 2]], 'has_more' => true], 5);
        $this->assertSame(['a', 'b'], array_column($out['results'], 'name'));
        $this->assertSame([5, 1, false], [$out['hidden_below'], $out['hidden'], $out['has_more']]);

        $same = SmallGroups::trim(['results' => [['name' => 'a', 'visitors' => 9]], 'has_more' => true], 5);
        $this->assertTrue($same['has_more']);
        $this->assertArrayNotHasKey('hidden', $same);

        $this->assertSame(['results' => [['visitors' => 1]]], SmallGroups::trim(['results' => [['visitors' => 1]]], 0));
    }

    public function test_the_minimum_is_set_through_the_settings_api(): void
    {
        $this->asOwner();
        $this->patchJson('/api/sites/example.com', ['min_group_size' => 10])->assertOk()->assertJsonPath('data.min_group_size', 10);
        $this->patchJson('/api/sites/example.com', ['min_group_size' => 0])->assertOk()->assertJsonPath('data.min_group_size', 0);
        $this->patchJson('/api/sites/example.com', ['min_group_size' => 1])->assertUnprocessable();
        $this->patchJson('/api/sites/example.com', ['min_group_size' => 5000])->assertUnprocessable();
        $this->patchJson('/api/sites/example.com', ['min_group_size' => 'lots'])->assertUnprocessable();
    }
}
