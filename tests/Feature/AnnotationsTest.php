<?php

namespace Tests\Feature;

use App\Models\Annotation;
use App\Models\SharedLink;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Notes on the graph: who can write which kind, who sees what, and where they show up. */
class AnnotationsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $owner;

    private User $admin;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create(['domain' => 'example.com', 'timezone' => 'UTC', 'public' => true]);
        foreach (['owner' => 'owner', 'admin' => 'admin', 'viewer' => 'viewer'] as $name => $role) {
            $this->{$name} = User::create(['name' => ucfirst($name), 'email' => "{$name}@example.com", 'password' => 'password']);
            $this->site->members()->attach($this->{$name}->id, ['role' => $role, 'id' => (string) Str::uuid()]);
        }
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withHeader('X-Requested-With', 'XMLHttpRequest');
    }

    private function note(string $date, string $note = 'Launched v2', string $visibility = 'team', ?User $by = null): Annotation
    {
        return Annotation::create(['site_id' => $this->site->id, 'user_id' => ($by ?? $this->owner)->id, 'date' => $date, 'note' => $note, 'visibility' => $visibility]);
    }

    private function yesterday(): string
    {
        return now('UTC')->subDay()->toDateString();
    }

    public function test_owners_and_admins_share_notes_and_viewers_keep_private_ones(): void
    {
        $day = $this->yesterday();
        foreach ([$this->owner, $this->admin] as $u) {
            foreach (['private', 'team', 'public'] as $v) {
                $this->as($u)->postJson('/api/sites/example.com/annotations', ['date' => $day, 'note' => "{$u->name} {$v}", 'visibility' => $v])->assertCreated()->assertJsonPath('data.visibility', $v)->assertJsonPath('data.editable', true);
            }
        }
        $this->as($this->viewer)->postJson('/api/sites/example.com/annotations', ['date' => $day, 'note' => 'Mine', 'visibility' => 'private'])->assertCreated();
        $this->as($this->viewer)->postJson('/api/sites/example.com/annotations', ['date' => $day, 'note' => 'For everyone', 'visibility' => 'team'])->assertForbidden();
        $this->as($this->viewer)->postJson('/api/sites/example.com/annotations', ['date' => $day, 'note' => 'For everyone', 'visibility' => 'public'])->assertForbidden();
    }

    public function test_signed_out_visitors_cannot_write_notes(): void
    {
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->postJson('/api/sites/example.com/annotations', ['date' => $this->yesterday(), 'note' => 'x', 'visibility' => 'public'])->assertUnauthorized();
    }

    public function test_each_viewer_sees_only_what_they_may(): void
    {
        $day = $this->yesterday();
        $this->note($day, 'owner private', 'private', $this->owner);
        $this->note($day, 'team note', 'team', $this->admin);
        $this->note($day, 'public note', 'public', $this->owner);
        $this->note($day, 'viewer private', 'private', $this->viewer);

        $notes = fn (User $u) => collect($this->as($u)->getJson('/api/sites/example.com/annotations')->assertOk()->json('data'))->pluck('note')->sort()->values()->all();
        $this->assertSame(['owner private', 'public note', 'team note'], $notes($this->owner));
        $this->assertSame(['public note', 'team note'], $notes($this->admin));
        $this->assertSame(['public note', 'team note', 'viewer private'], $notes($this->viewer));

        $this->app['auth']->forgetGuards();
        $this->assertSame(['public note'], collect($this->getJson('/api/sites/example.com/annotations')->assertOk()->json('data'))->pluck('note')->all());
    }

    public function test_a_private_site_shows_a_shared_link_viewer_only_public_notes(): void
    {
        $this->site->update(['public' => false]);
        $link = SharedLink::create(['site_id' => $this->site->id, 'slug' => 'abc123abc123', 'name' => 'Team']);
        $this->note($this->yesterday(), 'team note', 'team');
        $this->note($this->yesterday(), 'public note', 'public');

        $this->assertSame(['public note'], collect($this->getJson('/api/sites/example.com/annotations', ['X-Share-Auth' => $link->slug])->assertOk()->json('data'))->pluck('note')->all());
    }

    public function test_who_may_change_what(): void
    {
        $team = $this->note($this->yesterday(), 'team note', 'team', $this->owner);
        $mine = $this->note($this->yesterday(), 'viewer private', 'private', $this->viewer);
        $ownersPrivate = $this->note($this->yesterday(), 'owner private', 'private', $this->owner);

        // an admin can fix and remove a note the whole site sees, even one the owner wrote
        $this->as($this->admin)->patchJson("/api/sites/example.com/annotations/{$team->id}", ['note' => 'Launched v2.1'])->assertOk()->assertJsonPath('data.note', 'Launched v2.1');
        // a viewer cannot touch it, but can change their own
        $this->as($this->viewer)->patchJson("/api/sites/example.com/annotations/{$team->id}", ['note' => 'x'])->assertForbidden();
        $this->as($this->viewer)->deleteJson("/api/sites/example.com/annotations/{$team->id}")->assertForbidden();
        $this->as($this->viewer)->patchJson("/api/sites/example.com/annotations/{$mine->id}", ['note' => 'Edited'])->assertOk();
        // nobody, not even the owner, reaches into someone else's private note
        $this->as($this->owner)->patchJson("/api/sites/example.com/annotations/{$mine->id}", ['note' => 'x'])->assertNotFound();
        $this->as($this->admin)->deleteJson("/api/sites/example.com/annotations/{$ownersPrivate->id}")->assertNotFound();
        // a viewer cannot promote their own note to the whole site
        $this->as($this->viewer)->patchJson("/api/sites/example.com/annotations/{$mine->id}", ['visibility' => 'team'])->assertForbidden();
        $this->as($this->admin)->deleteJson("/api/sites/example.com/annotations/{$team->id}")->assertOk();
        $this->assertSame(2, Annotation::count());
    }

    public function test_notes_are_checked_and_cleaned(): void
    {
        $post = fn (array $d) => $this->as($this->owner)->postJson('/api/sites/example.com/annotations', $d + ['date' => $this->yesterday(), 'note' => 'ok', 'visibility' => 'team']);

        $post(['date' => 'yesterday'])->assertUnprocessable()->assertJsonValidationErrors('date');
        $post(['date' => '1999-12-31'])->assertUnprocessable();
        $post(['date' => now()->addYears(5)->toDateString()])->assertUnprocessable();
        $post(['note' => ''])->assertUnprocessable();
        $post(['note' => str_repeat('a', 281)])->assertUnprocessable()->assertJsonValidationErrors('note');
        $post(['note' => "  \x00\x07  "])->assertUnprocessable();
        $post(['visibility' => 'everyone'])->assertUnprocessable();
        $post(['note' => "  Launched \x07v2 <b>today</b>  "])->assertCreated()->assertJsonPath('data.note', 'Launched v2 <b>today</b>'); // stored as text: the dashboard escapes it
    }

    public function test_a_site_has_a_limit(): void
    {
        $rows = [];
        for ($i = 0; $i < Annotation::MAX_PER_SITE; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'site_id' => $this->site->id, 'user_id' => $this->owner->id, 'date' => '2026-01-01', 'note' => "n{$i}", 'visibility' => 'team', 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('annotations')->insert($rows);

        $this->as($this->owner)->postJson('/api/sites/example.com/annotations', ['date' => $this->yesterday(), 'note' => 'one too many', 'visibility' => 'team'])->assertUnprocessable()->assertJsonValidationErrors('note');
    }

    public function test_the_graph_carries_the_notes_inside_its_period_for_the_viewer(): void
    {
        $this->note(now('UTC')->subDays(3)->toDateString(), 'inside', 'team');
        $this->note(now('UTC')->subDays(2)->toDateString(), 'inside public', 'public');
        $this->note(now('UTC')->subDays(40)->toDateString(), 'too old', 'team');
        $this->note(now('UTC')->subDays(4)->toDateString(), 'owner only', 'private', $this->owner);

        $graph = fn (string $period) => $this->getJson("/api/sites/example.com/stats/graph?period={$period}");
        $names = fn ($res) => collect($res->assertOk()->json('annotations'))->pluck('note')->all();

        $this->assertSame(['owner only', 'inside', 'inside public'], $names($this->as($this->owner)->getJson('/api/sites/example.com/stats/graph?period=7d')));
        $this->assertSame(['inside', 'inside public'], $names($this->as($this->admin)->getJson('/api/sites/example.com/stats/graph?period=7d')));
        $this->assertContains('too old', $names($this->as($this->admin)->getJson('/api/sites/example.com/stats/graph?period=91d')));
        $this->assertSame([], $names($this->as($this->admin)->getJson('/api/sites/example.com/stats/graph?period=realtime')));

        $this->app['auth']->forgetGuards();
        $this->assertSame(['inside public'], $names($graph('7d')));
        $one = $this->as($this->owner)->getJson('/api/sites/example.com/stats/graph?period=7d')->json('annotations.1');
        $this->assertSame(['mine' => true, 'editable' => true], ['mine' => $one['mine'], 'editable' => $one['editable']]);
    }

    public function test_the_csv_export_includes_the_notes_the_viewer_may_see(): void
    {
        $this->note(now('UTC')->subDays(2)->toDateString(), 'Newsletter went out', 'team');
        $this->note(now('UTC')->subDays(2)->toDateString(), 'Secret plan', 'private', $this->owner);

        $files = function (User $u) {
            $res = $this->as($u)->get('/api/sites/example.com/stats/export?period=7d')->assertOk();
            $tmp = tempnam(sys_get_temp_dir(), 'an');
            file_put_contents($tmp, $res->streamedContent());
            $zip = new \ZipArchive;
            $zip->open($tmp);
            $csv = $zip->getFromName('annotations.csv');
            $zip->close();
            unlink($tmp);

            return $csv;
        };
        $viewer = $files($this->admin);
        $this->assertStringContainsString('Newsletter went out', $viewer);
        $this->assertStringNotContainsString('Secret plan', $viewer);
        $this->assertStringContainsString('Secret plan', $files($this->owner));
    }

    public function test_the_stats_api_lists_notes_for_the_keys_owner(): void
    {
        $this->note(now('UTC')->subDays(2)->toDateString(), 'Newsletter went out', 'team');
        $this->note(now('UTC')->subDays(2)->toDateString(), 'Secret plan', 'private', $this->owner);
        $key = $this->as($this->admin)->postJson('/api/me/keys', ['name' => 'ci'])->assertSuccessful()->json('token');
        $this->app['auth']->forgetGuards();

        $res = $this->withToken($key)->getJson('/api/v1/stats/annotations?site_id=example.com&period=7d')->assertOk();
        $this->assertSame(['Newsletter went out'], collect($res->json('results'))->pluck('note')->all());
        $this->assertSame(['date', 'note', 'visibility'], array_keys($res->json('results.0')));
    }

    public function test_deleting_a_site_takes_its_notes_with_it(): void
    {
        $this->note($this->yesterday());

        $this->as($this->owner)->deleteJson('/api/sites/example.com')->assertOk();

        $this->assertSame(0, Annotation::count());
    }
}
