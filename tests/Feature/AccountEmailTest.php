<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AccountEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
    }

    private function user(string $email = 'u@example.com'): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'password' => 'password']);
    }

    public function test_password_reset_needs_mail_to_be_configured_and_never_reveals_which_emails_exist(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->getJson('/api/config')->assertJsonPath('password_reset', false);
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        Notification::assertNothingSent(); // mailer is "log"/"array": nothing can be delivered

        config(['mail.default' => 'smtp']);
        $this->getJson('/api/config')->assertJsonPath('password_reset', true);
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = (new \ReflectionClass(ResetPassword::class))->getMethod('resetUrl')->invoke($n, $user);

            return str_contains($url, '/reset-password?token=') && str_contains($url, urlencode($user->email));
        });
        Notification::assertCount(1);
    }

    public function test_a_valid_token_changes_the_password_and_a_bad_one_does_not(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', ['token' => 'wrong', 'email' => $user->email, 'password' => 'newpassword1', 'password_confirmation' => 'newpassword1'])->assertUnprocessable();
        $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'newpassword1', 'password_confirmation' => 'newpassword1'])->assertOk();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'newpassword1'])->assertOk();
        $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'another-pass1', 'password_confirmation' => 'another-pass1'])->assertUnprocessable(); // single use
    }

    public function test_verification_is_off_by_default(): void
    {
        Notification::fake();
        config(['tidemark.registration' => true]);
        $this->postJson('/api/auth/register', ['name' => 'N', 'email' => 'n@example.com', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertCreated();
        Notification::assertNothingSent();
        $this->postJson('/api/sites', ['domain' => 'free.example.org'])->assertCreated();
    }

    public function test_when_enabled_new_accounts_must_verify_before_adding_sites(): void
    {
        Notification::fake();
        config(['tidemark.registration' => true, 'tidemark.verify_email' => true]);

        $this->postJson('/api/auth/register', ['name' => 'N', 'email' => 'n@example.com', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertCreated()
            ->assertJsonPath('user.verification_required', true)->assertJsonPath('user.email_verified', false);
        $user = User::where('email', 'n@example.com')->first();
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->postJson('/api/sites', ['domain' => 'wait.example.org'])->assertForbidden()->assertJsonPath('error', 'email_unverified');

        $this->postJson('/api/auth/verification-notification')->assertOk();
        Notification::assertSentToTimes($user, VerifyEmail::class, 2);

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($url)->assertRedirect('/sites?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->app['auth']->forgetGuards(); // the next request loads the user afresh, as in production
        $this->actingAs($user->fresh());
        $this->postJson('/api/sites', ['domain' => 'ready.example.org'])->assertCreated();
    }

    public function test_verification_links_must_be_signed_and_match_the_account(): void
    {
        $user = $this->user();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->get("/api/auth/verify/{$user->id}/".sha1($user->email))->assertForbidden(); // unsigned
        $wrong = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('someone-else@example.com')]);
        $this->get($wrong)->assertRedirect();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_accounts_made_by_an_administrator_are_already_verified(): void
    {
        $admin = $this->user('admin@example.com');
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->postJson('/api/admin/users', ['name' => 'Made', 'email' => 'made@example.com', 'password' => 'password1'])->assertCreated();
        $this->assertTrue(User::where('email', 'made@example.com')->first()->hasVerifiedEmail());
    }
}
