<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ShieldRule extends Model
{
    use HasUuids;

    protected $fillable = ['site_id', 'type', 'value'];
}
