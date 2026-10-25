<?php

namespace App\Analytics\Ingest;

use App\Analytics\Support\DatacenterRanges;
use App\Analytics\Support\ReferrerSpam;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Decides whether a hit comes from a person's browser. Everything it looks at is already in the request and is used in memory only:
 * the user agent, a few headers, the address, the referrer. Which signal caught a hit is counted per day (see FilteredHits); nothing
 * that identifies the sender is stored.
 */
class BotFilter
{
    /**
     * @param  array{bot:bool}  $agent
     * @return string|null the reason the hit is left out (one of FilteredHits::REASONS), or null for a real visit
     */
    public static function verdict(Request $request, string $ua, array $agent, ?string $ip, ?string $referrer, string $visitorId): ?string
    {
        if ($agent['bot']) {
            return 'crawler';
        }
        // real browsers always say which languages they read, and headless Chrome announces itself in its client hints
        if (stripos((string) $request->header('Sec-CH-UA'), 'headless') !== false || trim((string) $request->header('Accept-Language')) === '') {
            return 'headless';
        }
        if (ReferrerSpam::matches($referrer)) {
            return 'referrer_spam';
        }
        if (DatacenterRanges::contains($ip)) {
            return 'datacenter';
        }
        if (self::flooding($visitorId)) {
            return 'flood';
        }

        return null;
    }

    /** More hits in a minute from one visitor than a person could cause. The count lives in the cache for that minute, under the visitor's daily anonymous id. */
    private static function flooding(string $visitorId): bool
    {
        $max = (int) config('tidemark.flood_per_minute', 120);
        if ($max < 1) {
            return false;
        }
        $key = 'tdm:flood:'.$visitorId;
        Cache::add($key, 0, 60);

        return (int) Cache::increment($key) > $max;
    }
}
