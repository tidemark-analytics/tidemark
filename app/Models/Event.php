<?php

namespace App\Models;

use App\Models\Concerns\UsesAnalyticsConnection;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use UsesAnalyticsConnection;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
