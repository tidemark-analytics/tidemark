<?php

namespace App\Analytics\Support;

/**
 * Personal data that sites send by accident: an email address in a URL, a password-reset token in a path, an account number in an event
 * property. It is masked before anything is written, and only how many values were masked (by kind) is counted, never the values.
 *
 * Deliberately conservative, because a mask that eats real page names would make reports worse: short numbers (page 2, year 2026, an id
 * like /posts/1234) are left alone, and so are long words and slugs.
 */
class PiiScrubber
{
    public const PATTERNS = ['email', 'uuid', 'number', 'token'];

    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/';

    private const UUID = '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i';

    private const JWT = '/\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]*/';

    /** Nine digits or more in a row: phone, card and account numbers, long order and customer ids. */
    private const NUMBER = '/(?<![\d])\d{9,}(?![\d])/';

    /**
     * @param  array<string, int>  $found  how many values of each kind were masked, added to
     */
    public static function scrub(string $value, array &$found): string
    {
        if ($value === '') {
            return $value;
        }
        $mask = function (string $re, string $kind, string $label, string $in) use (&$found): string {
            return preg_replace_callback($re, function () use (&$found, $kind, $label) {
                $found[$kind] = ($found[$kind] ?? 0) + 1;

                return $label;
            }, $in) ?? $in;
        };
        $value = $mask(self::EMAIL, 'email', '[email]', $value);
        $value = $mask(self::UUID, 'uuid', '[uuid]', $value);
        $value = $mask(self::JWT, 'token', '[token]', $value);
        $value = preg_replace_callback('/[A-Za-z0-9_\-]{20,}/', function ($m) use (&$found) {
            if (! self::looksLikeToken($m[0])) {
                return $m[0];
            }
            $found['token'] = ($found['token'] ?? 0) + 1;

            return '[token]';
        }, $value) ?? $value;

        return $mask(self::NUMBER, 'number', '[number]', $value);
    }

    /** Long, mixed letters and digits, and random-looking: not a word, a slug or a date. */
    public static function looksLikeToken(string $run): bool
    {
        if (preg_match('/^[0-9a-f]{32,}$/i', $run) && preg_match('/\d/', $run) && preg_match('/[a-f]/i', $run)) {
            return true; // a hash, session id or api key in hex
        }
        $hasDigit = (bool) preg_match('/\d/', $run);
        $hasLetter = (bool) preg_match('/[A-Za-z]/', $run);
        if (! $hasDigit || ! $hasLetter) {
            return false;
        }
        $pieces = preg_split('/[_\-]/', $run) ?: [$run];
        if (count($pieces) > 1 && count(array_filter($pieces, fn ($p) => preg_match('/^[A-Za-z]{2,}$/', $p))) >= 3) {
            return false; // several plain words joined by dashes: a slug (even with a date or a number in it)
        }
        if (count($pieces) === 1) {
            return strlen($run) >= 24 && self::entropy($run) >= 4.0;
        }
        if (strlen($run) >= 24 && self::entropy($run) >= 4.2) {
            return true;
        }
        // a token with a dash or underscore in it: judge the longest unbroken piece
        foreach ($pieces as $piece) {
            if (strlen($piece) >= 20 && preg_match('/\d/', $piece) && preg_match('/[A-Za-z]/', $piece) && self::entropy($piece) >= 3.6) {
                return true;
            }
        }

        return false;
    }

    /** Shannon entropy in bits per character. */
    private static function entropy(string $s): float
    {
        $counts = count_chars($s, 1);
        $len = strlen($s);
        $h = 0.0;
        foreach ($counts as $n) {
            $p = $n / $len;
            $h -= $p * log($p, 2);
        }

        return $h;
    }
}
