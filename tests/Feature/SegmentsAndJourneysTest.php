<?php

namespace Tests\Feature;

use App\Contracts\AllowsExploration;
use App\Contracts\Entitlements;
use App\Models\Segment;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SegmentsAndJourneysTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
        $this->actingAs($this->owner);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->postJson('/api/sites', ['domain' => 'example.com'])->assertCreated();
        $this->site = Site::where('domain', 'example.com')->firstOrFail();
    }

    /** One visit with events in order: [name, path] pairs, 'pageview' for pages. */
    private function visit(string $visitor, array $events, string $country = 'DE'): void
    {
        $at = now()->subHour();
        $visit = (string) Str::uuid();
        DB::table('visits')->insert([
            'id' => $visit, 'site_id' => $this->site->id, 'visitor_id' => str_pad($visitor, 32, '0'), 'started_at' => $at, 'ended_at' => $at, 'entry_page' => '/', 'exit_page' => '/',
            'channel' => 'Direct', 'country' => $country, 'pageviews' => 1, 'events' => count($events), 'is_bounce' => false, 'duration' => 10,
        ]);
        foreach ($events as $i => [$name, $path]) {
            DB::table('events')->insert(['site_id' => $this->site->id, 'visit_id' => $visit, 'visitor_id' => str_pad($visitor, 32, '0'), 'name' => $name, 'pathname' => $path, 'created_at' => $at->copy()->addSeconds($i)]);
        }
    }

    private function journeys(array $steps = [], array $q = []): array
    {
        return $this->getJson('/api/sites/example.com/stats/journeys?'.http_build_query($q + ['period' => 'day', 'steps' => json_encode($steps)]))->assertOk()->json();
    }

    private function names(array $result): array
    {
        return array_column($result['items'], 'visitors', 'value');
    }

    public function test_journeys_follow_visits_step_by_step(): void
    {
        $this->visit('a', [['pageview', '/'], ['pageview', '/pricing'], ['Signup', '/pricing']]);
        $this->visit('b', [['pageview', '/'], ['pageview', '/pricing'], ['pageview', '/pricing'], ['pageview', '/docs/install']]);
        $this->visit('c', [['pageview', '/'], ['pageview', '/docs/api']]);
        $this->visit('d', [['pageview', '/blog']]);

        $start = $this->journeys();
        $this->assertSame(4, $start['total']);
        $this->assertEquals(['/' => 3, '/blog' => 1], $this->names($start));

        $next = $this->journeys([['page', '/']]);
        $this->assertSame(3, $next['total']);
        $this->assertEquals(['/pricing' => 2, '/docs/api' => 1, '/docs' => 1], $this->names($next));
    }

    public function test_events_pages_and_folders_are_steps_and_visits_can_end(): void
    {
        $this->visit('a', [['pageview', '/'], ['pageview', '/pricing'], ['Signup', '/pricing']]);
        $this->visit('b', [['pageview', '/'], ['pageview', '/pricing']]);
        $this->visit('c', [['pageview', '/'], ['pageview', '/docs/install']]);
        $this->visit('d', [['pageview', '/'], ['pageview', '/docs/api']]);

        $second = $this->journeys([['page', '/']]);
        $byType = collect($second['items'])->mapWithKeys(fn ($i) => [$i['type'].':'.$i['value'] => $i['visitors']])->all();
        $this->assertSame(2, $byType['group:/docs']);
        $this->assertSame(2, $byType['page:/pricing'] ?? 0);
        $this->assertSame(1, $byType['page:/docs/install']);

        $third = $this->journeys([['page', '/'], ['page', '/pricing']]);
        $this->assertSame('none', $third['items'][0]['type']);
        $this->assertSame(1, $third['items'][0]['visitors']);
        $this->assertSame(['event:Signup' => 1], collect($third['items'])->where('type', 'event')->mapWithKeys(fn ($i) => ['event:'.$i['value'] => $i['visitors']])->all());

        $folder = $this->journeys([['page', '/'], ['group', '/docs']]);
        $this->assertSame(2, $folder['total']);

        $ending = $this->journeys([], ['direction' => 'end']);
        $this->assertSame(1, collect($ending['items'])->firstWhere('value', 'Signup')['visitors']);
        $before = $this->journeys([['event', 'Signup']], ['direction' => 'end']);
        $this->assertSame(1, collect($before['items'])->firstWhere('value', '/pricing')['visitors']);

        $searched = $this->journeys([['page', '/']], ['search' => 'doc']);
        $this->assertEquals(['/docs' => 2, '/docs/install' => 1, '/docs/api' => 1], $this->names($searched));
    }

    public function test_visit_filters_narrow_journeys(): void
    {
        $this->visit('a', [['pageview', '/'], ['pageview', '/de']], 'DE');
        $this->visit('b', [['pageview', '/'], ['pageview', '/fr']], 'FR');

        $res = $this->journeys([['page', '/']], ['filters' => json_encode([['is', 'country', ['FR']]])]);
        $this->assertSame(['/fr' => 1], $this->names($res));
    }

    public function test_segments_are_saved_shared_and_applied_as_filters(): void
    {
        $this->visit('a', [['pageview', '/']], 'DE');
        $this->visit('b', [['pageview', '/']], 'FR');
        $id = $this->postJson('/api/sites/example.com/segments', ['name' => 'Germany', 'type' => 'site', 'filters' => [['is', 'country', ['DE']], ['is', 'segment', ['nested']]]])
            ->assertCreated()->assertJsonPath('data.filters', [['is', 'country', ['DE']]])->json('data.id');

        $filters = json_encode([['is', 'segment', [$id]]]);
        $this->getJson('/api/sites/example.com/stats/top?'.http_build_query(['period' => 'day', 'filters' => $filters]))->assertOk()->assertJsonPath('stats.visitors.value', 1);
        $this->getJson('/api/sites/example.com/stats/top?'.http_build_query(['period' => 'day', 'filters' => json_encode([['is', 'segment', [(string) Str::uuid()]]])]))->assertUnprocessable();

        $this->patchJson("/api/sites/example.com/segments/{$id}", ['name' => 'Germans', 'filters' => [['is', 'country', ['FR']]]])->assertOk()->assertJsonPath('data.name', 'Germans');
        $this->getJson('/api/sites/example.com/stats/top?'.http_build_query(['period' => 'day', 'filters' => $filters]))->assertJsonPath('stats.visitors.value', 1);
        $this->postJson('/api/sites/example.com/segments', ['name' => 'Empty', 'type' => 'site', 'filters' => [['bogus', 'nothing', ['x']]]])->assertUnprocessable();
        $this->deleteJson("/api/sites/example.com/segments/{$id}")->assertOk();
        $this->assertSame(0, Segment::count());
    }

    public function test_personal_segments_belong_to_their_creator_and_viewers_cannot_share(): void
    {
        $viewer = User::create(['name' => 'View', 'email' => 'view@example.com', 'password' => 'password']);
        $this->postJson('/api/sites/example.com/people', ['email' => 'view@example.com', 'role' => 'viewer'])->assertCreated();
        $site = $this->postJson('/api/sites/example.com/segments', ['name' => 'Shared', 'type' => 'site', 'filters' => [['is', 'country', ['DE']]]])->assertCreated()->json('data.id');
        $mine = $this->postJson('/api/sites/example.com/segments', ['name' => 'Mine', 'type' => 'personal', 'filters' => [['is', 'country', ['FR']]]])->assertCreated()->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->actingAs($viewer);
        $names = collect($this->getJson('/api/sites/example.com/segments')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['Shared'], $names);
        $this->postJson('/api/sites/example.com/segments', ['name' => 'Sneaky', 'type' => 'site', 'filters' => [['is', 'country', ['DE']]]])->assertForbidden();
        $this->postJson('/api/sites/example.com/segments', ['name' => 'Viewer own', 'type' => 'personal', 'filters' => [['is', 'country', ['ES']]]])->assertCreated();
        $this->deleteJson("/api/sites/example.com/segments/{$site}")->assertForbidden();
        $this->deleteJson("/api/sites/example.com/segments/{$mine}")->assertNotFound();
        $this->getJson('/api/sites/example.com/stats/top?'.http_build_query(['period' => 'day', 'filters' => json_encode([['is', 'segment', [$mine]]])]))->assertUnprocessable();
    }

    public function test_an_extension_can_limit_segments_and_journeys(): void
    {
        $this->app->instance(Entitlements::class, new class extends DefaultEntitlements implements AllowsExploration
        {
            public function allowsSegments(Site $site): bool
            {
                return false;
            }

            public function allowsJourneys(Site $site): bool
            {
                return false;
            }
        });

        $this->getJson('/api/sites/example.com')->assertJsonPath('data.features.segments', false)->assertJsonPath('data.features.journeys', false);
        $this->getJson('/api/sites/example.com/segments')->assertOk()->assertJsonPath('allowed', false);
        $this->postJson('/api/sites/example.com/segments', ['name' => 'X', 'type' => 'site', 'filters' => [['is', 'country', ['DE']]]])->assertForbidden();
        $this->getJson('/api/sites/example.com/stats/journeys?period=day')->assertOk()->assertJsonPath('allowed', false);
    }
}
