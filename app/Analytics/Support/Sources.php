<?php

namespace App\Analytics\Support;

/** Turns a referrer + UTM tags into Plausible-style `source` and `channel` values. */
class Sources
{
    /** [host regex, display name, category] — first match wins. */
    private const RULES = [
        ['/(^|\.)google\.[a-z.]+$/', 'Google', 'search'], ['/(^|\.)bing\.com$/', 'Bing', 'search'], ['/(^|\.)duckduckgo\.com$/', 'DuckDuckGo', 'search'],
        ['/(^|\.)yahoo\.[a-z.]+$/', 'Yahoo!', 'search'], ['/(^|\.)yandex\.[a-z.]+$/', 'Yandex', 'search'], ['/(^|\.)baidu\.com$/', 'Baidu', 'search'],
        ['/(^|\.)ecosia\.org$/', 'Ecosia', 'search'], ['/(^|\.)brave\.com$/', 'Brave', 'search'], ['/(^|\.)startpage\.com$/', 'Startpage', 'search'],
        ['/(^|\.)kagi\.com$/', 'Kagi', 'search'], ['/(^|\.)qwant\.com$/', 'Qwant', 'search'], ['/(^|\.)naver\.com$/', 'Naver', 'search'], ['/(^|\.)seznam\.cz$/', 'Seznam', 'search'],
        ['/^gemini\.google\.com$/', 'Google Gemini', 'referral'], ['/(^|\.)(chatgpt\.com|chat\.openai\.com)$/', 'ChatGPT', 'referral'],
        ['/(^|\.)perplexity\.ai$/', 'Perplexity', 'referral'], ['/(^|\.)claude\.ai$/', 'Claude', 'referral'],
        ['/(^|\.)(facebook\.com|fb\.com|fb\.me)$/', 'Facebook', 'social'], ['/(^|\.)instagram\.com$/', 'Instagram', 'social'],
        ['/(^|\.)(twitter\.com|x\.com|t\.co)$/', 'Twitter', 'social'], ['/(^|\.)(linkedin\.com|lnkd\.in)$/', 'LinkedIn', 'social'],
        ['/(^|\.)reddit\.com$/', 'Reddit', 'social'], ['/(^|\.)pinterest\.[a-z.]+$/', 'Pinterest', 'social'], ['/(^|\.)tiktok\.com$/', 'TikTok', 'social'],
        ['/^news\.ycombinator\.com$/', 'Hacker News', 'social'], ['/(^|\.)(bsky\.app|bsky\.social)$/', 'Bluesky', 'social'], ['/(^|\.)threads\.net$/', 'Threads', 'social'],
        ['/(^|\.)quora\.com$/', 'Quora', 'social'], ['/(^|\.)tumblr\.com$/', 'Tumblr', 'social'], ['/(^|\.)vk\.com$/', 'VK', 'social'], ['/(^|\.)lobste\.rs$/', 'Lobsters', 'social'],
        ['/(^|\.)(youtube\.com|youtu\.be)$/', 'YouTube', 'video'], ['/(^|\.)vimeo\.com$/', 'Vimeo', 'video'], ['/(^|\.)twitch\.tv$/', 'Twitch', 'video'],
        ['/(^|\.)github\.com$/', 'GitHub', 'referral'],
        ['/^(mail\.google\.com|outlook\.live\.com|outlook\.office\.com|mail\.yahoo\.com|mail\.proton\.me)$/', 'Email', 'email'],
    ];

    private const SEARCH_NAMES = ['google', 'bing', 'duckduckgo', 'yahoo', 'yandex', 'baidu', 'ecosia', 'brave', 'startpage', 'kagi', 'qwant', 'naver', 'seznam'];

    private const SOCIAL_NAMES = ['facebook', 'instagram', 'twitter', 'x', 'linkedin', 'reddit', 'pinterest', 'tiktok', 'hacker news', 'mastodon', 'bluesky', 'threads', 'quora', 'tumblr', 'vk'];

