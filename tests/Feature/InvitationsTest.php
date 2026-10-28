<?php

namespace Tests\Feature;

use App\Mail\InviteMail;
use App\Models\Invitation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvitationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tidemark.invites' => true, 'mail.default' => 'smtp']);
        Mail::fake();
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
        $this->actingAs($this->owner);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->postJson('/api/sites', ['domain' => 'example.com'])->assertCreated();
    }

    private function invite(string $email = 'new@example.com', string $role = 'viewer')
    {
        return $this->postJson('/api/sites/example.com/people', ['email' => $email, 'role' => $role]);
    }

    private function token(): string
    {
        $token = null;
        Mail::assertSent(InviteMail::class, function (InviteMail $m) use (&$token) {
            $token = $m->invitation ? substr($m->invitation->url(), strpos($m->invitation->url(), '=') + 1) : $token;

            return true;
        });

        return $token;
    }

    public function test_unknown_address_gets_an_invitation_and_no_user_exists_yet(): void
    {
        $this->invite()->assertCreated()->assertJsonPath('invited', true);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
        $this->assertSame(1, Invitation::count());
        Mail::assertSent(InviteMail::class, fn ($m) => $m->hasTo('new@example.com') && $m->invitation !== null);
        $people = $this->getJson('/api/sites/example.com/people')->assertOk();
        $this->assertSame('new@example.com', $people->json('invites.0.email'));
        $this->assertTrue($people->json('invites_enabled'));
    }

    public function test_accepting_creates_a_verified_account_with_the_role_and_uses_the_link_once(): void
    {
        $this->invite('new@example.com', 'admin')->assertCreated();
        $token = $this->token();
        auth()->logout();
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/invites/{$token}")->assertOk()->assertJsonPath('site', 'example.com')->assertJsonPath('has_account', false)->assertJsonPath('inviter', 'Owner');
        $this->postJson("/api/invites/{$token}/accept", ['name' => 'New', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertOk()->assertJsonPath('site', 'example.com');

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame('admin', Site::where('domain', 'example.com')->first()->roleOf($user));
        $this->assertSame(0, Invitation::count());
        $this->getJson("/api/invites/{$token}")->assertStatus(410);
    }

    public function test_an_invitation_expires_after_72_hours(): void
    {
        $this->invite()->assertCreated();
        $token = $this->token();
        $this->travel(71)->hours();
        $this->getJson("/api/invites/{$token}")->assertOk();
        $this->travel(2)->hours();
        $this->getJson("/api/invites/{$token}")->assertStatus(410);
        $this->postJson("/api/invites/{$token}/accept", ['name' => 'N', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertStatus(410);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);

        $this->artisan('tidemark:prune-invitations')->assertSuccessful();
        $this->assertSame(0, Invitation::count());
    }

    public function test_existing_users_are_added_directly_and_told_by_mail(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password']);
        $this->invite('Other@Example.com')->assertCreated()->assertJsonMissing(['invited' => true]);
        $this->assertSame('viewer', Site::where('domain', 'example.com')->first()->roleOf($other));
        $this->assertSame(0, Invitation::count());
        Mail::assertSent(InviteMail::class, fn ($m) => $m->hasTo('other@example.com') && $m->invitation === null);
    }

    public function test_reinviting_replaces_the_old_link_and_revoking_removes_it(): void
    {
        $this->invite()->assertCreated();
        $first = $this->token();
        $this->invite('new@example.com', 'admin')->assertCreated();
        $this->assertSame(1, Invitation::count());
        $this->assertNull(Invitation::live($first));

        $id = Invitation::first()->id;
        $this->postJson("/api/sites/example.com/invites/{$id}/resend")->assertOk();
        $id = Invitation::first()->id;
        $this->deleteJson("/api/sites/example.com/invites/{$id}")->assertOk();
        $this->assertSame(0, Invitation::count());
    }

    public function test_off_by_default_or_without_mail_keeps_the_old_message(): void
    {
        config(['tidemark.invites' => false]);
        $this->invite()->assertUnprocessable()->assertJsonValidationErrors('email');
        config(['tidemark.invites' => true, 'mail.default' => 'log']);
        $this->invite()->assertUnprocessable();
        $this->assertSame(0, Invitation::count());
    }

    public function test_limits_and_a_registered_address_must_sign_in_to_accept(): void
    {
        config(['tidemark.invite_pending_limit' => 2]);
        $this->invite('a@example.com')->assertCreated();
        $this->invite('b@example.com')->assertCreated();
        $this->invite('c@example.com')->assertUnprocessable();

        config(['tidemark.invite_pending_limit' => 5]);
        $this->invite('a@example.com')->assertCreated(); // re-inviting replaces the old link
        $tok = $this->token();
        User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'password']);
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/invites/{$tok}/accept")->assertStatus(409);
    }
}
