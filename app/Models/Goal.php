<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Goal extends Model
{
    use HasUuids;

    protected $fillable = ['site_id', 'type', 'value'];

    /** Name shown in the dashboard. */
    public function getNameAttribute(): string
    {
        return $this->type === 'page' ? 'Visit '.$this->value : $this->value;
    }

    protected $appends = ['name'];
}
