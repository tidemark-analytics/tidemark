<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A WebAuthn credential. `public_key` is a PEM public key; the private half never leaves the person's device. */
class Passkey extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'name', 'credential_id', 'public_key', 'sign_count', 'last_used_at'];

    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
