<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FunnelStep extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['funnel_id', 'goal_id', 'position'];

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }
}
