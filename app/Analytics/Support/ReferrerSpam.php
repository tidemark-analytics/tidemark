<?php

namespace App\Analytics\Support;

/** Domains that only exist to get their name into other people's referrer reports. The list ships with each release (`bots:update-lists`). */
class ReferrerSpam
{
    /** @var array<string, int>|null */
    private static ?array $domains = null;

    public static function path(): string
    {
        return resource_path('data/referrer-spam.php');
    }

    /** Is this referrer address (or bare domain) a listed spam domain, or a subdomain of one? */
    public static function matches(?string $referrer): bool
    {
        if (! $referrer) {
            return false;
        }
        self::$domains ??= is_file(self::path()) ? require self::path() : [];
        $host = strtolower((string) (parse_url(str_contains($referrer, '://') ? $referrer : 'https://'.$referrer, PHP_URL_HOST) ?: ''));
        $host = preg_replace('/^www\./', '', rtrim($host, '.'));
        while ($host !== '' && str_contains($host, '.')) {
            if (isset(self::$domains[$host])) {
                return true;
            }
            $host = substr($host, strpos($host, '.') + 1);
        }

        return false;
    }
}
