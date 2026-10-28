<?php

namespace App\Analytics\Ingest;

use App\Analytics\Support\ExchangeRates;
use App\Analytics\Support\FilteredHits;
use App\Analytics\Support\PiiMasks;
use App\Analytics\Support\PiiScrubber;
use App\Analytics\Support\Sources;
use App\Analytics\Support\UrlParams;
use App\Contracts\Entitlements;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Accepts Plausible's event payload — {n: name, u: url, d: domain, r: referrer, w: screen width, p: props, h: hash mode, i: interactive} —
 * so existing Plausible snippets work by changing only the script URL. An event sent with `i: 0` (or `interactive: false`) is not an
 * interaction: it does not count against a bounce (see DatabaseSink).
 */
class Collector
{
    public function __construct(private EventSink $sink, private Entitlements $entitlements) {}

    /** @return array{status:int, body:string} */
    public function handle(Request $request): array
    {
        $p = json_decode($request->getContent(), true);
        if (! is_array($p)) {
            $p = $request->all();
        }
        $name = trim((string) ($p['n'] ?? $p['name'] ?? ''));
        $url = (string) ($p['u'] ?? $p['url'] ?? '');
        $domain = (string) ($p['d'] ?? $p['domain'] ?? '');
        if ($name === '' || $url === '' || $domain === '') {
            return ['status' => 400, 'body' => 'Missing event name, url or domain'];
        }
        $parts = parse_url($url);
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return ['status' => 400, 'body' => 'Invalid url'];
        }

        $site = $this->site($domain);
        if (! $site) {
            return ['status' => 404, 'body' => 'Unknown site'];
        }

        // a visitor who asked not to be measured (Global Privacy Control, or Do Not Track) is not, when the site owner chose to honor it
        if ((($site['honor_gpc'] ?? false) && $request->header('Sec-GPC') === '1') || (($site['honor_dnt'] ?? false) && $request->header('DNT') === '1')) {
            return ['status' => 202, 'body' => 'ok'];
        }

