<?php

namespace App\Models;

use App\Http\Controllers\Api\AuthController;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** A pending offer of access sent to an address that has no account yet. Expires on its own; the person is only created on acceptance. */
class Invitation extends Model
{
    use HasUuids;

    protected $fillable = ['email', 'token_hash', 'invited_by', 'site_id', 'team_id', 'account_id', 'role', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** Invites need the opt-in flag and a mail transport that can actually deliver them. */
    public static function enabled(): bool
    {
        return (bool) config('tidemark.invites') && AuthController::mailConfigured();
    }

    /** Invitations send mail from this install, so they are capped per person per day and in total. */
    public static function guardAbuse(User $inviter): void
    {
        if (config('tidemark.verify_email') && ! $inviter->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['email' => 'Confirm your own e-mail address before inviting people.']);
        }
        if (RateLimiter::tooManyAttempts('invite:'.$inviter->id, (int) config('tidemark.invite_daily_limit'))) {
            throw ValidationException::withMessages(['email' => 'You have sent a lot of invitations today. Try again tomorrow.']);
        }
        if (static::where('invited_by', $inviter->id)->where('expires_at', '>', now())->count() >= (int) config('tidemark.invite_pending_limit')) {
            throw ValidationException::withMessages(['email' => 'Too many invitations are waiting to be accepted. Revoke some first.']);
        }
    }

    public static function countSent(User $inviter): void
    {
        RateLimiter::hit('invite:'.$inviter->id, 86400);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Creates (or replaces) the one live invitation for this address and site; returns it with the link token in ->plainToken. */
    public static function issue(string $email, User $inviter, Site $site, string $role): self
    {
        static::where('email', $email)->where('site_id', $site->id)->delete();
        $token = Str::random(40);
        $invite = static::create([
            'email' => $email, 'token_hash' => static::hash($token), 'invited_by' => $inviter->id,
            'site_id' => $site->id, 'role' => $role, 'expires_at' => now()->addHours((int) config('tidemark.invite_hours')),
        ]);
        $invite->plainToken = $token;

        return $invite;
    }

    /** Same, for joining a team (the team's sites follow from it). */
    public static function issueForTeam(string $email, User $inviter, Team $team): self
    {
        static::where('email', $email)->where('team_id', $team->id)->delete();
        $token = Str::random(40);
        $invite = static::create([
            'email' => $email, 'token_hash' => static::hash($token), 'invited_by' => $inviter->id,
            'team_id' => $team->id, 'role' => 'viewer', 'expires_at' => now()->addHours((int) config('tidemark.invite_hours')),
        ]);
        $invite->plainToken = $token;

        return $invite;
    }

    /** Same, for helping to manage an account (the inviter's own). */
    public static function issueForAccount(string $email, User $inviter): self
    {
        static::where('email', $email)->where('account_id', $inviter->id)->delete();
        $token = Str::random(40);
        $invite = static::create([
            'email' => $email, 'token_hash' => static::hash($token), 'invited_by' => $inviter->id,
            'account_id' => $inviter->id, 'role' => 'admin', 'expires_at' => now()->addHours((int) config('tidemark.invite_hours')),
        ]);
        $invite->plainToken = $token;

        return $invite;
    }

    public ?string $plainToken = null;

    /** The live invitation for a link token, or null when it is unknown or past its 72 hours. */
    public static function live(string $token): ?self
    {
        $invite = static::where('token_hash', static::hash($token))->first();

        return $invite && $invite->expires_at->isFuture() ? $invite : null;
    }

    public function url(): string
    {
        return url('/invite?token='.$this->plainToken);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
