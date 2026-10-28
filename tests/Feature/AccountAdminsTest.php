<?php

namespace Tests\Feature;

use App\Mail\AccountAdminMail;
use App\Models\AccountAdmin;
use App\Models\Invitation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountAdminsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->owner = $this->user('owner@example.com');
        $this->admin = $this->user('admin@example.com');
        $this->actingAs($this->owner);
        $this->postJson('/api/sites', ['domain' => 'example.com'])->assertCreated();
        $this->postJson('/api/sites', ['domain' => 'second.com'])->assertCreated();
    }

    private function user(string $email): User
    {
        return User::create(['name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password' => 'password']);
    }

    private function makeAdmin(): void
    {
        $this->actingAs($this->owner);
        $this->postJson('/api/account/admins', ['email' => 'admin@example.com'])->assertCreated();
    }

    public function test_an_account_admin_is_admin_on_every_site_of_the_account_but_not_the_owner(): void
    {
        config(['mail.default' => 'smtp']);
        $this->makeAdmin();
        Mail::assertSent(AccountAdminMail::class, fn ($m) => $m->hasTo('admin@example.com') && $m->invitation === null);

        foreach (['example.com', 'second.com'] as $d) {
            $this->assertSame('admin', Site::where('domain', $d)->first()->roleOf($this->admin));
        }
        $this->assertDatabaseCount('site_user', 2); // nothing was added per site
        $this->actingAs($this->admin);
        $this->assertSame(['example.com', 'second.com'], collect($this->getJson('/api/sites')->json('data'))->pluck('domain')->all());
        $this->getJson('/api/sites/example.com')->assertOk()->assertJsonPath('data.can_manage', true)->assertJsonPath('data.is_owner', true)->assertJsonPath('data.role', 'admin');
        $this->patchJson('/api/sites/example.com', ['public' => true])->assertOk();
        $this->assertContains($this->owner->id, $this->getJson('/api/me')->json('user.administers.*.id'));
    }

    public function test_removing_the_admin_takes_the_access_back_and_strangers_cannot_add_themselves(): void
    {
        $this->makeAdmin();
        $this->deleteJson("/api/account/admins/{$this->admin->id}")->assertOk();
        $this->actingAs($this->admin);
        $this->assertSame([], $this->getJson('/api/sites')->json('data'));
        $this->getJson('/api/sites/example.com')->assertForbidden();

        $this->postJson('/api/account/admins', ['email' => 'owner@example.com'])->assertCreated(); // adding the owner as *their own* admin: it is their account that changes
        $this->actingAs($this->owner);
        $this->assertSame([], $this->getJson('/api/account/admins')->json('data'));
    }

    public function test_an_admin_does_not_make_more_admins_for_the_owner_and_rollups_stay_private(): void
    {
        $this->makeAdmin();
        $third = $this->user('third@example.com');
        $this->actingAs($this->owner);
        $this->postJson('/api/rollups', ['name' => 'all-sites', 'all' => true])->assertCreated();
        $this->actingAs($this->admin);
        $this->postJson('/api/account/admins', ['email' => 'third@example.com'])->assertCreated();
        $this->assertNull(Site::where('domain', 'example.com')->first()->roleOf($third)); // that made them admin of the admin's own account only
        $this->assertSame(1, Site::where('kind', 'rollup')->count());
        $this->getJson('/api/sites/all-sites')->assertForbidden();
        $this->assertNotContains('all-sites', collect($this->getJson('/api/sites')->json('data'))->pluck('domain')->all());
    }

    public function test_an_admin_adds_sites_to_the_owners_account_under_the_owners_limits(): void
    {
        $this->makeAdmin();
        config(['tidemark.limits.sites_per_user' => 3]);
        $this->actingAs($this->admin);
        $this->postJson('/api/sites', ['domain' => 'third.com', 'account' => $this->owner->id])->assertCreated();
        $site = Site::where('domain', 'third.com')->first();
        $this->assertSame($this->owner->id, $site->ownerId());
        $this->assertSame('admin', $site->roleOf($this->admin));
        $this->postJson('/api/sites', ['domain' => 'fourth.com', 'account' => $this->owner->id])->assertUnprocessable(); // the owner's three sites are used up
        $this->postJson('/api/sites', ['domain' => 'mine.com'])->assertCreated(); // their own account is separate
        $this->assertSame($this->admin->id, Site::where('domain', 'mine.com')->first()->ownerId());
        $this->actingAs($this->user('stranger@example.com'));
        $this->postJson('/api/sites', ['domain' => 'nope.com', 'account' => $this->owner->id])->assertUnprocessable();
    }

    public function test_an_admin_manages_the_accounts_teams_and_shares_its_sites_with_them(): void
    {
        $this->makeAdmin();
        $sam = $this->user('sam@example.com');
        $this->actingAs($this->admin);
        $id = $this->postJson('/api/teams', ['name' => 'Staff', 'account_id' => $this->owner->id])->assertCreated()->json('data.id');
        $team = collect($this->getJson('/api/teams')->json('data'))->firstWhere('id', $id);
        $this->assertSame('Owner', $team['account']);
        $this->assertSame(['example.com', 'second.com'], collect($this->getJson('/api/teams')->json('owned_sites'))->pluck('domain')->all());
        $this->postJson("/api/teams/{$id}/members", ['email' => 'sam@example.com'])->assertCreated();
        $this->postJson('/api/sites/example.com/teams', ['team_id' => $id, 'role' => 'viewer'])->assertCreated();
        $this->assertSame('viewer', Site::where('domain', 'example.com')->first()->roleOf($sam));
        $this->actingAs($this->user('stranger@example.com'));
        $this->postJson('/api/teams', ['name' => 'Evil', 'account_id' => $this->owner->id])->assertForbidden();
        $this->deleteJson("/api/teams/{$id}")->assertNotFound();
    }

    public function test_an_admin_can_delete_a_site_of_the_account(): void
    {
        $this->makeAdmin();
        $this->actingAs($this->admin);
        $this->deleteJson('/api/sites/second.com')->assertOk();
        $this->assertNull(Site::where('domain', 'second.com')->first());
    }

    public function test_an_unknown_address_gets_an_invitation_that_makes_them_an_admin_on_acceptance(): void
    {
        config(['tidemark.invites' => true, 'mail.default' => 'smtp']);
        $this->postJson('/api/account/admins', ['email' => 'new@example.com'])->assertCreated()->assertJsonPath('invited', true);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
        $token = null;
        Mail::assertSent(AccountAdminMail::class, function (AccountAdminMail $m) use (&$token) {
            $token = substr($m->invitation->url(), strpos($m->invitation->url(), '=') + 1);

            return true;
        });
        $this->assertSame('new@example.com', $this->getJson('/api/account/admins')->json('invites.0.email'));

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/invites/{$token}")->assertOk()->assertJsonPath('account', 'Owner');
        $this->postJson("/api/invites/{$token}/accept", ['name' => 'New', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertOk()->assertJsonPath('site', 'example.com');
        $new = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame('admin', Site::where('domain', 'example.com')->first()->roleOf($new));
        $this->assertTrue(AccountAdmin::where('owner_id', $this->owner->id)->where('user_id', $new->id)->exists());
        $this->assertSame(0, Invitation::count());
    }

    public function test_without_invitations_an_unknown_address_is_refused(): void
    {
        $this->postJson('/api/account/admins', ['email' => 'nobody@example.com'])->assertUnprocessable();
    }

    public function test_the_site_people_list_shows_account_admins_and_the_owner_alone_picks_teams(): void
    {
        $this->makeAdmin();
        $people = $this->getJson('/api/sites/example.com/people')->assertOk();
        $this->assertSame(['admin@example.com'], collect($people->json('account_admins'))->pluck('email')->all());
        $this->actingAs($this->admin);
        $this->assertSame([], $this->getJson('/api/sites/example.com/people')->json('available_teams')); // no teams exist yet, but the call works for the admin
    }
}
