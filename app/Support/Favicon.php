<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The icon a site shows in browser tabs, fetched by the server (so a dashboard viewer's browser never contacts the site) and
 * remembered. Only public https addresses are contacted, only small raster images are kept, and a site with no usable icon is
 * remembered as such so the dashboard falls back to its letter tile without asking again every page load.
 */
class Favicon
{
    private const MAX_BYTES = 65536;

    private const HIT_DAYS = 7;

    private const MISS_HOURS = 24;

    /** @return array{type:string, body:string}|null */
    public static function for(string $domain): ?array
    {
        $domain = strtolower(trim($domain));
        if (! config('tidemark.favicons') || ! preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            return null;
        }
        $key = 'favicon:'.$domain;
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached === false ? null : $cached;
        }
        $icon = self::discover($domain);
        if ($icon) {
            Cache::put($key, $icon, now()->addDays(self::HIT_DAYS));
        } else {
            Cache::put($key, false, now()->addHours(self::MISS_HOURS));
        }

        return $icon;
    }

    /** @return array{type:string, body:string}|null */
    private static function discover(string $domain): ?array
    {
        $candidates = [];
        $page = self::get("https://{$domain}/", 131072, html: true);
        if ($page && preg_match_all('/<link\b[^>]*>/i', $page['body'], $links)) {
            foreach ($links[0] as $tag) {
                if (preg_match('/\brel\s*=\s*["\']?([^"\'>]*)/i', $tag, $rel) && preg_match('/(^|\s)(shortcut\s+icon|icon|apple-touch-icon)(\s|$)/i', trim($rel[1]))
                    && preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $href)) {
                    $candidates[] = self::absolute(html_entity_decode($href[1]), $page['url']);
                }
            }
        }
        $candidates[] = "https://{$domain}/favicon.ico";
        foreach (array_slice(array_values(array_unique(array_filter($candidates))), 0, 4) as $url) {
            $res = self::get($url, self::MAX_BYTES);
            if ($res && ($type = self::imageType($res['body']))) {
                return ['type' => $type, 'body' => $res['body']];
            }
        }

        return null;
    }

    /** The fetched body and the address it finally came from, or null. Follows a few redirects, checking every hop. */
    private static function get(string $url, int $max, bool $html = false): ?array
    {
        for ($hop = 0; $hop < 4; $hop++) {
            $check = self::publicAddress($url);
            if (! $check) {
                return null;
            }
            try {
                $res = Http::timeout(5)->connectTimeout(3)->withoutRedirecting()->withHeaders(['User-Agent' => 'Tidemark-Favicon', 'Accept' => $html ? 'text/html' : 'image/*'])
                    ->withOptions([
                        'curl' => [CURLOPT_RESOLVE => ["{$check['host']}:{$check['port']}:{$check['ip']}"]],
                        // stop downloading once a response is clearly bigger than anything we would keep
                        'progress' => function ($total, $downloaded) use ($max) {
                            if ($downloaded > $max * 4) {
                                throw new \RuntimeException('Too large');
                            }
                        },
                    ])->get($url);
            } catch (\Throwable) {
                return null;
            }
            if ($res->redirect()) {
                $url = self::absolute((string) $res->header('Location'), $url) ?? '';

                continue;
            }
            if (! $res->successful()) {
                return null;
            }
            $body = substr($res->body(), 0, $max);

            return ['body' => $body, 'url' => $url];
        }

        return null;
    }

    /** @return array{host:string, port:int, ip:string}|null */
    private static function publicAddress(string $url): ?array
    {
        $p = parse_url($url);
        $host = strtolower((string) ($p['host'] ?? ''));
        if (($p['scheme'] ?? '') !== 'https' || $host === '' || isset($p['user']) || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }
        $ips = gethostbynamel($host) ?: [];
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }

        return $ips ? ['host' => $host, 'port' => (int) ($p['port'] ?? 443), 'ip' => $ips[0]] : null;
    }

    private static function absolute(string $href, string $base): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, 'data:')) {
            return null;
        }
        if (preg_match('#^https://#i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        if (! $b || empty($b['host'])) {
            return null;
        }
        $origin = 'https://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');
        if (str_starts_with($href, '//')) {
            return 'https:'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
            return null;
        }
        $dir = rtrim(dirname(($b['path'] ?? '/').'x'), '/');

        return $origin.$dir.'/'.$href;
    }

    /** The image type judged by the file's own first bytes, never by what the site claims; vector images are left out. */
    private static function imageType(string $body): ?string
    {
        return match (true) {
            strlen($body) < 16 => null,
            str_starts_with($body, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($body, "\x00\x00\x01\x00") => 'image/x-icon',
            str_starts_with($body, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($body, 'GIF8') => 'image/gif',
            str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }
}
