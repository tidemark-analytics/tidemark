<?php

namespace App\Support;

use App\Http\Controllers\Api\AuthController;
use App\Mail\TwoFactorCodeMail;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** The second sign-in step: authenticator codes, e-mailed codes, passkeys and one-off recovery codes. */
class SecondFactor
{
    public const EMAIL_MINUTES = 10;

    private const EMAIL_TRIES = 5;

    private const RECOVERY_CODES = 10;

    /** Methods this person can use right now, in the order the sign-in page offers them. */
    public static function methods(User $user): array
    {
        $methods = [];
        if ($user->passkeys()->exists()) {
            $methods[] = 'passkey';
        }
        if ($user->hasAuthenticator()) {
            $methods[] = 'totp';
        }
        if ($user->hasEmailTwoFactor() && AuthController::mailConfigured()) {
            $methods[] = 'email';
        }
        if ($methods) {
            $methods[] = 'recovery';
        }

        return $methods;
    }

    /** Checks the second step of a sign-in request: `method` picks how, `code` or `passkey` carries the proof. */
    public static function verify(User $user, ?string $method, ?string $code, ?array $passkey): bool
    {
        $method = $method ?: 'totp';
        if (! in_array($method, self::methods($user), true)) {
            return false;
        }

        return match ($method) {
            'totp' => $code !== null && $code !== '' && Totp::verify($user->two_factor_secret, $code),
            'email' => $code !== null && self::checkEmailCode($user, $code),
            'recovery' => $code !== null && self::useRecoveryCode($user, $code),
            'passkey' => $passkey !== null && self::checkPasskey($user, $passkey),
            default => false,
        };
    }

    // ---- e-mailed codes

    public static function sendEmailCode(User $user): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put(self::emailKey($user), ['hash' => self::hashCode($code), 'tries' => 0], now()->addMinutes(self::EMAIL_MINUTES));
        Mail::to($user->email)->send(new TwoFactorCodeMail($code, self::EMAIL_MINUTES));
    }

    public static function checkEmailCode(User $user, string $code): bool
    {
        $pending = Cache::get(self::emailKey($user));
        if (! $pending) {
            return false;
        }
        if ($pending['tries'] >= self::EMAIL_TRIES) {
            Cache::forget(self::emailKey($user));

            return false;
        }
        if (! hash_equals($pending['hash'], self::hashCode(preg_replace('/\s+/', '', $code)))) {
            $pending['tries']++;
            Cache::put(self::emailKey($user), $pending, now()->addMinutes(self::EMAIL_MINUTES));

            return false;
        }
        Cache::forget(self::emailKey($user));

        return true;
    }

    private static function emailKey(User $user): string
    {
        return '2fa-email:'.$user->id;
    }

    private static function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    // ---- recovery codes

    /** Makes a fresh set when the person has none yet; returns the codes in plain text (shown once), or null when they already had some. */
    public static function ensureRecoveryCodes(User $user): ?array
    {
        return $user->two_factor_recovery_codes ? null : self::regenerateRecoveryCodes($user);
    }

    public static function regenerateRecoveryCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $codes[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => self::hashRecovery($c), $codes)])->save();

        return $codes;
    }

    public static function useRecoveryCode(User $user, string $code): bool
    {
        $hash = self::hashRecovery($code);
        $left = $user->two_factor_recovery_codes ?? [];
        $index = array_search($hash, $left, true);
        if ($index === false) {
            return false;
        }
        unset($left[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($left)])->save();

        return true;
    }

    public static function recoveryLeft(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    private static function hashRecovery(string $code): string
    {
        return hash('sha256', Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $code)));
    }

    /** Recovery codes only make sense while some method is on. */
    public static function pruneRecoveryCodes(User $user): void
    {
        if (! $user->hasTwoFactor() && $user->two_factor_recovery_codes) {
            $user->forceFill(['two_factor_recovery_codes' => null])->save();
        }
    }

    // ---- passkeys

    public static function registrationOptions(User $user): array
    {
        $challenge = WebAuthn::challenge();
        Cache::put(self::passkeyKey('reg', $user), $challenge, now()->addMinutes(5));

        return [
            'challenge' => $challenge,
            'rp' => ['name' => config('app.name'), 'id' => WebAuthn::rpId()],
            'user' => ['id' => WebAuthn::b64uEncode($user->id), 'name' => $user->email, 'displayName' => $user->name],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'timeout' => 60000,
            'attestation' => 'none',
            'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'preferred'],
            'excludeCredentials' => $user->passkeys->map(fn ($p) => ['type' => 'public-key', 'id' => $p->credential_id])->values(),
        ];
    }

    /** @throws InvalidArgumentException when the response does not check out */
    public static function addPasskey(User $user, string $name, array $response): Passkey
    {
        $challenge = Cache::pull(self::passkeyKey('reg', $user));
        if (! $challenge) {
            throw new InvalidArgumentException('That request expired. Try again.');
        }
        $credential = WebAuthn::register(
            WebAuthn::b64uDecode((string) ($response['clientDataJSON'] ?? '')),
            WebAuthn::b64uDecode((string) ($response['attestationObject'] ?? '')),
            $challenge,
        );
        if (Passkey::where('credential_id', $credential['credential_id'])->exists()) {
            throw new InvalidArgumentException('This passkey is already registered.');
        }

        return $user->passkeys()->create($credential + ['name' => $name]);
    }

    public static function loginOptions(User $user): array
    {
        $challenge = WebAuthn::challenge();
        Cache::put(self::passkeyKey('auth', $user), $challenge, now()->addMinutes(5));

        return [
            'challenge' => $challenge,
            'rpId' => WebAuthn::rpId(),
            'timeout' => 60000,
            'userVerification' => 'preferred',
            'allowCredentials' => $user->passkeys->map(fn ($p) => ['type' => 'public-key', 'id' => $p->credential_id])->values(),
        ];
    }

    private static function checkPasskey(User $user, array $response): bool
    {
        $challenge = Cache::pull(self::passkeyKey('auth', $user));
        $passkey = $user->passkeys()->where('credential_id', (string) ($response['id'] ?? ''))->first();
        if (! $challenge || ! $passkey) {
            return false;
        }
        try {
            $count = WebAuthn::assert(
                WebAuthn::b64uDecode((string) ($response['clientDataJSON'] ?? '')),
                WebAuthn::b64uDecode((string) ($response['authenticatorData'] ?? '')),
                WebAuthn::b64uDecode((string) ($response['signature'] ?? '')),
                $challenge,
                $passkey->public_key,
                (int) $passkey->sign_count,
            );
        } catch (InvalidArgumentException) {
            return false;
        }
        $passkey->forceFill(['sign_count' => $count, 'last_used_at' => now()])->save();

        return true;
    }

    private static function passkeyKey(string $kind, User $user): string
    {
        return "passkey:$kind:".$user->id;
    }
}
