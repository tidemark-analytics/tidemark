<?php

namespace App\Reports;

use Carbon\CarbonImmutable;

/**
 * The reporting period an e-mail covers: the last full week (Monday to Sunday) or calendar month in the site's timezone. A report is
 * due from 08:00 on the first day after the period ends.
 */
class ReportWindow
{
    public const SEND_HOUR = 8;

    public function __construct(public string $frequency, public CarbonImmutable $start, public CarbonImmutable $end, public CarbonImmutable $due) {}

    /** The newest period that has ended at `$now`, whether or not it is due yet. */
    public static function latest(string $frequency, CarbonImmutable $now): self
    {
        if ($frequency === 'monthly') {
            $first = $now->startOfMonth();
            $start = $first->subMonth()->startOfMonth();
            $end = $first->subDay()->endOfDay();
        } else {
            $first = $now->startOfWeek(); // Monday
            $start = $first->subWeek();
            $end = $first->subDay()->endOfDay();
        }

        return new self($frequency, $start, $end, $first->setTime(self::SEND_HOUR, 0));
    }

    public function isDue(CarbonImmutable $now): bool
    {
        return $now->gte($this->due);
    }

    /** The key stored once it has been sent: the period's last day. */
    public function key(): string
    {
        return $this->end->toDateString();
    }

    public function label(): string
    {
        if ($this->frequency === 'monthly') {
            return $this->start->format('F Y');
        }

        return $this->start->format('j M').' – '.$this->end->format('j M Y');
    }
}
