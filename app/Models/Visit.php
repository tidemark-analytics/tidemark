<?php

namespace App\Models;

use App\Models\Concerns\UsesAnalyticsConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Visit extends Model
{
    use HasUuids, UsesAnalyticsConnection;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'is_bounce' => 'boolean'];
    }
}
