<?php

namespace App\Analytics\Support;

/**
 * Query strings are never stored with a page by default. A site owner can allow-list parameter names (a page number, a language) to get
 * them in the page reports. Names that look like they carry a secret or personal data are refused, because they would end up in a report.
 */
class UrlParams
{
    public const MAX = 20;

    private const REFUSED = '/pass|token|secret|auth|session|sess|key|email|mail|phone|name|user|login|code|sig|hash|otp|ssn|card/i';

    /**
     * The allow-list as a clean list of names, from whatever the owner typed (commas, spaces or new lines).
     *
     * @return array<int, string>
     */
    public static function parse(?string $raw): array
    {
        $names = preg_split('/[\s,]+/', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($names));
    }

    /** The reason a list is not acceptable, or null when it is fine. */
    public static function problem(?string $raw): ?string
    {
        $names = self::parse($raw);
        if (count($names) > self::MAX) {
            return 'Allow at most '.self::MAX.' parameters.';
        }
        foreach ($names as $name) {
            if (! preg_match('/^[A-Za-z0-9_.\-]{1,50}$/', $name)) {
                return "“{$name}” is not a valid parameter name. Use letters, numbers, dashes, dots and underscores.";
            }
            if (preg_match(self::REFUSED, $name)) {
                return "“{$name}” looks like it could carry a secret or personal data, so it can't be stored with page addresses.";
            }
        }

        return null;
    }

    /** The list as it is saved. */
    public static function normalize(?string $raw): ?string
    {
        $names = self::parse($raw);

        return $names ? implode(',', $names) : null;
    }

    /**
     * The page path with the allowed parameters from the query appended, sorted by name so the same page always reports as one row. A
     * `#fragment` (hash mode) stays at the end.
     *
     * @param  array<string, mixed>  $query  the parsed query string
     * @param  array<int, string>  $allowed
     */
    public static function apply(string $path, array $query, array $allowed): string
    {
        if (! $allowed) {
            return $path;
        }
        $fragment = '';
        if (($at = strpos($path, '#')) !== false) {
            [$path, $fragment] = [substr($path, 0, $at), substr($path, $at)];
        }
        $keep = [];
        foreach ($allowed as $name) {
            if (isset($query[$name]) && is_string($query[$name]) && $query[$name] !== '') {
                $keep[$name] = preg_replace('/[\x00-\x1F\x7F]/', '', mb_substr($query[$name], 0, 100));
            }
        }
        ksort($keep);

        return mb_substr($path.($keep ? '?'.http_build_query($keep, '', '&', PHP_QUERY_RFC3986) : '').$fragment, 0, 500);
    }
}