    /**
     * @param  array<string,?string>  $utm  keys: source, medium, campaign, content, term
     * @return array{source:?string, channel:string, referrer:?string}
     */
    public static function classify(?string $referrer, array $utm, string $siteHost): array
    {
        $host = null;
        $ref = null;
        if ($referrer) {
            $parts = parse_url(str_contains($referrer, '://') ? $referrer : 'https://'.$referrer);
            $host = isset($parts['host']) ? preg_replace('/^www\./', '', strtolower($parts['host'])) : null;
            $ref = $host ? mb_substr($host.rtrim($parts['path'] ?? '', '/'), 0, 500) : null;
            $site = preg_replace('/^www\./', '', strtolower($siteHost));
            if ($host && ($host === $site || str_ends_with($host, '.'.$site))) {
                $host = $ref = null; // internal navigation
            }
        }

        $name = $category = null;
        if ($host) {
            foreach (self::RULES as [$re, $n, $cat]) {
                if (preg_match($re, $host)) {
                    [$name, $category] = [$n, $cat];
                    break;
                }
            }
            $name ??= $host;
            $category ??= 'referral';
        }

        $medium = strtolower(trim((string) ($utm['medium'] ?? '')));
        $utmSource = trim((string) ($utm['source'] ?? ''));
        $source = $utmSource !== '' ? self::titleCase($utmSource) : $name;
        if ($utmSource !== '') {
            $category = self::categoryOf(strtolower($utmSource)) ?? $category ?? 'referral';
        }

        $channel = match (true) {
            in_array($medium, ['cpc', 'ppc', 'paid', 'paidsearch', 'paid-search', 'sem'], true) => $category === 'social' ? 'Paid Social' : ($category === 'search' || $category === null ? 'Paid Search' : 'Paid Other'),
            in_array($medium, ['paid-social', 'paidsocial', 'social-paid', 'paid_social'], true) => 'Paid Social',
            in_array($medium, ['email', 'e-mail', 'newsletter'], true) || $category === 'email' => 'Email',
            in_array($medium, ['display', 'banner', 'cpm'], true) => 'Display',
            $medium === 'affiliate' => 'Affiliates',
            in_array($medium, ['social', 'social-network', 'sm'], true) => 'Organic Social',
            in_array($medium, ['organic'], true) && $category === 'search' => 'Organic Search',
            $category === 'search' => 'Organic Search',
            $category === 'social' => 'Organic Social',
            $category === 'video' => 'Organic Video',
            $source !== null => 'Referral',
            default => 'Direct',
        };

        return ['source' => $source, 'channel' => $channel, 'referrer' => $ref];
    }

    private static function categoryOf(string $name): ?string
    {
        return in_array($name, self::SEARCH_NAMES, true) ? 'search' : (in_array($name, self::SOCIAL_NAMES, true) ? 'social' : null);
    }

    private static function titleCase(string $s): string
    {
        $known = ['google' => 'Google', 'bing' => 'Bing', 'duckduckgo' => 'DuckDuckGo', 'facebook' => 'Facebook', 'twitter' => 'Twitter', 'x' => 'Twitter', 'linkedin' => 'LinkedIn', 'reddit' => 'Reddit', 'newsletter' => 'Newsletter', 'youtube' => 'YouTube'];

        return $known[strtolower($s)] ?? mb_substr($s, 0, 150);
    }

    /** Screen width → Plausible's device buckets. */
    public static function deviceFromWidth(?int $w, bool $mobileUa, bool $tabletUa): string
    {
        if ($tabletUa) {
            return 'Tablet';
        }
        if ($mobileUa) {
            return 'Mobile';
        }

        return match (true) {
            $w === null || $w <= 0 => 'Desktop',
            $w < 576 => 'Mobile',
            $w < 992 => 'Tablet',
            $w < 1440 => 'Laptop',
            default => 'Desktop',
        };
    }
}
