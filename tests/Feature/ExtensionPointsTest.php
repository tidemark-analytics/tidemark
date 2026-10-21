<?php

namespace Tests\Feature;

use App\Analytics\Ingest\Collector;
use App\Contracts\Entitlements;
use App\Events\SiteCreated;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExtensionPointsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    private function hit(string $domain = 'example.com')
    {
        return $this->call('POST', '/api/event', [], [], [], $this->transformHeadersToServerVars(['User-Agent' => self::CHROME, 'Content-Type' => 'text/plain']),
            json_encode(['n' => 'pageview', 'u' => "https://{$domain}/", 'd' => $domain, 'w' => 1200]));
    }

    public function test_an_extension_can_stop_events_from_being_recorded_without_the_tracker_seeing_an_error(): void
    {
        $owner = User::create(['name' => 'O', 'email' => 'o@example.com', 'password' => 'password']);
        $site = Site::create(['domain' => 'example.com']);
        $site->members()->attach($owner->id, ['role' => 'owner', 'id' => (string) Str::uuid()]);

        $this->hit()->assertStatus(202);
        $this->assertSame(1, DB::table('events')->count());

        $seen = [];
        $this->app->instance(Entitlements::class, new class($seen) implements Entitlements
        {
            public function __construct(public array &$seen) {}

            public function canCreateSite(User $user): true|string
            {
                return true;
            }

            public function allowsRecording(array $site): bool
            {
                $this->seen[] = $site;

                return false;
            }
        });
        Cache::flush();
        $this->app->forgetInstance(Collector::class);

        $this->hit()->assertStatus(202)->assertSee('ok');
        $this->assertSame(1, DB::table('events')->count()); // nothing new stored
        $this->assertSame([$site->id, $owner->id], [$seen[0]['id'], $seen[0]['owner_id']]);
    }

    public function test_an_extension_can_refuse_new_sites_and_hears_about_created_ones(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@example.com', 'password' => 'password']);
        $this->actingAs($user)->withHeader('X-Requested-With', 'XMLHttpRequest');

        Event::fake([SiteCreated::class]);
        $this->postJson('/api/sites', ['domain' => 'one.example.org'])->assertCreated();
        Event::assertDispatched(SiteCreated::class, fn ($e) => $e->site->domain === 'one.example.org' && $e->owner->is($user));

        $this->app->instance(Entitlements::class, new class implements Entitlements
        {
            public function canCreateSite(User $user): true|string
            {
                return 'Upgrade to add more sites.';
            }

            public function allowsRecording(array $site): bool
            {
                return true;
            }
        });
        $this->postJson('/api/sites', ['domain' => 'two.example.org'])->assertUnprocessable()->assertJsonPath('errors.domain.0', 'Upgrade to add more sites.');
    }

    public function test_the_default_entitlements_still_honor_the_site_cap_from_the_environment(): void
    {
        config(['tidemark.limits.sites_per_user' => 1]);
        $user = User::create(['name' => 'U', 'email' => 'u2@example.com', 'password' => 'password']);
        $this->actingAs($user)->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->postJson('/api/sites', ['domain' => 'a.example.org'])->assertCreated();
        $this->postJson('/api/sites', ['domain' => 'b.example.org'])->assertUnprocessable();
    }

    public function test_extension_modules_are_listed_for_the_dashboard(): void
    {
        config(['tidemark.extensions' => ['/vendor/cloud/extension.js']]);
        file_put_contents(storage_path('app/installed'), '1');
        $this->get('/login')->assertOk()->assertSee('\\/vendor\\/cloud\\/extension.js', false);
    }
}
