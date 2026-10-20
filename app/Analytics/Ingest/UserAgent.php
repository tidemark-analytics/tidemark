<?php

namespace App\Analytics\Ingest;

/** Small dependency-free user-agent classifier (browser / OS / device class). */
class UserAgent
{
    private const BOT = '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|embedly|quora link|outbrain|pinterest|vkshare|w3c_validator|whatsapp|telegrambot|discordbot|twitterbot|linkedinbot|headlesschrome|lighthouse|pagespeed|gtmetrix|uptime|pingdom|monitor|curl\/|wget\/|python-requests|python-urllib|go-http-client|java\/|okhttp|libwww|httpclient|axios\/|node-fetch|phantomjs|puppeteer|playwright|selenium|ahrefs|semrush|mj12|dotbot|petalbot|yandex|baidu|duckduckbot|applebot|facebot|ia_archiver|archive\.org|feedfetcher|feedly|netcraft|nmap|masscan|zgrab/i';

    /** @return array{browser:?string, os:?string, mobile:bool, tablet:bool, bot:bool} */
    public static function parse(?string $ua): array
    {
        $ua = (string) $ua;

        return [
            'bot' => $ua === '' || (bool) preg_match(self::BOT, $ua),
            'browser' => self::browser($ua),
            'os' => self::os($ua),
            'tablet' => (bool) preg_match('/ipad|tablet|kindle|silk|playbook|(android(?!.*mobile))/i', $ua),
            'mobile' => (bool) preg_match('/mobi|iphone|ipod|android.*mobile|windows phone|blackberry|opera mini/i', $ua),
        ];
    }

    private static function browser(string $ua): ?string
    {
        $map = [
            'Edge' => '/\b(edg|edge|edga|edgios)\//i',
            'Opera' => '/\b(opr|opera|opios)\b/i',
            'Samsung Internet' => '/samsungbrowser/i',
            'Vivaldi' => '/vivaldi/i',
            'DuckDuckGo' => '/ddg\//i',
            'Firefox' => '/\b(firefox|fxios)\b/i',
            'Internet Explorer' => '/msie|trident\//i',
            'Chrome' => '/\b(chrome|crios|chromium)\b/i',
            'Safari' => '/\bsafari\b/i',
            'Facebook' => '/fban|fbav/i',
            'Instagram' => '/instagram/i',
        ];
        foreach ($map as $name => $re) {
            if (preg_match($re, $ua)) {
                return $name;
            }
        }

        return $ua === '' ? null : 'Other';
    }

    private static function os(string $ua): ?string
    {
        $map = [
            'iOS' => '/iphone|ipad|ipod/i',
            'Android' => '/android/i',
            'Windows' => '/windows|win32|win64/i',
            'Chrome OS' => '/cros/i',
            'Mac' => '/macintosh|mac os x/i',
            'GNU/Linux' => '/linux|x11|ubuntu|debian|fedora/i',
        ];
        foreach ($map as $name => $re) {
            if (preg_match($re, $ua)) {
                return $name;
            }
        }

        return $ua === '' ? null : 'Other';
    }
}
