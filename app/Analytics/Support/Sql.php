<?php

namespace App\Analytics\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * The handful of SQL fragments that differ between SQLite, MySQL/MariaDB and PostgreSQL.
 * Everything else in the analytics layer uses the portable query builder.
 */
class Sql
{
    public static function connection(): Connection
    {
        return DB::connection(config('tidemark.analytics_connection'));
    }

    public static function driver(): string
    {
        return self::connection()->getDriverName();
    }

    /** Wrap a column for raw SQL, e.g. "events"."created_at". */
    public static function col(string $column): string
    {
        return self::connection()->getQueryGrammar()->wrap($column);
    }

    /**
     * Bucket a timestamp column into a unit, shifted by a fixed timezone offset (minutes).
     * Yields a normalized 'YYYY-MM-DD HH:MM:SS' string in the viewer's local time.
     */
    public static function bucket(string $column, string $unit, int $offsetMinutes = 0): Expression
    {
        $c = self::col($column);
        $o = (int) $offsetMinutes;
        $formats = [
            'minute' => ['%Y-%m-%d %H:%i:00', '%Y-%m-%d %H:%M:00', 'YYYY-MM-DD HH24:MI:00'],
            'hour' => ['%Y-%m-%d %H:00:00', '%Y-%m-%d %H:00:00', 'YYYY-MM-DD HH24:00:00'],
            'day' => ['%Y-%m-%d 00:00:00', '%Y-%m-%d 00:00:00', 'YYYY-MM-DD 00:00:00'],
            'month' => ['%Y-%m-01 00:00:00', '%Y-%m-01 00:00:00', 'YYYY-MM-01 00:00:00'],
            'year' => ['%Y-01-01 00:00:00', '%Y-01-01 00:00:00', 'YYYY-01-01 00:00:00'],
        ];
        if ($unit === 'week') { // Monday-based weeks, as local midnight of the Monday
            return new Expression(match (self::driver()) {
                'sqlite' => "strftime('%Y-%m-%d 00:00:00', {$c}, '{$o} minutes', 'weekday 0', '-6 days')",
                'pgsql' => "to_char(date_trunc('week', {$c} + interval '{$o} minutes'), 'YYYY-MM-DD 00:00:00')",
                default => "DATE_FORMAT(DATE_SUB(DATE_ADD({$c}, INTERVAL {$o} MINUTE), INTERVAL WEEKDAY(DATE_ADD({$c}, INTERVAL {$o} MINUTE)) DAY), '%Y-%m-%d 00:00:00')",
            });
        }
        [$my, $lite, $pg] = $formats[$unit] ?? $formats['day'];

        return new Expression(match (self::driver()) {
            'sqlite' => "strftime('{$lite}', {$c}, '{$o} minutes')",
            'pgsql' => "to_char({$c} + interval '{$o} minutes', '{$pg}')",
            default => "DATE_FORMAT(DATE_ADD({$c}, INTERVAL {$o} MINUTE), '{$my}')",
        });
    }

    /** Seconds since the Unix epoch for a timestamp column. */
    public static function epoch(string $column): string
    {
        $c = self::col($column);

        return match (self::driver()) {
            'sqlite' => "CAST(strftime('%s', {$c}) AS INTEGER)",
            'pgsql' => "CAST(EXTRACT(EPOCH FROM {$c}) AS BIGINT)",
            default => "TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', {$c})",
        };
    }

    /** Hour-of-day (0-23) and weekday (0=Sunday) in local time. */
    public static function hourOfDay(string $column, int $offset): string
    {
        $c = self::col($column);

        return match (self::driver()) {
            'sqlite' => "CAST(strftime('%H', {$c}, '{$offset} minutes') AS INTEGER)",
            'pgsql' => "CAST(EXTRACT(HOUR FROM {$c} + interval '{$offset} minutes') AS INTEGER)",
            default => "HOUR(DATE_ADD({$c}, INTERVAL {$offset} MINUTE))",
        };
    }

    public static function weekday(string $column, int $offset): string
    {
        $c = self::col($column);

        return match (self::driver()) {
            'sqlite' => "CAST(strftime('%w', {$c}, '{$offset} minutes') AS INTEGER)",
            'pgsql' => "CAST(EXTRACT(DOW FROM {$c} + interval '{$offset} minutes') AS INTEGER)",
            default => "(DAYOFWEEK(DATE_ADD({$c}, INTERVAL {$offset} MINUTE)) - 1)",
        };
    }

    /** Case-insensitive LIKE operator for the current driver. */
    public static function like(): string
    {
        return self::driver() === 'pgsql' ? 'ilike' : 'like';
    }

    public static function raw(string $sql): Expression
    {
        return new Expression($sql);
    }
}
