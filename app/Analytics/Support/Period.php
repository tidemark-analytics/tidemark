<?php

namespace App\Analytics\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * A reporting window in the site's timezone (like Plausible's `period` + `date`).
 * Boundaries are stored in UTC; `offset()` shifts SQL buckets into the site's local time.
 */
class Period
{
    public const KEYS = ['realtime', 'day', '24h', '7d', '28d', '30d', '91d', 'month', '6mo', '12mo', 'year', 'all', 'custom'];

    public function __construct(
        public string $key,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public string $interval,
        public string $timezone,
        public ?CarbonImmutable $anchor = null, // the local `date` the period was resolved against
    ) {}

    public static function fromRequest(Request $r, string $timezone, ?CarbonImmutable $firstEvent = null): self
    {
        $key = (string) $r->input('period', '28d');
        $key = in_array($key, self::KEYS, true) ? $key : '28d';
        $tz = new \DateTimeZone($timezone);
        $now = CarbonImmutable::now($tz);
        $date = $r->input('date') ? CarbonImmutable::parse((string) $r->input('date'), $tz) : $now;

        return self::resolve($key, $date, $timezone, $r->input('from'), $r->input('to'), $firstEvent, $r->input('interval'));
    }

    public static function resolve(string $key, CarbonImmutable $date, string $timezone, ?string $from = null, ?string $to = null, ?CarbonImmutable $firstEvent = null, ?string $interval = null): self
    {
        $tz = new \DateTimeZone($timezone);
        $now = CarbonImmutable::now($tz);
        $date = $date->setTimezone($tz);
        $endOfToday = $now->endOfDay();

        [$start, $end, $default] = match ($key) {
            'realtime' => [$now->subMinutes(30)->startOfMinute(), $now, 'minute'],
            '24h' => [$now->subHours(24)->startOfHour(), $now, 'hour'],
            'day' => [$date->startOfDay(), min($date->endOfDay(), $endOfToday), 'hour'],
            '7d' => [$date->subDays(6)->startOfDay(), min($date->endOfDay(), $endOfToday), 'day'],
            '28d' => [$date->subDays(27)->startOfDay(), min($date->endOfDay(), $endOfToday), 'day'],
            '30d' => [$date->subDays(29)->startOfDay(), min($date->endOfDay(), $endOfToday), 'day'],
            '91d' => [$date->subDays(90)->startOfDay(), min($date->endOfDay(), $endOfToday), 'day'],
            'month' => [$date->startOfMonth(), min($date->endOfMonth(), $endOfToday), 'day'],
            '6mo' => [$date->startOfMonth()->subMonths(5), min($date->endOfMonth(), $endOfToday), 'month'],
            '12mo' => [$date->startOfMonth()->subMonths(11), min($date->endOfMonth(), $endOfToday), 'month'],
            'year' => [$date->startOfYear(), min($date->endOfYear(), $endOfToday), 'month'],
            'all' => [($firstEvent ? $firstEvent->setTimezone($tz)->startOfDay() : $now->subDays(30)->startOfDay()), $endOfToday, 'month'],
            default => [CarbonImmutable::parse($from ?: $now->subDays(27)->toDateString(), $tz)->startOfDay(), CarbonImmutable::parse($to ?: $now->toDateString(), $tz)->endOfDay(), 'day'],
        };
        if ($key === 'custom' && $start->diffInDays($end) > 366 * 3) {
            $start = $end->subYears(3)->startOfDay();
        }
        if ($start->gt($end)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        $p = new self($key, $start->utc(), $end->utc(), $default, $timezone, $date);
        $p->interval = $p->allowsInterval($interval) ? $interval : $p->defaultInterval($default);

        return $p;
    }

    /** Intervals that make sense for the span: no hourly buckets over a year, no daily ones over three. */
    public function allowedIntervals(): array
    {
        if ($this->key === 'realtime') {
            return ['minute'];
        }
        $days = max(1, $this->start->diffInDays($this->end) + 1);

        return match (true) {
            $days <= 2 => ['hour'],
            $days <= 7 => ['hour', 'day'],
            $days <= 60 => ['day', 'week'],
            $days <= 400 => ['day', 'week', 'month'],
            default => ['week', 'month'],
        };
    }

    private function allowsInterval(?string $i): bool
    {
        return $i !== null && in_array($i, $this->allowedIntervals(), true);
    }

    private function defaultInterval(string $preferred): string
    {
        $allowed = $this->allowedIntervals();

        return in_array($preferred, $allowed, true) ? $preferred : (in_array('day', $allowed, true) && $preferred === 'hour' ? 'day' : $allowed[0]);
    }

    /** Offset of the site's timezone from UTC in minutes, at the end of the window. */
    public function offset(): int
    {
        return (int) ((new \DateTimeZone($this->timezone))->getOffset($this->end->toDateTime()) / 60);
    }

    public function startSql(): string
    {
        return $this->start->format('Y-m-d H:i:s');
    }

    public function endSql(): string
    {
        return $this->end->format('Y-m-d H:i:s');
    }

    /** The window to compare against: the immediately preceding period, or the same dates a year earlier. */
    public function comparison(string $mode): self
    {
        $p = clone $this;
        if ($mode === 'year_over_year') {
            $p->start = $this->start->subYear();
            $p->end = $this->end->subYear();
        } else {
            $len = $this->end->getTimestamp() - $this->start->getTimestamp();
            $p->end = $this->start->subSecond();
            $p->start = $p->end->subSeconds($len);
        }

        return $p;
    }

    /** Every bucket label (site-local 'Y-m-d H:i:s') in the window, so charts have no gaps. */
    public function buckets(): array
    {
        $tz = new \DateTimeZone($this->timezone);
        $cursor = CarbonImmutable::instance($this->start->toDateTime())->setTimezone($tz);
        $last = CarbonImmutable::instance($this->end->toDateTime())->setTimezone($tz);
        $step = ['minute' => '1 minute', 'hour' => '1 hour', 'day' => '1 day', 'week' => '1 week', 'month' => '1 month'][$this->interval];
        $cursor = match ($this->interval) {
            'minute' => $cursor->startOfMinute(),
            'hour' => $cursor->startOfHour(),
            'day' => $cursor->startOfDay(),
            'week' => $cursor->startOfWeek(),
            'month' => $cursor->startOfMonth(),
        };
        $out = [];
        for ($n = 0; $cursor->lte($last) && $n < 5000; $n++) {
            $out[] = $cursor->format('Y-m-d H:i:s');
            $cursor = $cursor->modify('+'.$step);
        }

        return $out;
    }

    public function toArray(): array
    {
        return [
            'period' => $this->key, 'interval' => $this->interval, 'intervals' => $this->allowedIntervals(),
            'from' => $this->start->setTimezone(new \DateTimeZone($this->timezone))->toIso8601String(),
            'to' => $this->end->setTimezone(new \DateTimeZone($this->timezone))->toIso8601String(),
        ];
    }
}
