<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Tell me when…": one condition on a site and where to send it. */
class AlertRule extends Model
{
    use HasUuids;

    public const MAX_PER_SITE = 20;

    public const TYPES = ['traffic_spike', 'traffic_drop', 'goal_milestone', 'goal_conversions', 'new_top_referrer'];

    protected $fillable = ['site_id', 'type', 'config', 'email', 'webhook_ids', 'quiet_start', 'quiet_end', 'enabled', 'state', 'last_fired_at'];

    protected function casts(): array
    {
        return ['config' => 'array', 'webhook_ids' => 'array', 'state' => 'array', 'email' => 'boolean', 'enabled' => 'boolean', 'last_fired_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
