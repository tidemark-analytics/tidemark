<?php

namespace App\Models;

use App\Http\Controllers\Api\AuthController;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A pending offer of access sent to an address that has no account yet. Expires on its own; the person is only created on acceptance. */
class Invitation extends Model
{
    use HasUuids;

    protected $fillable = ['email', 'token_hash', 'invited_by', 'site_id', 'role', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** Invites need the opt-in flag and a mail transport that can actually deliver them. */
    public static function enabled(): bool
    {
        return (bool) config('tidemark.invites') && AuthController::mailConfigured();
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
}
