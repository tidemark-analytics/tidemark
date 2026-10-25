<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Where alerts are delivered besides e-mail: a signed JSON endpoint, or a Slack or Discord channel. */
class AlertWebhook extends Model
{
    use HasUuids;

    public const MAX_PER_SITE = 10;

    public const KINDS = ['generic', 'slack', 'discord'];

    protected $fillable = ['site_id', 'name', 'kind', 'url', 'secret', 'last_status', 'last_error', 'last_at'];

    protected $hidden = ['url', 'secret'];

    protected function casts(): array
    {
        return ['url' => 'encrypted', 'secret' => 'encrypted', 'last_at' => 'datetime'];
    }

    /** The address without the part that acts as a password (chat webhooks keep their token in the path). */
    public function displayUrl(): string
    {
        $p = parse_url($this->url) ?: [];

        return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : '').(strlen($p['path'] ?? '') > 1 ? '/…' : '');
    }
}
