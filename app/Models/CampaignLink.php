<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A short redirect link (`/l/abc`) or a tracking pixel (`/p/abc.gif`) for one site, with the UTM values it applies. */
class CampaignLink extends Model
{
    public const UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    protected $guarded = [];

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public static function newSlug(): string
    {
        do {
            $slug = Str::lower(Str::random(7));
        } while (self::where('slug', $slug)->exists());

        return $slug;
    }

    /** The destination with this link's UTM values added to its query (they replace any the address already had). */
    public function target(): string
    {
        $url = (string) $this->destination;
        $query = [];
        foreach (self::UTM as $key) {
            if ($this->{$key} !== null && $this->{$key} !== '') {
                $query[$key] = $this->{$key};
            }
        }
        if (! $query) {
            return $url;
        }
        $fragment = '';
        if (($pos = strpos($url, '#')) !== false) {
            $fragment = substr($url, $pos);
            $url = substr($url, 0, $pos);
        }
        $existing = [];
        if (($qpos = strpos($url, '?')) !== false) {
            parse_str(substr($url, $qpos + 1), $existing);
            $url = substr($url, 0, $qpos);
        }

        return $url.'?'.http_build_query(array_merge($existing, $query), '', '&', PHP_QUERY_RFC3986).$fragment;
    }

    public function countHit(): void
    {
        $day = now()->toDateString();
        $changed = DB::table('campaign_link_counts')->where('link_id', $this->id)->where('day', $day)->increment('hits');
        if (! $changed) {
            try {
                DB::table('campaign_link_counts')->insert(['link_id' => $this->id, 'day' => $day, 'hits' => 1]);
            } catch (\Throwable) { // two first hits of the day at once: the other one inserted the row
                DB::table('campaign_link_counts')->where('link_id', $this->id)->where('day', $day)->increment('hits');
            }
        }
    }
}
