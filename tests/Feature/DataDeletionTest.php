<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
    }

    private function siteFor(User $user, string $role = 'owner', string $domain = 'a.com'): Site
    {
        $site = Site::create(['domain' => $domain]);
        $site->members()->attach($user->id, ['role' => $role, 'id' => (string) Str::uuid()]);

        return $site;
    }

    private function user(string $email = 'o@example.com'): User
    {
        return User::create(['name' => 'Olive Owner', 'email' => $email, 'password' => 'password']);
    }

    /** A visit with one pageview per path, and optional properties on the first event. */
    private function visit(Site $site, array $paths, string $at = '2026-03-10 10:00:00', string $country = 'US', array $props = []): string
    {
        $id = (string) Str::uuid();
        DB::table('visits')->insert(['id' => $id, 'site_id' => $site->id, 'visitor_id' => str_repeat('a', 32), 'started_at' => $at, 'ended_at' => $at,
            'entry_page' => $paths[0], 'exit_page' => end($paths), 'country' => $country, 'pageviews' => count($paths), 'events' => count($paths)]);
        foreach ($paths as $i => $path) {
            $eventId = DB::table('events')->insertGetId(['site_id' => $site->id, 'visit_id' => $id, 'visitor_id' => str_repeat('a', 32), 'name' => 'pageview', 'pathname' => $path, 'hostname' => 'a.com', 'created_at' => $at]);
            if ($i === 0) {
                foreach ($props as $k => $v) {
                    DB::table('event_props')->insert(['site_id' => $site->id, 'event_id' => $eventId, 'key' => $k, 'value' => $v]);
                }
            }
        }

        return $id;
    }

    public function test_preview_counts_whole_visits_that_match_a_page_or_a_visit_property(): void
    {
        $user = $this->user();
        $site = $this->siteFor($user);
        $this->visit($site, ['/', '/secret']);
        $this->visit($site, ['/', '/blog']);
        $this->visit($site, ['/pricing'], country: 'DE');

        $page = [['is', 'page', ['/secret']]];
        $this->actingAs($user)->postJson("/api/sites/{$site->domain}/data/preview", ['filters' => $page])->assertOk()->assertJson(['visits' => 1, 'events' => 2]);
        $this->actingAs($user)->postJson("/api/sites/{$site->domain}/data/preview", ['filters' => [['is', 'country', ['DE']]]])->assertOk()->assertJson(['visits' => 1, 'events' => 1]);
        $this->assertSame(3, DB::table('visits')->count(), 'a preview deletes nothing');
    }

    public function test_it_matches_an_event_property_key_and_value(): void
    {
        $user = $this->user();
        $site = $this->siteFor($user);
        $this->visit($site, ['/'], props: ['email' => 'leak@example.com']);
        $this->visit($site, ['/'], props: ['email' => 'fine@example.com']);

        $this->actingAs($user)->postJson("/api/sites/{$site->domain}/data/preview", ['filters' => [['is', 'prop:email', ['leak@example.com']]]])->assertOk()->assertJson(['visits' => 1, 'events' => 1]);
    }

    public function test_it_deletes_matching_visits_with_their_events_and_properties_and_logs_who_did_it(): void
    {
        $user = $this->user();
        $site = $this->siteFor($user);
        $this->visit($site, ['/', '/secret'], props: ['email' => 'x@example.com']);
        $keep = $this->visit($site, ['/', '/blog']);
        $filters = [['is', 'page', ['/secret']]];

        $this->actingAs($user)->postJson("/api/sites/{$site->domain}/data/delete", ['filters' => $filters, 'confirm_visits' => 1])->assertOk()->assertJson(['visits' => 1, 'events' => 2]);

        $this->assertSame([$keep], DB::table('visits')->pluck('id')->all());
        $this->assertSame(2, DB::table('events')->count());
        $this->assertSame(0, DB::table('event_props')->count());
        $this->actingAs($user)->getJson("/api/sites/{$site->domain}/data/deletions")->assertOk()
            ->assertJsonPath('data.0.by', 'Olive Owner')->assertJsonPath('data.0.visits', 1)->assertJsonPath('data.0.events', 2)->assertJsonPath('data.0.filters.0.1', 'page');
    }

    public function test_a_date_range_is_read_in_the_sites_timezone(): void
    {
        $user = $this->user();
        $site = $this->siteFor($user);
        $site->update(['timezone' => 'America/New_York']);
        $this->visit($site, ['/'], '2026-03-10 02:00:00'); // 9 March 21:00 in New York
        $this->visit($site, ['/'], '2026-03-10 18:00:00'); // 10 March 14:00 in New York

        $this->actingAs($user)->postJson("/api/sites/{$site->domain}/data/preview", ['from' => '2026-03-10', 'to' => '2026-03-10'])->assertOk()->assertJson(['visits' => 1]);
    }

    public function test_it_needs_a_condition_and_a_matching_confirmation(): void
    {
        $user = $this->user();
        $site = $this->siteFor($user);
        $this->visit($site, ['/secret']);

        $this->actingAs($user)->postJson("/api/sites/{$site->domain}/data/preview", [])->assertStatus(422);
        $this->actingAs($user)->postJson("/api/sites/{$site->domain}/data/delete", ['confirm_visits' => 1])->assertStatus(422);
        $this->actingAs($user)->postJson("/api/sites/{$site->domain}/data/delete", ['filters' => [['is', 'page', ['/secret']]], 'confirm_visits' => 5])->assertStatus(422);
        $this->assertSame(1, DB::table('visits')->count());
    }

    public function test_only_owners_and_admins_can_use_it_and_only_for_their_own_site(): void
    {
        $owner = $this->user();
        $site = $this->siteFor($owner);
        $viewer = $this->user('v@example.com');
        $site->members()->attach($viewer->id, ['role' => 'viewer', 'id' => (string) Str::uuid()]);
        $admin = $this->user('a@example.com');
        $site->members()->attach($admin->id, ['role' => 'admin', 'id' => (string) Str::uuid()]);
        $this->visit($site, ['/secret']);
        $body = ['filters' => [['is', 'page', ['/secret']]]];

        $this->actingAs($viewer)->postJson("/api/sites/{$site->domain}/data/preview", $body)->assertForbidden();
        $this->actingAs($admin)->postJson("/api/sites/{$site->domain}/data/preview", $body)->assertOk()->assertJson(['visits' => 1]);

        $other = $this->siteFor($this->user('x@example.com'), 'owner', 'b.com');
        $this->visit($other, ['/secret']);
        $this->actingAs($owner)->postJson("/api/sites/{$other->domain}/data/delete", $body + ['confirm_visits' => 1])->assertStatus(403);
        $this->assertSame(1, DB::table('visits')->where('site_id', $other->id)->count());
    }

    public function test_it_never_touches_another_sites_data(): void
    {
        $user = $this->user();
        $one = $this->siteFor($user);
        $two = $this->siteFor($user, 'owner', 'b.com');
        $this->visit($one, ['/secret']);
        $this->visit($two, ['/secret']);

        $this->actingAs($user)->postJson("/api/sites/{$one->domain}/data/delete", ['filters' => [['is', 'page', ['/secret']]], 'confirm_visits' => 1])->assertOk();

        $this->assertSame(0, DB::table('visits')->where('site_id', $one->id)->count());
        $this->assertSame(1, DB::table('visits')->where('site_id', $two->id)->count());
    }
}