        $ua = (string) $request->userAgent();
        $agent = UserAgent::parse($ua);
        $ip = Geo::clientIp($request);
        parse_str($parts['query'] ?? '', $q);
        $q = array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 255) : null, $q);
        $referrer = $q['ref'] ?? $q['source'] ?? $q['utm_source'] ?? null;
        $referrer = isset($p['r']) && $p['r'] ? (string) $p['r'] : ($referrer && str_contains($referrer, '.') ? $referrer : null);
        $visitorId = $this->visitorId($site['id'], $ip, $ua);
        // crawlers, scripts, data center traffic, referrer spam and floods are left out and only counted, unless the owner turned the filter off
        if (($site['bot_filter'] ?? true) && ! config('tidemark.disable_bot_filter') && ($why = BotFilter::verdict($request, $ua, $agent, $ip, $referrer, $visitorId))) {
            FilteredHits::record($site['id'], $why);

            return ['status' => 202, 'body' => 'ok'];
        }
        $hostname = strtolower($parts['host'] ?? '');
        $pathname = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        if (! empty($p['h']) && ! empty($parts['fragment'])) {
            $pathname .= '#'.$parts['fragment'];
        }
        $pathname = mb_substr($pathname, 0, 500);

        $geo = Geo::resolve($request, $ip);
        $precision = $site['location_precision'] ?? 'city'; // (a site cached before this setting existed has none)
        if ($precision !== 'city') {
            $geo['city'] = null;
            if ($precision === 'country') {
                $geo['region'] = null;
            }
        }
        if ($this->shielded($site, $ip, $geo['country'], $pathname, $hostname)) {
            return ['status' => 202, 'body' => 'ok'];
        }
        if (! $this->entitlements->allowsRecording($site)) {
            return ['status' => 202, 'body' => 'ok'];
        }

        $pathname = UrlParams::apply($pathname, $q, $site['url_params'] ?? []); // only what the owner allow-listed; none by default
        // personal data sites send by accident (an email in a URL, a token in a path) is masked before it is stored; only counts are kept
        $masked = [];
        $clean = function (?string $v) use ($site, &$masked) {
            return ($site['pii_scrub'] ?? true) && $v ? PiiScrubber::scrub($v, $masked) : $v;
        };
        $pathname = (string) $clean($pathname);
        $utm = ['source' => $q['utm_source'] ?? null, 'medium' => $q['utm_medium'] ?? null, 'campaign' => $q['utm_campaign'] ?? null, 'content' => $q['utm_content'] ?? null, 'term' => $q['utm_term'] ?? null];
        $utm = array_map($clean, $utm);
        $src = Sources::classify($referrer, $utm, $site['domain']);
        $src['referrer'] = $clean($src['referrer']);
        $revenue = $name === 'pageview' ? null : $this->revenue($p['revenue'] ?? $p['$'] ?? null, $site['currencies'][$name] ?? null);

        $this->sink->record([
            'site_id' => $site['id'],
            'visitor_id' => $visitorId,
            'at' => now()->format('Y-m-d H:i:s'),
            'name' => mb_substr((string) $clean($name), 0, 120),
            'pathname' => $pathname,
            'hostname' => mb_substr($hostname, 0, 100),
            'referrer' => ($site['referrer_domain_only'] ?? false) && $src['referrer'] ? strtok($src['referrer'], '/') : $src['referrer'], 'source' => $src['source'], 'channel' => $src['channel'], 'utm' => $utm,
            'country' => $geo['country'], 'region' => $geo['region'], 'city' => $geo['city'],
            'browser' => $agent['browser'], 'os' => $agent['os'],
            'device' => Sources::deviceFromWidth(isset($p['w']) ? (int) $p['w'] : null, $agent['mobile'], $agent['tablet']),
            'props' => $this->scrubProps($this->props($p['p'] ?? $p['props'] ?? null), $clean),
            'revenue' => $revenue,
            'interactive' => $name === 'pageview' || ! $this->declaredNotInteractive($p),
        ]);
        if ($masked) {
            PiiMasks::record($site['id'], $masked);
        }

        return ['status' => 202, 'body' => 'ok'];
    }

    /** @param array<string,mixed> $payload */
    private function declaredNotInteractive(array $payload): bool
    {
        $flag = $payload['i'] ?? $payload['interactive'] ?? true;

        return in_array($flag, [false, 0, '0', 'false'], true);
    }

    /** Anonymous visitor id: HMAC of ip + user agent + site, keyed by a salt that changes every day. Nothing is stored that could be reversed. */
    private function visitorId(string $siteId, ?string $ip, string $ua): string
    {
        $salt = (config('tidemark.salt') ?: config('app.key')).gmdate('Y-m-d');

        return substr(hash_hmac('sha256', $siteId.'|'.$ip.'|'.$ua, $salt), 0, 32);
    }

    /**
     * The revenue an event carries, `{amount, currency}` as Plausible sends it. When the event's goal reports in a currency, the
     * amount is also converted into it (null when no rate is known yet).
     *
     * @return array{amount:float,currency:string,reporting:?float}|null
     */
    private function revenue(mixed $raw, ?string $goalCurrency): ?array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw) || ! is_numeric($raw['amount'] ?? null)) {
            return null;
        }
        $currency = strtoupper(trim((string) ($raw['currency'] ?? '')));
        $amount = round((float) $raw['amount'], 4);
        if (! preg_match('/^[A-Z]{3}$/', $currency) || abs($amount) >= 1.0E+13) {
            return null;
        }
        $reporting = $goalCurrency ? ExchangeRates::convert($amount, $currency, $goalCurrency) : null;

        return ['amount' => $amount, 'currency' => $currency, 'reporting' => $reporting === null ? null : round($reporting, 4)];
    }

    /**
     * @param  array<string,string>  $props
     * @return array<string,string>
     */
    private function scrubProps(array $props, \Closure $clean): array
    {
        $out = [];
        foreach ($props as $k => $v) {
            $out[mb_substr((string) $clean((string) $k), 0, 120)] = mb_substr((string) $clean($v), 0, 500);
        }

        return $out;
    }

    /** @return array<string,string> */
    private function props(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $k => $v) {
            if (! is_scalar($v) || count($out) >= 30) {
                continue;
            }
            $out[mb_substr((string) $k, 0, 120)] = mb_substr(is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, 0, 500);
        }

        return $out;
    }

    /** @return array{id:string, domain:string, shields:array<string,array<int,string>>}|null */
    private function site(string $domain): ?array
    {
        foreach (array_filter(array_map('trim', explode(',', strtolower($domain)))) as $candidate) {
            $candidate = preg_replace('/^www\./', '', $candidate);
            $found = Cache::remember('tdm:site:'.$candidate, 60, function () use ($candidate) {
                $site = Site::real()->where('domain', $candidate)->first();

                return $site ? ['id' => $site->id, 'domain' => $site->domain, 'owner_id' => $site->members()->wherePivot('role', 'owner')->value('users.id'), 'honor_gpc' => (bool) $site->honor_gpc, 'honor_dnt' => (bool) $site->honor_dnt, 'location_precision' => $site->location_precision ?: 'city', 'url_params' => UrlParams::parse($site->url_params), 'referrer_domain_only' => (bool) $site->referrer_domain_only, 'pii_scrub' => Site::piiScrubEnforced() || (bool) ($site->pii_scrub ?? true), 'bot_filter' => (bool) ($site->bot_filter ?? true), 'shields' => $site->shields()->get()->groupBy('type')->map(fn ($g) => $g->pluck('value')->all())->all(), 'currencies' => Schema::hasColumn('goals', 'currency') ? $site->goals()->where('type', 'event')->whereNotNull('currency')->pluck('currency', 'value')->all() : []] : 0;
            });
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    private function shielded(array $site, ?string $ip, ?string $country, string $path, string $hostname): bool
    {
        $s = $site['shields'];
        $ignored = array_merge($s['ip'] ?? [], config('tidemark.ignore_ips'));
        foreach ($ignored as $rule) {
            if ($ip && ($rule === $ip || fnmatch($rule, $ip) || (str_contains($rule, '/') && self::inCidr($ip, $rule)))) {
                return true;
            }
        }
        if ($country && in_array($country, $s['country'] ?? [], true)) {
            return true;
        }
        foreach ($s['page'] ?? [] as $rule) {
            if (fnmatch(str_replace('**', '*', $rule), $path)) {
                return true;
            }
        }
        $allowed = array_merge($s['hostname'] ?? [], config('tidemark.allowed_hostnames'));

        return $allowed && ! array_filter($allowed, fn ($h) => fnmatch($h, $hostname));
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, 32);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        if ($bits % 8 === 0) {
            return true;
        }
        $mask = ~(0xFF >> ($bits % 8)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }
}
