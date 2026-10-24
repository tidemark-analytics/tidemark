<?php

namespace App\Analytics\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Converts revenue sent in one currency into the currency a goal reports in. Rates are the European Central Bank's daily
 * reference rates, fetched by `tidemark:refresh-rates` (only on installs with a revenue goal) and cached; nothing is looked up
 * while events are recorded.
 */
class ExchangeRates
{
    private const KEY = 'tdm:exchange-rates';

    private const FEED = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    /** @return float|null the amount in `$to`, or null when a rate is unknown */
    public static function convert(float $amount, string $from, string $to): ?float
    {
        if ($from === $to) {
            return $amount;
        }
        $rates = Cache::get(self::KEY, []) + ['EUR' => 1.0];
        if (empty($rates[$from]) || empty($rates[$to])) {
            return null;
        }

        return $amount / $rates[$from] * $rates[$to];
    }

    /** @return array<string,float> units of each currency per euro */
    public static function rates(): array
    {
        return Cache::get(self::KEY, []);
    }

    /** Downloads the latest rates. Returns how many currencies were read, or 0 when the feed could not be used. */
    public static function refresh(): int
    {
        try {
            $xml = Http::timeout(10)->get(self::FEED)->throw()->body();
        } catch (\Throwable) {
            return 0;
        }

        return self::store($xml);
    }

    public static function store(string $xml): int
    {
        preg_match_all('/<Cube\s+currency=[\'"]([A-Z]{3})[\'"]\s+rate=[\'"]([0-9.]+)[\'"]/', $xml, $m, PREG_SET_ORDER);
        $rates = [];
        foreach ($m as [, $currency, $rate]) {
            if ((float) $rate > 0) {
                $rates[$currency] = (float) $rate;
            }
        }
        if ($rates) {
            Cache::put(self::KEY, $rates, now()->addDays(4));
        }

        return count($rates);
    }
}
