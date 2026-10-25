<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One person's opt-in to the weekly or monthly e-mail report of one site. */
class ReportSubscription extends Model
{
    use HasUuids;

    public const FREQUENCIES = ['weekly', 'monthly'];

    protected $fillable = ['user_id', 'site_id', 'frequency', 'last_period_end', 'token'];

    protected static function booted(): void
    {
        static::creating(fn (self $s) => $s->token ??= Str::random(40));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
