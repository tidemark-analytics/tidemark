<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An ordered list of goals a visitor is expected to complete one after another. */
class Funnel extends Model
{
    use HasUuids;

    public const MIN_STEPS = 2;

    public const MAX_STEPS = 8;

    protected $fillable = ['site_id', 'name'];

    public function steps(): HasMany
    {
        return $this->hasMany(FunnelStep::class)->orderBy('position');
    }
}
