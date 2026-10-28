<?php

namespace Tests\Feature;

use App\Contracts\AuthorizesOwnershipTransfer;
use App\Contracts\Entitlements;
use App\Mail\OrganizationAdminMail;
use App\Mail\OwnershipTransferMail;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationsTest extends TestCase
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

    private function org(): Organization
    {
        return Organization::where('owner_id', $this->owner->id)->firstOrFail();
    }

    private function makeAdmin(): void
    {
        $this->actingAs($this->owner);
        $this->postJson('/api/organization/admins', ['email' => 'admin@example.com'])->assertCreated();
    }

    public function test_sites_belong_to_an_organization_named_after_the_owner_unless_they_name_it(): void
    {
        $this->assertSame("Owner's organization", $this->org()->name);
        $this->assertSame($this->org()->id, Site::where('domain', 'example.com')->value('organization_id'));
        $this->assertSame(1, Organization::count()); // the second site joined the same organization

        config(['tidemark.registration' => true]);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/register', ['name' => 'Pat', 'email' => 'pat@example.com', 'password' => 'password1', 'password_confirmation' => 'password1', 'organization' => 'Acme Studio'])->assertCreated();
        $this->assertSame('Acme Studio', Organization::where('owner_id', User::where('email', 'pat@example.com')->value('id'))->value('name'));
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/register', ['name' => 'Kim', 'email' => 'kim@example.com', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertCreated();
        $this->assertSame("Kim's organization", Organization::where('owner_id', User::where('email', 'kim@example.com')->value('id'))->value('name'));
    }

    public function test_the_owner_renames_the_organization_and_admins_cannot(): void
    {
        $this->patchJson('/api/organization', ['name' => 'Foggy Mountain'])->assertOk();
        $this->assertSame('Foggy Mountain', $this->org()->name);
        $this->makeAdmin();
        $this->actingAs($this->admin);
        $this->patchJson('/api/organization', ['name' => 'Mine now'])->assertNotFound(); // they own none
        $this->assertSame('Foggy Mountain', $this->org()->name);
    }

    public function test_an_admin_is_admin_on_every_site_but_not_the_owner_and_not_on_rollups(): void
    {
        config(['mail.default' => 'smtp']);
        $this->makeAdmin();
        Mail::assertSent(OrganizationAdminMail::class, fn ($m) => $m->hasTo('admin@example.com') && $m->invitation === null);
        $this->postJson('/api/rollups', ['name' => 'all-sites', 'all' => true])->assertCreated();

        foreach (['example.com', 'second.com'] as $d) {
            $this->assertSame('admin', Site::where('domain', $d)->first()->roleOf($this->admin));
        }
        $this->assertDatabaseCount('site_user', 3); // two owner rows and the rollup, nothing per admin
        $this->actingAs($this->admin);
        $this->assertSame(['example.com', 'second.com'], collect($this->getJson('/api/sites')->json('data'))->pluck('domain')->all());
        $this->getJson('/api/sites/example.com')->assertOk()->assertJsonPath('data.can_manage', true)->assertJsonPath('data.is_owner', true)->assertJsonPath('data.role', 'admin');
        $this->getJson('/api/sites/all-sites')->assertForbidden();
        $this->assertSame([['id' => $this->org()->id, 'name' => "Owner's organization", 'role' => 'admin']], $this->getJson('/api/me')->json('user.organizations'));
    }

    public function test_removing_an_admin_takes_the_access_back(): void
    {
        $this->makeAdmin();
        $this->deleteJson("/api/organization/admins/{$this->admin->id}")->assertOk();
        $this->actingAs($this->admin);
        $this->assertSame([], $this->getJson('/api/sites')->json('data'));
        $this->getJson('/api/sites/example.com')->assertForbidden();
    }

    public function test_an_admin_adds_sites_to_the_organization_under_the_owners_limits(): void
    {
        $this->makeAdmin();
        config(['tidemark.limits.sites_per_user' => 3]);
        $this->actingAs($this->admin);
        $this->postJson('/api/sites', ['domain' => 'third.com', 'organization' => $this->org()->id])->assertCreated();
        $site = Site::where('domain', 'third.com')->first();
        $this->assertSame($this->owner->id, $site->ownerId());
        $this->assertSame($this->org()->id, $site->organization_id);
        $this->postJson('/api/sites', ['domain' => 'fourth.com', 'organization' => $this->org()->id])->assertUnprocessable();
        $this->postJson('/api/sites', ['domain' => 'mine.com'])->assertCreated(); // no organization named: their own, created for them
        $this->assertSame("Admin's organization", Organization::where('owner_id', $this->admin->id)->value('name'));
        $this->actingAs($this->user('stranger@example.com'));
        $this->postJson('/api/sites', ['domain' => 'nope.com', 'organization' => $this->org()->id])->assertUnprocessable();
    }

    public function test_an_admin_manages_the_organizations_teams_and_deletes_its_sites(): void
    {
        $this->makeAdmin();
        $sam = $this->user('sam@example.com');
        $this->actingAs($this->admin);
        $id = $this->postJson('/api/teams', ['name' => 'Staff', 'organization_id' => $this->org()->id])->assertCreated()->json('data.id');
        $team = collect($this->getJson('/api/teams')->json('data'))->firstWhere('id', $id);
        $this->assertSame("Owner's organization", $team['organization']);
        $this->postJson("/api/teams/{$id}/members", ['email' => 'sam@example.com'])->assertCreated();
        $this->postJson('/api/sites/example.com/teams', ['team_id' => $id, 'role' => 'viewer'])->assertCreated();
        $this->assertSame('viewer', Site::where('domain', 'example.com')->first()->roleOf($sam));
        $this->deleteJson('/api/sites/second.com')->assertOk();
        $this->actingAs($this->user('stranger@example.com'));
        $this->postJson('/api/teams', ['name' => 'Evil', 'organization_id' => $this->org()->id])->assertForbidden();
        $this->deleteJson("/api/teams/{$id}")->assertNotFound();
    }

    public function test_an_unknown_address_gets_an_invitation_that_makes_them_an_admin(): void
    {
        config(['tidemark.invites' => true, 'mail.default' => 'smtp']);
        $this->postJson('/api/organization/admins', ['email' => 'new@example.com'])->assertCreated()->assertJsonPath('invited', true);
        $token = null;
        Mail::assertSent(OrganizationAdminMail::class, function (OrganizationAdminMail $m) use (&$token) {
            $token = substr($m->invitation->url(), strpos($m->invitation->url(), '=') + 1);

            return true;
        });
        $this->assertSame('new@example.com', $this->getJson('/api/organization')->json('organization.invites.0.email'));
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/invites/{$token}")->assertOk()->assertJsonPath('organization', "Owner's organization");
        $this->postJson("/api/invites/{$token}/accept", ['name' => 'New', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertOk()->assertJsonPath('site', 'example.com');
        $new = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame('admin', Site::where('domain', 'example.com')->first()->roleOf($new));
        $this->assertSame(0, Organization::where('owner_id', $new->id)->count()); // helping does not create an organization of their own
        $this->assertSame(0, Invitation::count());
    }

    public function test_without_invitations_an_unknown_address_is_refused(): void
    {
        $this->postJson('/api/organization/admins', ['email' => 'nobody@example.com'])->assertUnprocessable();
    }

    public function test_ownership_moves_only_after_the_admin_accepts(): void
    {
        config(['mail.default' => 'smtp']);
        $this->makeAdmin();
        $this->postJson('/api/organization/transfer', ['user_id' => $this->admin->id, 'confirm' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $stranger = $this->user('stranger@example.com');
        $this->postJson('/api/organization/transfer', ['user_id' => $stranger->id, 'confirm' => "Owner's organization"])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson('/api/organization/transfer', ['user_id' => $this->admin->id, 'confirm' => "owner's ORGANIZATION"])->assertOk();
        Mail::assertSent(OwnershipTransferMail::class, fn ($m) => $m->hasTo('admin@example.com'));
        $this->assertSame($this->owner->id, $this->org()->owner_id); // still theirs
        $this->assertSame($this->admin->id, $this->getJson('/api/organization')->json('organization.transfer.to.id'));

        $this->actingAs($this->admin);
        $offer = $this->getJson('/api/organization')->assertOk()->json('incoming_transfers.0');
        $this->assertSame("Owner's organization", $offer['name']);
        $orgId = $offer['id'];
        $this->postJson("/api/organization/transfers/{$orgId}/accept")->assertOk();

        $org = Organization::find($orgId);
        $this->assertSame($this->admin->id, $org->owner_id);
        $this->assertSame([$this->owner->id], $org->admins()->pluck('users.id')->all()); // the previous owner stays on as an admin
        foreach (['example.com', 'second.com'] as $d) {
            $s = Site::where('domain', $d)->first();
            $this->assertSame($this->admin->id, $s->ownerId());
            $this->assertSame('admin', $s->roleOf($this->owner));
            $this->assertSame(1, DB::table('site_user')->where('site_id', $s->id)->where('role', 'owner')->count());
        }
        $this->assertNull($org->transfer_to);
        $this->postJson("/api/organization/transfers/{$orgId}/accept")->assertNotFound(); // nothing pending any more
    }

    public function test_an_offer_can_be_cancelled_declined_or_expire(): void
    {
        $this->makeAdmin();
        $this->postJson('/api/organization/transfer', ['user_id' => $this->admin->id, 'confirm' => "Owner's organization"])->assertOk();
        $this->deleteJson('/api/organization/transfer')->assertOk();
        $this->assertNull($this->org()->transfer_to);

        $this->postJson('/api/organization/transfer', ['user_id' => $this->admin->id, 'confirm' => "Owner's organization"])->assertOk();
        $this->actingAs($this->admin);
        $this->deleteJson("/api/organization/transfers/{$this->org()->id}")->assertOk();
        $this->assertSame($this->owner->id, $this->org()->owner_id);

        $this->actingAs($this->owner);
        $this->postJson('/api/organization/transfer', ['user_id' => $this->admin->id, 'confirm' => "Owner's organization"])->assertOk();
        $this->travel(73)->hours();
        $this->actingAs($this->admin);
        $this->assertSame([], $this->getJson('/api/organization')->json('incoming_transfers'));
        $this->postJson("/api/organization/transfers/{$this->org()->id}/accept")->assertStatus(410);
        $this->assertSame($this->owner->id, $this->org()->owner_id);
    }

    public function test_an_extension_can_refuse_a_transfer(): void
    {
        $this->app->singleton(Entitlements::class, fn () => new class extends DefaultEntitlements implements AuthorizesOwnershipTransfer
        {
            public function allowsOwnershipTransfer(Organization $organization, User $to): true|string
            {
                return 'Not on this plan.';
            }
        });
        $this->makeAdmin();
        $this->postJson('/api/organization/transfer', ['user_id' => $this->admin->id, 'confirm' => "Owner's organization"])->assertUnprocessable()->assertJsonPath('errors.user_id.0', 'Not on this plan.');
    }

    public function test_the_site_people_list_shows_organization_admins(): void
    {
        $this->makeAdmin();
        $this->assertSame(['admin@example.com'], collect($this->getJson('/api/sites/example.com/people')->json('organization_admins'))->pluck('email')->all());
    }

    public function test_existing_sites_move_into_an_organization_when_the_migration_runs(): void
    {
        $legacy = User::create(['name' => 'Legacy Larry', 'email' => 'larry@example.com', 'password' => 'password']);
        $site = Site::create(['domain' => 'legacy.com']);
        $site->members()->attach($legacy->id, ['role' => 'owner', 'id' => (string) Str::uuid()]);
        $this->assertNull($site->fresh()->organization_id);

        $migration = require database_path('migrations/2026_11_17_000001_create_organizations.php');
        Schema::drop('organization_user');
        Schema::drop('organizations');
        foreach (['sites', 'invitations'] as $table) {
            Schema::table($table, fn ($t) => $t->dropIndex(['organization_id']));
            Schema::table($table, fn ($t) => $t->dropColumn('organization_id'));
        }
        $migration->up();

        $org = Organization::where('owner_id', $legacy->id)->first();
        $this->assertSame("Legacy Larry's organization", $org->name);
        $this->assertSame($org->id, Site::where('domain', 'legacy.com')->value('organization_id'));
    }
}
