<?php

namespace App\Analytics\Ingest;

use GeoIp2\Database\Reader;
use Illuminate\Http\Request;

/** Resolves country/region/city from CDN headers, falling back to a local MaxMind database if installed. */
class Geo
{
    /** @return array{country:?string, region:?string, city:?string} */
    public static function resolve(Request $request, ?string $ip): array
    {
        $h = fn (string $name) => $request->headers->get($name);

        $country = $h('CF-IPCountry') ?: $h('X-Vercel-IP-Country') ?: $h('CloudFront-Viewer-Country') ?: $h('X-Country-Code');
        $region = $h('CF-Region-Code') ?: $h('X-Vercel-IP-Country-Region') ?: $h('CloudFront-Viewer-Country-Region');
        $city = $h('CF-IPCity') ?: $h('X-Vercel-IP-City');
        if ($city) {
            $city = rawurldecode($city);
        }

        if (! $country && $ip && class_exists(Reader::class)) {
            $db = config('tidemark.geoip_database');
            if ($db && is_file($db)) {
                try {
                    static $reader = null;
                    $reader ??= new Reader($db);
                    $rec = $reader->city($ip);
                    $country = $rec->country->isoCode;
                    $sub = $rec->subdivisions[0] ?? null;
                    $region = $sub ? ($rec->country->isoCode.'-'.$sub->isoCode) : null;
                    $city = $rec->city->name;
                } catch (\Throwable) {
                    // address not in database
                }
            }
        }

        $country = $country ? strtoupper(substr($country, 0, 2)) : null;
        if ($country && ! preg_match('/^[A-Z]{2}$/', $country) || $country === 'XX' || $country === 'T1') {
            $country = null;
        }
        if ($region && $country && ! str_contains($region, '-')) {
            $region = $country.'-'.$region;
        }

        return ['country' => $country, 'region' => $region ? substr($region, 0, 20) : null, 'city' => $city ? substr($city, 0, 100) : null];
    }

    public static function clientIp(Request $request): ?string
    {
        foreach (config('tidemark.ip_headers') as $header) {
            $value = $request->headers->get($header);
            if ($value) {
                $ip = trim(explode(',', $value)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $request->ip();
    }
}
