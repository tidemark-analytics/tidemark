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
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private string $org;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->owner = $this->user('owner@example.com');
        $this->admin = $this->user('admin@example.com');
        $this->actingAs($this->owner);
        $this->org = $this->postJson('/api/organizations', ['name' => 'Foggy Mountain'])->assertCreated()->json('data.id');
        $this->postJson('/api/sites', ['domain' => 'example.com', 'organization' => $this->org])->assertCreated();
        $this->postJson('/api/sites', ['domain' => 'second.com', 'organization' => $this->org])->assertCreated();
    }

    private function user(string $email): User
    {
        return User::create(['name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password' => 'password']);
    }

    private function personal(User $u): string
    {
        return Organization::personalFor($u)->id;
    }

    private function makeAdmin(): void
    {
        $this->actingAs($this->owner);
        $this->postJson("/api/organizations/{$this->org}/admins", ['email' => 'admin@example.com'])->assertCreated();
    }

    public function test_everyone_has_a_personal_organization_created_with_their_sign_in(): void
    {
        config(['tidemark.registration' => true]);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/register', ['name' => 'Pat', 'email' => 'pat@example.com', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertCreated();
        $pat = User::where('email', 'pat@example.com')->firstOrFail();
        $personal = Organization::where('owner_id', $pat->id)->get();
        $this->assertCount(1, $personal);
        $this->assertTrue($personal[0]->personal);
        $this->assertSame('Personal', $personal[0]->name);
        $this->assertSame(['Personal'], collect($this->getJson('/api/me')->json('user.organizations'))->pluck('name')->all());

        $this->postJson('/api/sites', ['domain' => 'mine.com'])->assertCreated(); // no organization chosen: Personal
        $this->assertSame($personal[0]->id, Site::where('domain', 'mine.com')->value('organization_id'));
    }

    public function test_personal_is_just_for_its_owner(): void
    {
        $p = $this->personal($this->owner);
        $this->patchJson("/api/organizations/{$p}", ['name' => 'Renamed'])->assertUnprocessable();
        $this->deleteJson("/api/organizations/{$p}", ['confirm' => 'Personal'])->assertUnprocessable();
        $this->postJson("/api/organizations/{$p}/admins", ['email' => 'admin@example.com'])->assertUnprocessable();
        $this->postJson("/api/organizations/{$p}/transfer", ['user_id' => $this->admin->id, 'confirm' => 'Personal'])->assertUnprocessable();
        $this->postJson('/api/teams', ['name' => 'Staff', 'organization_id' => $p])->assertUnprocessable();
        $this->assertSame('Personal', Organization::find($p)->name);
        $this->assertSame(0, Organization::find($p)->admins()->count());
    }

    public function test_anyone_creates_an_organization_up_to_a_limit_and_owns_it(): void
    {
        $org = Organization::find($this->org);
        $this->assertSame($this->owner->id, $org->owner_id);
        $this->assertFalse($org->personal);
        config(['tidemark.organization_limit' => 2]);
        $this->postJson('/api/organizations', ['name' => 'Second'])->assertCreated();
        $this->postJson('/api/organizations', ['name' => 'Third'])->assertUnprocessable();
        $me = collect($this->getJson('/api/me')->json('user.organizations'));
        $this->assertSame(['Personal', 'Foggy Mountain', 'Second'], $me->pluck('name')->all());
        $this->assertSame('owner', $me->firstWhere('name', 'Second')['role']);
    }

    public function test_the_site_list_follows_the_chosen_organization(): void
    {
        $this->postJson('/api/sites', ['domain' => 'mine.com'])->assertCreated();
        $this->postJson('/api/rollups', ['name' => 'all-sites', 'all' => true])->assertCreated();
        $domains = fn (string $q) => collect($this->getJson('/api/sites?organization='.$q)->json('data'))->pluck('domain')->all();
        $this->assertSame(['example.com', 'second.com'], $domains($this->org));
        $this->assertEqualsCanonicalizing(['mine.com', 'all-sites'], $domains($this->personal($this->owner))); // Personal also holds the person's rollups
        $this->assertCount(4, $this->getJson('/api/sites')->json('data')); // no choice: everything

        $this->makeAdmin();
        $this->actingAs($this->admin);
        $this->assertSame(['example.com', 'second.com'], $domains($this->org));
        $this->assertSame([], $domains($this->personal($this->admin)));
        $this->assertSame([], $domains('shared')); // organization admins are members, not "shared with"
    }

    public function test_sites_shared_one_by_one_show_under_shared_with_me(): void
    {
        $this->postJson('/api/sites/example.com/people', ['email' => 'admin@example.com', 'role' => 'viewer'])->assertCreated();
        $this->actingAs($this->admin);
        $this->assertTrue($this->getJson('/api/me')->json('user.shared_sites'));
        $this->assertSame(['example.com'], collect($this->getJson('/api/sites?organization=shared')->json('data'))->pluck('domain')->all());
        $this->assertSame([], $this->getJson('/api/sites?organization='.$this->personal($this->admin))->json('data'));
    }

    public function test_the_owner_renames_and_deletes_an_empty_organization(): void
    {
        $this->patchJson("/api/organizations/{$this->org}", ['name' => 'Foggy Drifters'])->assertOk();
        $this->assertSame('Foggy Drifters', Organization::find($this->org)->name);
        $this->deleteJson("/api/organizations/{$this->org}", ['confirm' => 'wrong'])->assertUnprocessable();
        $this->deleteJson("/api/organizations/{$this->org}", ['confirm' => 'foggy drifters'])->assertUnprocessable(); // still has sites
        foreach (['example.com', 'second.com'] as $d) {
            $this->deleteJson("/api/sites/{$d}")->assertOk();
        }
        $team = $this->postJson('/api/teams', ['name' => 'Staff', 'organization_id' => $this->org])->assertCreated()->json('data.id');
        $this->deleteJson("/api/organizations/{$this->org}", ['confirm' => 'foggy drifters'])->assertOk();
        $this->assertNull(Organization::find($this->org));
        $this->assertDatabaseMissing('teams', ['id' => $team]);
    }

    public function test_admins_cannot_rename_delete_or_manage_the_admin_list(): void
    {
        $this->makeAdmin();
        $this->actingAs($this->admin);
        $this->patchJson("/api/organizations/{$this->org}", ['name' => 'Mine now'])->assertNotFound();
        $this->deleteJson("/api/organizations/{$this->org}", ['confirm' => 'Foggy Mountain'])->assertNotFound();
        $this->postJson("/api/organizations/{$this->org}/admins", ['email' => 'owner@example.com'])->assertNotFound();
        $this->getJson("/api/organizations/{$this->org}")->assertOk()->assertJsonPath('role', 'admin')->assertJsonPath('owner.name', 'Owner')->assertJsonPath('invites', []);
        $this->assertSame('Foggy Mountain', Organization::find($this->org)->name);
    }

    public function test_an_admin_can_leave_but_the_owner_cannot(): void
    {
        $this->makeAdmin();
        $this->postJson("/api/organizations/{$this->org}/leave")->assertUnprocessable();
        $this->actingAs($this->admin);
        $this->postJson("/api/organizations/{$this->org}/leave")->assertOk();
        $this->getJson('/api/sites/example.com')->assertForbidden();
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
        $this->assertDatabaseCount('site_user', 3);
        $this->actingAs($this->admin);
        $this->getJson('/api/sites/example.com')->assertOk()->assertJsonPath('data.can_manage', true)->assertJsonPath('data.is_owner', true)->assertJsonPath('data.role', 'admin');
        $this->getJson('/api/sites/all-sites')->assertForbidden();
        $this->assertSame(['Personal', 'Foggy Mountain'], collect($this->getJson('/api/me')->json('user.organizations'))->pluck('name')->all());
    }

    public function test_removing_an_admin_takes_the_access_back(): void
    {
        $this->makeAdmin();
        $this->deleteJson("/api/organizations/{$this->org}/admins/{$this->admin->id}")->assertOk();
        $this->actingAs($this->admin);
        $this->getJson('/api/sites/example.com')->assertForbidden();
    }

    public function test_an_admin_adds_sites_to_the_organization_under_the_owners_limits(): void
    {
        $this->makeAdmin();
        config(['tidemark.limits.sites_per_user' => 3]);
        $this->actingAs($this->admin);
        $this->postJson('/api/sites', ['domain' => 'third.com', 'organization' => $this->org])->assertCreated();
        $site = Site::where('domain', 'third.com')->first();
        $this->assertSame($this->owner->id, $site->ownerId());
        $this->assertSame($this->org, $site->organization_id);
        $this->postJson('/api/sites', ['domain' => 'fourth.com', 'organization' => $this->org])->assertUnprocessable();
        $this->postJson('/api/sites', ['domain' => 'mine.com'])->assertCreated(); // Personal is separate
        $this->assertSame($this->admin->id, Site::where('domain', 'mine.com')->first()->ownerId());
        $this->actingAs($this->user('stranger@example.com'));
        $this->postJson('/api/sites', ['domain' => 'nope.com', 'organization' => $this->org])->assertUnprocessable();
    }

    public function test_an_admin_manages_the_organizations_teams_and_deletes_its_sites(): void
    {
        $this->makeAdmin();
        $sam = $this->user('sam@example.com');
        $this->actingAs($this->admin);
        $id = $this->postJson('/api/teams', ['name' => 'Staff', 'organization_id' => $this->org])->assertCreated()->json('data.id');
        $team = collect($this->getJson('/api/teams')->json('data'))->firstWhere('id', $id);
        $this->assertSame('Foggy Mountain', $team['organization']);
        $this->postJson("/api/teams/{$id}/members", ['email' => 'sam@example.com'])->assertCreated();
        $this->postJson('/api/sites/example.com/teams', ['team_id' => $id, 'role' => 'viewer'])->assertCreated();
        $this->assertSame('viewer', Site::where('domain', 'example.com')->first()->roleOf($sam));
        $this->deleteJson('/api/sites/second.com')->assertOk();
        $this->actingAs($this->user('stranger@example.com'));
        $this->postJson('/api/teams', ['name' => 'Evil', 'organization_id' => $this->org])->assertForbidden();
        $this->deleteJson("/api/teams/{$id}")->assertNotFound();
    }

    public function test_an_unknown_address_gets_an_invitation_that_makes_them_an_admin(): void
    {
        config(['tidemark.invites' => true, 'mail.default' => 'smtp']);
        $this->postJson("/api/organizations/{$this->org}/admins", ['email' => 'new@example.com'])->assertCreated()->assertJsonPath('invited', true);
        $token = null;
        Mail::assertSent(OrganizationAdminMail::class, function (OrganizationAdminMail $m) use (&$token) {
            $token = substr($m->invitation->url(), strpos($m->invitation->url(), '=') + 1);

            return true;
        });
        $this->assertSame('new@example.com', $this->getJson("/api/organizations/{$this->org}")->json('invites.0.email'));
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/invites/{$token}")->assertOk()->assertJsonPath('organization', 'Foggy Mountain');
        $this->postJson("/api/invites/{$token}/accept", ['name' => 'New', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertOk()->assertJsonPath('site', 'example.com');
        $new = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame('admin', Site::where('domain', 'example.com')->first()->roleOf($new));
        $this->assertSame(1, Organization::where('owner_id', $new->id)->where('personal', true)->count()); // they get their own Personal organization too
        $this->assertSame(0, Invitation::count());
    }

    public function test_without_invitations_an_unknown_address_is_refused(): void
    {
        $this->postJson("/api/organizations/{$this->org}/admins", ['email' => 'nobody@example.com'])->assertUnprocessable();
    }

    public function test_ownership_moves_only_after_the_admin_accepts(): void
    {
        config(['mail.default' => 'smtp']);
        $this->makeAdmin();
        $this->postJson("/api/organizations/{$this->org}/transfer", ['user_id' => $this->admin->id, 'confirm' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $stranger = $this->user('stranger@example.com');
        $this->postJson("/api/organizations/{$this->org}/transfer", ['user_id' => $stranger->id, 'confirm' => 'Foggy Mountain'])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson("/api/organizations/{$this->org}/transfer", ['user_id' => $this->admin->id, 'confirm' => 'foggy MOUNTAIN'])->assertOk();
        Mail::assertSent(OwnershipTransferMail::class, fn ($m) => $m->hasTo('admin@example.com'));
        $this->assertSame($this->owner->id, Organization::find($this->org)->owner_id);
        $this->assertSame($this->admin->id, $this->getJson("/api/organizations/{$this->org}")->json('transfer.to.id'));

        $this->actingAs($this->admin);
        $offer = $this->getJson('/api/organizations')->assertOk()->json('incoming_transfers.0');
        $this->assertSame('Foggy Mountain', $offer['name']);
        $this->postJson("/api/organizations/{$this->org}/transfer/accept")->assertOk();

        $org = Organization::find($this->org);
        $this->assertSame($this->admin->id, $org->owner_id);
        $this->assertSame([$this->owner->id], $org->admins()->pluck('users.id')->all());
        foreach (['example.com', 'second.com'] as $d) {
            $s = Site::where('domain', $d)->first();
            $this->assertSame($this->admin->id, $s->ownerId());
            $this->assertSame('admin', $s->roleOf($this->owner));
            $this->assertSame(1, DB::table('site_user')->where('site_id', $s->id)->where('role', 'owner')->count());
        }
        $this->assertNull($org->transfer_to);
        $this->postJson("/api/organizations/{$this->org}/transfer/accept")->assertNotFound();
    }

    public function test_an_offer_can_be_cancelled_declined_or_expire(): void
    {
        $this->makeAdmin();
        $offer = fn () => $this->postJson("/api/organizations/{$this->org}/transfer", ['user_id' => $this->admin->id, 'confirm' => 'Foggy Mountain'])->assertOk();
        $offer();
        $this->deleteJson("/api/organizations/{$this->org}/transfer")->assertOk();
        $this->assertNull(Organization::find($this->org)->transfer_to);

        $offer();
        $this->actingAs($this->admin);
        $this->postJson("/api/organizations/{$this->org}/transfer/decline")->assertOk();
        $this->assertSame($this->owner->id, Organization::find($this->org)->owner_id);

        $this->actingAs($this->owner);
        $offer();
        $this->travel(73)->hours();
        $this->actingAs($this->admin);
        $this->assertSame([], $this->getJson('/api/organizations')->json('incoming_transfers'));
        $this->postJson("/api/organizations/{$this->org}/transfer/accept")->assertStatus(410);
        $this->assertSame($this->owner->id, Organization::find($this->org)->owner_id);
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
        $this->postJson("/api/organizations/{$this->org}/transfer", ['user_id' => $this->admin->id, 'confirm' => 'Foggy Mountain'])->assertUnprocessable()->assertJsonPath('errors.user_id.0', 'Not on this plan.');
    }

    public function test_the_site_people_list_shows_organization_admins(): void
    {
        $this->makeAdmin();
        $this->assertSame(['admin@example.com'], collect($this->getJson('/api/sites/example.com/people')->json('organization_admins'))->pluck('email')->all());
    }

    public function test_the_migration_gives_everyone_a_personal_organization_holding_their_sites(): void
    {
        $legacy = User::create(['name' => 'Legacy Larry', 'email' => 'larry@example.com', 'password' => 'password']);
        $quiet = User::create(['name' => 'Quiet Quinn', 'email' => 'quinn@example.com', 'password' => 'password']);
        $site = Site::create(['domain' => 'legacy.com']);
        $site->members()->attach($legacy->id, ['role' => 'owner', 'id' => (string) Str::uuid()]);

        $migration = require database_path('migrations/2026_11_17_000001_create_organizations.php');
        DB::table('organization_user')->truncate();
        DB::table('organizations')->truncate();
        DB::table('sites')->update(['organization_id' => null]);
        $migration->down();
        $migration->up();

        $org = Organization::where('owner_id', $legacy->id)->first();
        $this->assertTrue($org->personal);
        $this->assertSame('Personal', $org->name);
        $this->assertSame($org->id, Site::where('domain', 'legacy.com')->value('organization_id'));
        $this->assertSame(1, Organization::where('owner_id', $quiet->id)->where('personal', true)->count());
        $this->assertSame(User::count(), Organization::where('personal', true)->count());
    }
}
