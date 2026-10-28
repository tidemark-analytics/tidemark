<?php

namespace Tests\Feature;

use App\Mail\TeamMail;
use App\Models\Invitation;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TeamsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->owner = $this->user('owner@example.com');
        $this->actingAs($this->owner);
        $this->postJson('/api/sites', ['domain' => 'example.com'])->assertCreated();
        $this->postJson('/api/sites', ['domain' => 'second.com'])->assertCreated();
    }

    private function user(string $email): User
    {
        return User::create(['name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password' => 'password']);
    }

    private function team(string $name = 'Marketing'): string
    {
        return $this->postJson('/api/teams', ['name' => $name])->assertCreated()->json('data.id');
    }

    private function role(string $domain, User $u): ?string
    {
        return Site::where('domain', $domain)->first()->roleOf($u);
    }

    public function test_teams_belong_to_their_owner_and_names_are_unique_per_owner(): void
    {
        $this->team();
        $this->postJson('/api/teams', ['name' => 'Marketing'])->assertUnprocessable();
        $this->assertCount(1, $this->getJson('/api/teams')->json('data'));
        $this->assertSame(['example.com', 'second.com'], $this->getJson('/api/teams')->json('owned_sites'));

        $other = $this->user('other@example.com');
        $this->actingAs($other);
        $this->assertCount(0, $this->getJson('/api/teams')->json('data'));
        $this->postJson('/api/teams', ['name' => 'Marketing'])->assertCreated();
    }

    public function test_sharing_a_site_with_a_team_gives_each_member_the_teams_role(): void
    {
        config(['mail.default' => 'smtp']);
        $sam = $this->user('sam@example.com');
        $id = $this->team();
        $this->postJson("/api/teams/{$id}/members", ['email' => 'sam@example.com'])->assertCreated();
        Mail::assertSent(TeamMail::class, fn ($m) => $m->hasTo('sam@example.com') && $m->invitation === null);
        $this->assertNull($this->role('example.com', $sam));

        $this->postJson('/api/sites/example.com/teams', ['team_id' => $id, 'role' => 'viewer'])->assertCreated();
        $this->assertSame('viewer', $this->role('example.com', $sam));
        $this->assertNull($this->role('second.com', $sam));

        $this->patchJson("/api/sites/example.com/teams/{$id}", ['role' => 'admin'])->assertOk();
        $this->assertSame('admin', $this->role('example.com', $sam));

        $this->actingAs($sam);
        $this->getJson('/api/sites/example.com')->assertOk()->assertJsonPath('data.can_manage', true)->assertJsonPath('data.is_owner', false);
        $this->assertSame(['example.com'], collect($this->getJson('/api/sites')->json('data'))->pluck('domain')->all());
        $this->getJson('/api/sites/second.com')->assertForbidden();
    }

    public function test_the_strongest_role_wins_and_removing_the_team_never_touches_a_direct_grant(): void
    {
        $sam = $this->user('sam@example.com');
        $id = $this->team();
        $this->postJson("/api/teams/{$id}/members", ['email' => 'sam@example.com']);
        $this->postJson('/api/sites/example.com/people', ['email' => 'sam@example.com', 'role' => 'viewer'])->assertCreated();
        $this->postJson('/api/sites/example.com/teams', ['team_id' => $id, 'role' => 'admin'])->assertCreated();
        $this->assertSame('admin', $this->role('example.com', $sam)); // direct viewer + team admin

        $this->deleteJson("/api/teams/{$id}/members/{$sam->id}")->assertOk();
        $this->assertSame('viewer', $this->role('example.com', $sam)); // the direct grant stays

        $this->postJson("/api/teams/{$id}/members", ['email' => 'sam@example.com']);
        $this->deleteJson("/api/teams/{$id}")->assertOk();
        $this->assertSame('viewer', $this->role('example.com', $sam));
        $this->assertSame(0, Team::count());
        $this->assertDatabaseCount('site_team', 0);
    }

    public function test_only_the_owner_shares_with_their_own_teams(): void
    {
        $sam = $this->user('sam@example.com');
        $mine = $this->team();
        $this->postJson('/api/sites/example.com/people', ['email' => 'sam@example.com', 'role' => 'admin'])->assertCreated();

        $this->actingAs($sam);
        $theirs = $this->postJson('/api/teams', ['name' => 'Sam team'])->json('data.id');
        $this->postJson('/api/sites/example.com/teams', ['team_id' => $mine, 'role' => 'viewer'])->assertForbidden(); // an admin is not the owner
        $this->actingAs($this->owner);
        $this->postJson('/api/sites/example.com/teams', ['team_id' => $theirs, 'role' => 'viewer'])->assertUnprocessable(); // not the owner's team
        $this->postJson('/api/sites/example.com/teams', ['team_id' => $mine, 'role' => 'owner'])->assertUnprocessable();

        $people = $this->getJson('/api/sites/example.com/people')->assertOk();
        $this->assertSame([$mine], collect($people->json('available_teams'))->pluck('id')->all());
        $this->actingAs($sam);
        $this->assertSame([], $this->getJson('/api/sites/example.com/people')->json('available_teams'));
    }

    public function test_an_unknown_address_gets_a_team_invitation_that_joins_the_team(): void
    {
        config(['tidemark.invites' => true, 'mail.default' => 'smtp']);
        $id = $this->team();
        $this->postJson('/api/sites/example.com/teams', ['team_id' => $id, 'role' => 'viewer'])->assertCreated();
        $this->postJson("/api/teams/{$id}/members", ['email' => 'new@example.com'])->assertCreated()->assertJsonPath('invited', true);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
        $token = null;
        Mail::assertSent(TeamMail::class, function (TeamMail $m) use (&$token) {
            $token = substr($m->invitation->url(), strpos($m->invitation->url(), '=') + 1);

            return $m->invitation !== null;
        });
        $this->assertSame('new@example.com', $this->getJson('/api/teams')->json('data.0.invites.0.email'));

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/invites/{$token}")->assertOk()->assertJsonPath('team', 'Marketing')->assertJsonPath('site', null);
        $this->postJson("/api/invites/{$token}/accept", ['name' => 'New', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertOk()->assertJsonPath('site', 'example.com');
        $new = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame('viewer', $this->role('example.com', $new));
        $this->assertSame(0, Invitation::count());
    }

    public function test_without_invitations_an_unknown_address_is_refused(): void
    {
        $id = $this->team();
        $this->postJson("/api/teams/{$id}/members", ['email' => 'nobody@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_reports_and_the_site_list_follow_team_access(): void
    {
        $sam = $this->user('sam@example.com');
        $id = $this->team();
        $this->postJson("/api/teams/{$id}/members", ['email' => 'sam@example.com']);
        $this->postJson('/api/sites/example.com/teams', ['team_id' => $id, 'role' => 'viewer']);
        $this->actingAs($sam);
        $this->assertSame(['example.com'], collect($this->getJson('/api/me/reports')->json('data'))->pluck('domain')->all());
        $this->putJson('/api/me/reports/'.Site::where('domain', 'example.com')->value('id'), ['frequency' => 'weekly'])->assertOk();
    }
}
