<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person who manages an account together with its owner: admin on every site it owns, creates sites for it, manages its teams. */
class AccountAdmin extends Model
{
    use HasUuids;

    protected $fillable = ['owner_id', 'user_id'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
