<?php

namespace App\Analytics\Support;

use Illuminate\Support\Facades\DB;

/** The counters behind "masked in the last 30 days": how many values, by kind of pattern, per site and day. */
class PiiMasks
{
    /** @param  array<string, int>  $found */
    public static function record(string $siteId, array $found): void
    {
        $day = gmdate('Y-m-d');
        foreach ($found as $pattern => $n) {
            if ($n < 1 || ! in_array($pattern, PiiScrubber::PATTERNS, true)) {
                continue;
            }
            DB::table('pii_masks')->insertOrIgnore(['site_id' => $siteId, 'day' => $day, 'pattern' => $pattern, 'count' => 0]);
            DB::table('pii_masks')->where(['site_id' => $siteId, 'day' => $day, 'pattern' => $pattern])->increment('count', $n);
        }
    }

    /** @return array<int, array{pattern: string, count: int}> */
    public static function recent(string $siteId, int $days = 30): array
    {
        return DB::table('pii_masks')->where('site_id', $siteId)->where('day', '>=', gmdate('Y-m-d', strtotime("-{$days} days")))
            ->groupBy('pattern')->selectRaw('pattern, SUM(count) as total')->orderByDesc('total')->get()
            ->map(fn ($r) => ['pattern' => $r->pattern, 'count' => (int) $r->total])->all();
    }
}
