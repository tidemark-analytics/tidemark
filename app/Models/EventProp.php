<?php

namespace App\Models;

use App\Models\Concerns\UsesAnalyticsConnection;
use Illuminate\Database\Eloquent\Model;

class EventProp extends Model
{
    use UsesAnalyticsConnection;

    public $timestamps = false;

    protected $guarded = [];
}
