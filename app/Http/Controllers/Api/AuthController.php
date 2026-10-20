<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\User;
use App\Support\Totp;
use App\Updates\Updater;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string', 'code' => 'nullable|string']);
        $key = 'login:'.strtolower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 8)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again in a minute.']);
        }

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        if ($user->hasTwoFactor()) {
            if (empty($data['code'])) {
                return response()->json(['two_factor' => true], 202);
            }
            if (! Totp::verify($user->two_factor_secret, $data['code'])) {
                RateLimiter::hit($key, 60);
                throw ValidationException::withMessages(['code' => 'Invalid authentication code.']);
            }
        }
        RateLimiter::clear($key);
        Auth::guard('web')->login($user, (bool) $request->boolean('remember'));
        $request->session()->regenerate();

        return ['user' => $this->present($user)];
    }

    public function register(Request $request)
    {
        abort_unless(config('tidemark.registration'), 403, 'Registration is disabled.');
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $user = User::create($data);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $this->present($user)], 201);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ['ok' => true];
    }

    public function me(Request $request)
    {
        $user = $this->present($request->user());
        if ($user['is_admin']) {
            $updater = app(Updater::class);
            $user['update'] = $updater->availableUpdate();
            // refresh the daily check after the response has been sent, so it never slows the page down
            app()->terminating(fn () => $updater->refreshIfStale());
        }

        return ['user' => $user, 'config' => [
            'mode' => config('tidemark.mode'),
            'script_base' => url('/js'),
            'event_url' => url('/api/event'),
        ]];
    }

    public function config()
    {
        return ['registration' => (bool) config('tidemark.registration'), 'mode' => config('tidemark.mode'), 'name' => config('app.name')];
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'email' => 'sometimes|email|max:255|unique:users,email,'.$request->user()->id.',id',
            'timezone' => ['sometimes', 'string', fn ($a, $v, $fail) => in_array($v, \DateTimeZone::listIdentifiers(), true) || $fail('Unknown timezone')],
            'locale' => 'sometimes|string|max:12',
        ]);
        $request->user()->update($data);

        return ['user' => $this->present($request->user()->fresh())];
    }

    public function password(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|string', 'password' => ['required', 'confirmed', Password::min(8)]]);
        if (! Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'Current password is incorrect.']);
        }
        $request->user()->update(['password' => $data['password']]);

        return ['ok' => true];
    }

    // ---- two-factor

    public function twoFactorSetup(Request $request)
    {
        $user = $request->user();
        abort_if($user->hasTwoFactor(), 422, 'Two-factor authentication is already enabled.');
        $secret = Totp::secret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();

        return ['secret' => $secret, 'uri' => Totp::uri($secret, $user->email, config('app.name'))];
    }

    public function twoFactorConfirm(Request $request)
    {
        $data = $request->validate(['code' => 'required|string']);
        $user = $request->user();
        if (! $user->two_factor_secret || ! Totp::verify($user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Invalid authentication code.']);
        }
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return ['ok' => true];
    }

    public function twoFactorDisable(Request $request)
    {
        $data = $request->validate(['password' => 'required|string']);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Password is incorrect.']);
        }
        $request->user()->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

        return ['ok' => true];
    }

    // ---- API keys

    public function keys(Request $request)
    {
        return ['data' => $request->user()->apiKeys()->latest()->get()];
    }

    public function createKey(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100']);
        [$key, $plain] = ApiKey::issue($request->user(), $data['name']);

        return response()->json(['data' => $key, 'token' => $plain], 201);
    }

    public function deleteKey(Request $request, string $id)
    {
        $request->user()->apiKeys()->whereKey($id)->delete();

        return ['ok' => true];
    }

    private function present(User $u): array
    {
        return [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'is_admin' => $u->is_admin,
            'timezone' => $u->timezone, 'locale' => $u->locale, 'two_factor' => $u->hasTwoFactor(),
        ];
    }
}
