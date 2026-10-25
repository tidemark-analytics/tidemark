<?php

namespace App\Analytics\Support;

/**
 * Whether an address belongs to a cloud or hosting provider. People browse from homes, phones and offices; scripts and crawlers
 * run from data centers. The ranges come from the providers' own published lists (see `bots:update-lists`) and are shipped with
 * each release. Nothing about the visitor is stored; the address is only compared with the ranges in memory.
 */
class DatacenterRanges
{
    /** @var array{v4: array<int, array{0:int,1:int}>, v6: array<int, array{0:string,1:string}>}|null */
    private static ?array $data = null;

    public static function path(): string
    {
        return resource_path('data/datacenter-ranges.php');
    }

    public static function contains(?string $ip): bool
    {
        if (! $ip) {
            return false;
        }
        self::$data ??= is_file(self::path()) ? require self::path() : ['v4' => [], 'v6' => []];

        return self::matches($ip, self::$data);
    }

    /** @param array{v4: array<int, array{0:int,1:int}>, v6: array<int, array{0:string,1:string}>} $data */
    public static function matches(string $ip, array $data): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            return self::within(unpack('N', $packed)[1], $data['v4']);
        }
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) { // an IPv4 address written the IPv6 way
            return self::within(unpack('N', substr($packed, 12))[1], $data['v4']);
        }

        return self::within(bin2hex(substr($packed, 0, 8)), $data['v6']);
    }

    /** Binary search over sorted, non-overlapping [start, end] ranges. */
    private static function within(int|string $x, array $ranges): bool
    {
        $lo = 0;
        $hi = count($ranges) - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($x < $ranges[$mid][0]) {
                $hi = $mid - 1;
            } elseif ($x > $ranges[$mid][1]) {
                $lo = $mid + 1;
            } else {
                return true;
            }
        }

        return false;
    }

    /**
     * Sorted, merged ranges from a list of CIDR blocks. IPv6 blocks are kept to their first 64 bits, which is where networks end.
     *
     * @param  array<int, string>  $cidrs
     * @return array{v4: array<int, array{0:int,1:int}>, v6: array<int, array{0:string,1:string}>}
     */
    public static function build(array $cidrs): array
    {
        $v4 = [];
        $v6 = [];
        foreach ($cidrs as $cidr) {
            [$addr, $bits] = array_pad(explode('/', trim($cidr), 2), 2, null);
            $packed = @inet_pton((string) $addr);
            if ($packed === false || ! is_numeric($bits)) {
                continue;
            }
            $bits = (int) $bits;
            if (strlen($packed) === 4 && $bits >= 8 && $bits <= 32) {
                $start = unpack('N', $packed)[1] & ((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF);
                $v4[] = [$start, $start | (0xFFFFFFFF >> $bits)];
            } elseif (strlen($packed) === 16 && $bits >= 16 && $bits <= 64) {
                $head = unpack('J', substr($packed, 0, 8))[1];
                $start = $bits === 64 ? $head : ($head >> (64 - $bits)) << (64 - $bits);
                $end = $bits === 64 ? $head : $start | ((1 << (64 - $bits)) - 1);
                $v6[] = [sprintf('%016x', $start), sprintf('%016x', $end)];
            }
        }

        return ['v4' => self::merge($v4), 'v6' => self::merge($v6)];
    }

    private static function merge(array $ranges): array
    {
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($ranges as [$s, $e]) {
            $last = count($out) - 1;
            if ($last >= 0 && $s <= (is_int($out[$last][1]) ? $out[$last][1] + 1 : $out[$last][1])) {
                if ($e > $out[$last][1]) {
                    $out[$last][1] = $e;
                }
            } else {
                $out[] = [$s, $e];
            }
        }

        return $out;
    }
}
