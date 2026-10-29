<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SecondFactor;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Account settings for the second sign-in step. The sign-in side lives in AuthController::login and the /auth/two-factor routes. */
class TwoFactorController extends Controller
{
    public function show(Request $request)
    {
        return $this->state($request->user());
    }

    // ---- authenticator app

    public function setup(Request $request)
    {
        $user = $request->user();
        abort_if($user->hasAuthenticator(), 422, 'An authenticator app is already set up.');
        $secret = Totp::secret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();

        return ['secret' => $secret, 'uri' => Totp::uri($secret, $user->email, config('app.name'))];
    }

    public function confirm(Request $request)
    {
        $data = $request->validate(['code' => 'required|string']);
        $user = $request->user();
        if (! $user->two_factor_secret || ! Totp::verify($user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Invalid authentication code.']);
        }
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $this->withRecoveryCodes($user);
    }

    public function disable(Request $request)
    {
        $user = $this->confirmPassword($request);
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        SecondFactor::pruneRecoveryCodes($user);

        return $this->state($user);
    }

    // ---- e-mailed codes

    public function emailSetup(Request $request)
    {
        abort_unless(AuthController::mailConfigured(), 422, 'This installation cannot send e-mail yet.');
        abort_if($request->user()->hasEmailTwoFactor(), 422, 'E-mail codes are already on.');
        SecondFactor::sendEmailCode($request->user());

        return ['ok' => true, 'email' => $request->user()->email];
    }

    public function emailConfirm(Request $request)
    {
        $data = $request->validate(['code' => 'required|string']);
        $user = $request->user();
        if (! SecondFactor::checkEmailCode($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is wrong or has expired.']);
        }
        $user->forceFill(['two_factor_email_confirmed_at' => now()])->save();

        return $this->withRecoveryCodes($user);
    }

    public function emailDisable(Request $request)
    {
        $user = $this->confirmPassword($request);
        $user->forceFill(['two_factor_email_confirmed_at' => null])->save();
        SecondFactor::pruneRecoveryCodes($user);

        return $this->state($user);
    }

    // ---- passkeys

    public function passkeyOptions(Request $request)
    {
        return SecondFactor::registrationOptions($request->user());
    }

    public function passkeyStore(Request $request)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:100',
            'clientDataJSON' => 'required|string',
            'attestationObject' => 'required|string',
        ]);
        $user = $request->user();
        try {
            SecondFactor::addPasskey($user, trim($data['name'] ?? '') ?: 'Passkey', $data);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['passkey' => $e->getMessage()]);
        }

        return $this->withRecoveryCodes($user);
    }

    public function passkeyDestroy(Request $request, string $id)
    {
        $user = $this->confirmPassword($request);
        $user->passkeys()->whereKey($id)->delete();
        SecondFactor::pruneRecoveryCodes($user);

        return $this->state($user);
    }

    // ---- recovery codes

    public function recoveryCodes(Request $request)
    {
        $user = $this->confirmPassword($request);
        abort_unless($user->hasTwoFactor(), 422, 'Turn on a second step first.');

        $codes = SecondFactor::regenerateRecoveryCodes($user);

        return $this->state($user) + ['recovery_codes' => $codes];
    }

    // ---- sign-in helpers (not signed in yet: the password stands in for the session)

    public function loginEmailCode(Request $request)
    {
        $user = $this->passwordUser($request);
        abort_unless($user->hasEmailTwoFactor() && AuthController::mailConfigured(), 422, 'E-mail codes are not on for this account.');
        SecondFactor::sendEmailCode($user);

        return ['ok' => true];
    }

    public function loginPasskeyOptions(Request $request)
    {
        $user = $this->passwordUser($request);
        abort_unless($user->passkeys()->exists(), 422, 'No passkeys are registered for this account.');

        return SecondFactor::loginOptions($user);
    }

    /** The settings state plus, the first time any method is turned on, the recovery codes (shown once). */
    private function withRecoveryCodes(User $user): array
    {
        $codes = SecondFactor::ensureRecoveryCodes($user);

        return $this->state($user) + ['recovery_codes' => $codes];
    }

    private function state(User $user): array
    {
        $user->unsetRelation('passkeys');

        return [
            'authenticator' => $user->hasAuthenticator(),
            'email' => $user->hasEmailTwoFactor(),
            'mail' => AuthController::mailConfigured(),
            'passkeys' => $user->passkeys()->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'created_at' => $p->created_at, 'last_used_at' => $p->last_used_at])->values(),
            'recovery_left' => SecondFactor::recoveryLeft($user),
            'two_factor' => $user->hasTwoFactor(),
        ];
    }

    private function confirmPassword(Request $request): User
    {
        $data = $request->validate(['password' => 'required|string']);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Password is incorrect.']);
        }

        return $request->user();
    }

    private function passwordUser(Request $request): User
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $key = 'login:'.strtolower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 8)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again in a minute.']);
        }
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        return $user;
    }
}
