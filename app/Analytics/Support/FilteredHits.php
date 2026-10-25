<?php

namespace App\Analytics\Support;

use Illuminate\Support\Facades\DB;

/** How many hits were left out as bot or spam traffic, by reason, per site and day. Only counts are kept: no addresses, no user agents. */
class FilteredHits
{
    public const REASONS = ['crawler', 'headless', 'referrer_spam', 'datacenter', 'flood'];

    public static function record(string $siteId, string $reason): void
    {
        if (! in_array($reason, self::REASONS, true)) {
            return;
        }
        $day = gmdate('Y-m-d');
        DB::table('filtered_hits')->insertOrIgnore(['site_id' => $siteId, 'day' => $day, 'reason' => $reason, 'count' => 0]);
        DB::table('filtered_hits')->where(['site_id' => $siteId, 'day' => $day, 'reason' => $reason])->increment('count');
    }

    /** @return array<int, array{reason: string, count: int}> */
    public static function recent(string $siteId, int $days = 30): array
    {
        return DB::table('filtered_hits')->where('site_id', $siteId)->where('day', '>=', gmdate('Y-m-d', strtotime("-{$days} days")))
            ->groupBy('reason')->selectRaw('reason, SUM(count) as total')->orderByDesc('total')->get()
            ->map(fn ($r) => ['reason' => $r->reason, 'count' => (int) $r->total])->all();
    }
}
