<?php

namespace Tests\Feature;

use App\Contracts\Entitlements;
use App\Contracts\LimitsImportedHistory;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
    }

    private function siteFor(User $user, string $domain, string $role = 'owner'): Site
    {
        $site = Site::create(['domain' => $domain]);
        $site->members()->attach($user->id, ['role' => $role, 'id' => (string) Str::uuid()]);

        return $site;
    }

    private function user(string $email = 'o@example.com'): User
    {
        return User::create(['name' => 'O', 'email' => $email, 'password' => 'password']);
    }

    private function visit(string $id, string $at = '2026-03-01 10:00:00'): array
    {
        return ['visit' => ['id' => $id, 'visitor_id' => str_repeat('a', 32), 'started_at' => $at, 'ended_at' => $at, 'entry_page' => '/', 'exit_page' => '/', 'country' => 'us', 'pageviews' => 1, 'events' => 2, 'is_bounce' => true, 'duration' => 0],
            'events' => [['name' => 'pageview', 'pathname' => '/', 'hostname' => 'a.com', 'created_at' => $at, 'props' => new \stdClass], ['name' => 'Signup', 'pathname' => '/', 'created_at' => $at, 'props' => ['plan' => 'pro']]]];
    }

    private function header(): array
    {
        return ['format' => 'tidemark-export', 'version' => 1, 'domain' => 'old.example.com', 'goals' => [['type' => 'event', 'value' => 'Signup']]];
    }

    public function test_an_export_can_be_imported_into_another_site_and_stats_match(): void
    {
        $user = $this->user();
        $from = $this->siteFor($user, 'old.example.com');
        $to = $this->siteFor($user, 'new.example.com');
        $this->actingAs($user)->postJson('/api/sites/old.example.com/import', ['header' => $this->header(), 'visits' => [$this->visit((string) Str::uuid())]])->assertOk();

        $export = $this->actingAs($user)->get('/api/sites/old.example.com/export/data');
        $export->assertOk();
        $body = $export->streamedContent();
        $body = function_exists('gzdecode') && str_starts_with($body, "\x1f\x8b") ? gzdecode($body) : $body;
        $lines = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", $body)));
        $this->assertSame('tidemark-export', $lines[0]['format']);
        $this->assertCount(2, $lines);
        $signup = collect($lines[1]['events'])->firstWhere('name', 'Signup');
        $this->assertSame(['plan' => 'pro'], $signup['props']);
        $this->assertArrayNotHasKey('site_id', $lines[1]['visit']);

        $r = $this->actingAs($user)->postJson('/api/sites/new.example.com/import', ['header' => $lines[0], 'visits' => [$lines[1]]])->assertOk();
        $this->assertSame(1, $r->json('imported'));
        $this->assertSame(2, DB::table('events')->where('site_id', $to->id)->count());
        $this->assertSame('pro', DB::table('event_props')->where('site_id', $to->id)->value('value'));
        $this->assertSame('US', DB::table('visits')->where('site_id', $to->id)->value('country'));
        $this->assertTrue($to->goals()->where('value', 'Signup')->exists());
        $this->assertSame(1, DB::table('visits')->where('site_id', $from->id)->count());
    }

    public function test_importing_the_same_file_twice_does_not_duplicate_visits(): void
    {
        $user = $this->user();
        $site = $this->siteFor($user, 'a.com');
        $payload = ['header' => $this->header(), 'visits' => [$this->visit((string) Str::uuid())]];
        $this->actingAs($user)->postJson('/api/sites/a.com/import', $payload)->assertOk();
        $r = $this->actingAs($user)->postJson('/api/sites/a.com/import', $payload)->assertOk();

        $this->assertSame(1, $r->json('skipped'));
        $this->assertSame(1, DB::table('visits')->where('site_id', $site->id)->count());
        $this->assertSame(2, DB::table('events')->where('site_id', $site->id)->count());
    }

    public function test_an_import_never_overwrites_or_shares_rows_with_another_site(): void
    {
        $a = $this->user('a@example.com');
        $b = $this->user('b@example.com');
        $siteA = $this->siteFor($a, 'a.com');
        $siteB = $this->siteFor($b, 'b.com');
        $id = (string) Str::uuid();
        $this->actingAs($a)->postJson('/api/sites/a.com/import', ['visits' => [$this->visit($id)]])->assertOk();

        $r = $this->actingAs($b)->postJson('/api/sites/b.com/import', ['visits' => [$this->visit($id)]])->assertOk();
        $this->assertSame(0, $r->json('skipped'));
        $this->assertSame(1, DB::table('visits')->where('site_id', $siteB->id)->count());
        $this->assertSame(1, DB::table('visits')->where('site_id', $siteA->id)->count());
        $this->assertSame($id, DB::table('visits')->where('site_id', $siteA->id)->value('id'));
        $this->assertSame(0, DB::table('events')->where('site_id', $siteB->id)->whereNotIn('visit_id', DB::table('visits')->where('site_id', $siteB->id)->pluck('id'))->count());
        $this->actingAs($b)->postJson('/api/sites/a.com/import', ['visits' => []])->assertStatus(403);
    }

    public function test_bad_rows_are_counted_and_the_header_must_be_a_tidemark_export(): void
    {
        $user = $this->user();
        $this->siteFor($user, 'a.com');
        $bad = ['visit' => ['visitor_id' => 'short', 'started_at' => 'nope', 'ended_at' => 'nope']];
        $r = $this->actingAs($user)->postJson('/api/sites/a.com/import', ['visits' => [$bad, $this->visit((string) Str::uuid(), '2999-01-01 00:00:00')]])->assertOk();
        $this->assertSame(2, $r->json('invalid'));
        $this->assertSame(0, DB::table('visits')->count());
        $this->actingAs($user)->postJson('/api/sites/a.com/import', ['header' => ['format' => 'plausible'], 'visits' => []])->assertStatus(422);
    }

    public function test_an_extension_can_drop_history_older_than_its_plan_keeps(): void
    {
        $user = $this->user();
        $site = $this->siteFor($user, 'a.com');
        $this->app->instance(Entitlements::class, new class($this->app->make(Entitlements::class)) implements Entitlements, LimitsImportedHistory
        {
            public function __construct(private Entitlements $inner) {}

            public function canCreateSite(User $user): true|string
            {
                return $this->inner->canCreateSite($user);
            }

            public function allowsRecording(array $site): bool
            {
                return true;
            }

            public function importCutoff(Site $site): ?\DateTimeInterface
            {
                return new \DateTimeImmutable('2026-01-01');
            }
        });

        $r = $this->actingAs($user)->postJson('/api/sites/a.com/import', ['visits' => [$this->visit((string) Str::uuid(), '2025-06-01 10:00:00'), $this->visit((string) Str::uuid(), '2026-06-01 10:00:00')]])->assertOk();
        $this->assertSame(1, $r->json('outdated'));
        $this->assertSame(1, $r->json('imported'));
        $this->assertSame(1, DB::table('visits')->where('site_id', $site->id)->count());
    }

    public function test_viewers_cannot_export_or_import(): void
    {
        $owner = $this->user();
        $viewer = $this->user('v@example.com');
        $site = $this->siteFor($owner, 'a.com');
        $site->members()->attach($viewer->id, ['role' => 'viewer', 'id' => (string) Str::uuid()]);

        $this->actingAs($viewer)->get('/api/sites/a.com/export/data')->assertStatus(403);
        $this->actingAs($viewer)->postJson('/api/sites/a.com/import', ['visits' => []])->assertStatus(403);
    }
}
