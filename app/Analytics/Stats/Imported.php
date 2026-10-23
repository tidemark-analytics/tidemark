<?php

namespace App\Analytics\Stats;

use App\Analytics\Support\Filters;
use App\Analytics\Support\Period;
use App\Analytics\Support\Sql;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Reads the daily totals imported from another tool (see PlausibleImportController) so the dashboard can add them to what Tidemark
 * collected itself. Imported data only has per-day numbers for each dimension on its own, so it is used only when no filter is
 * active, only for whole days, and only for the daily, weekly and monthly graph.
 */
class Imported
{
    /** Dashboard dimensions the imported data can answer, with the column the rows are stored under. */
    public const DIMS = ['channel', 'source', 'referrer', 'utm_medium', 'utm_source', 'utm_campaign', 'utm_content', 'utm_term', 'country', 'region', 'browser', 'os', 'device', 'entry_page', 'exit_page', 'page'];

    public function __construct(private Site $site) {}

    public function exists(): bool
    {
        return $this->site->imported_from !== null && $this->site->imported_start !== null;
    }

    public static function supports(string $dim): bool
    {
        return in_array($dim, self::DIMS, true);
    }

    /**
     * The whole local days of the period that imported data covers, as ['Y-m-d', 'Y-m-d'], or null when it doesn't apply.
     *
     * @return array{0:string,1:string}|null
     */
    public function range(Period $p, Filters $f): ?array
    {
        if (! $this->exists() || $f->rules || in_array($p->interval, ['minute', 'hour'], true) || $p->key === 'realtime') {
            return null;
        }
        $tz = new \DateTimeZone($p->timezone);
        $start = CarbonImmutable::instance($p->start->toDateTime())->setTimezone($tz);
        $end = CarbonImmutable::instance($p->end->toDateTime())->setTimezone($tz);
        $first = $start->format('H:i:s') === '00:00:00' ? $start : $start->addDay()->startOfDay();
        $last = $end->format('H:i:s') === '23:59:59' ? $end : $end->subDay()->startOfDay();
        $from = max($first->toDateString(), $this->site->imported_start->toDateString());
        $to = min($last->toDateString(), $this->site->imported_end->toDateString());

        return $from <= $to ? [$from, $to] : null;
    }

    /** @return array{visitors:int,visits:int,pageviews:int,bounces:int,dur:int} */
    public function totals(array $range): array
    {
        $r = $this->daily($range)->selectRaw('COALESCE(SUM(visitors),0) as visitors, COALESCE(SUM(visits),0) as visits, COALESCE(SUM(pageviews),0) as pageviews, COALESCE(SUM(bounces),0) as bounces, COALESCE(SUM(duration),0) as dur')->first();

        return ['visitors' => (int) $r->visitors, 'visits' => (int) $r->visits, 'pageviews' => (int) $r->pageviews, 'bounces' => (int) $r->bounces, 'dur' => (int) $r->dur];
    }

    /**
     * Per-bucket totals keyed by the same bucket labels the native series uses.
     *
     * @return array<string,array{visitors:int,visits:int,pageviews:int,bounces:int,dur:int}>
     */
    public function series(array $range, Period $p): array
    {
        $out = [];
        $rows = $this->daily($range)->selectRaw('date, SUM(visitors) as visitors, SUM(visits) as visits, SUM(pageviews) as pageviews, SUM(bounces) as bounces, SUM(duration) as dur')->groupBy('date')->get();
        foreach ($rows as $r) {
            $day = CarbonImmutable::parse((string) $r->date, 'UTC');
            $label = match ($p->interval) {
                'week' => $day->startOfWeek()->format('Y-m-d 00:00:00'),
                'month' => $day->startOfMonth()->format('Y-m-d 00:00:00'),
                default => $day->format('Y-m-d 00:00:00'),
            };
            $b = &$out[$label];
            $b ??= ['visitors' => 0, 'visits' => 0, 'pageviews' => 0, 'bounces' => 0, 'dur' => 0];
            foreach (['visitors', 'visits', 'pageviews', 'bounces', 'dur'] as $k) {
                $b[$k] += (int) $r->{$k};
            }
            unset($b);
        }

        return $out;
    }

    /**
     * Rows for one dimension, summed over the range. Visitors add up day by day, which counts someone who came on two days twice.
     *
     * @return array<int,object{name:string,visitors:int,visits:int,pv:int,bounces:int,dur:int}>
     */
    public function breakdown(string $dim, array $range, ?string $search = null, int $limit = 5000): array
    {
        $q = $this->breakdownQ($range)->where('dim', $dim)->where('name', '!=', '')
            ->selectRaw('name, SUM(visitors) as visitors, SUM(visits) as visits, SUM(pageviews) as pv, SUM(bounces) as bounces, SUM(duration) as dur')
            ->groupBy('name')->orderByDesc('visitors')->orderBy('name')->limit($limit);
        if ($search !== null) {
            $q->where('name', Sql::like(), '%'.addcslashes($search, '%_\\').'%');
        }

        return $q->get()->all();
    }

    /** Visits that came from no known source (a "Direct / None" row), summed over the range. */
    public function direct(array $range): ?object
    {
        $r = $this->breakdownQ($range)->where('dim', 'source')->where('name', '')
            ->selectRaw('SUM(visitors) as visitors, SUM(visits) as visits, SUM(pageviews) as pv, SUM(bounces) as bounces, SUM(duration) as dur')->first();

        return $r && $r->visits !== null ? $r : null;
    }

    /**
     * Totals for a dimension's named rows only (used to look up entry-page bounce rates and page view counts).
     *
     * @param  array<int,string>  $names
     * @return array<string,object>
     */
    public function lookup(string $dim, array $range, array $names): array
    {
        if (! $names) {
            return [];
        }
        $rows = $this->breakdownQ($range)->where('dim', $dim)->whereIn('name', $names)
            ->selectRaw('name, SUM(visitors) as visitors, SUM(visits) as visits, SUM(pageviews) as pv, SUM(bounces) as bounces')->groupBy('name')->get();

        return $rows->keyBy('name')->all();
    }

    /** @return array<string,array{visitors:int,events:int}> custom events by name */
    public function events(array $range): array
    {
        $out = [];
        foreach ($this->breakdownQ($range)->where('dim', 'event')->selectRaw('name, SUM(visitors) as visitors, SUM(visits) as events')->groupBy('name')->get() as $r) {
            $out[$r->name] = ['visitors' => (int) $r->visitors, 'events' => (int) $r->events];
        }

        return $out;
    }

    /** Visitors and pageviews of the pages that match a page goal's path (a `*` matches within a segment, `**` anything). */
    public function pageGoal(array $range, string $pattern): array
    {
        $regex = '#^'.str_replace(['\*\*', '\*'], ['.*', '[^/]*'], preg_quote($pattern, '#')).'$#u';
        $visitors = $views = 0;
        foreach ($this->breakdown('page', $range, null, 100000) as $r) {
            if (preg_match($regex, $r->name)) {
                $visitors += (int) $r->visitors;
                $views += (int) $r->pv;
            }
        }

        return ['visitors' => $visitors, 'events' => $views];
    }

    private function daily(array $range): Builder
    {
        return Sql::connection()->table('imported_visitors')->where('site_id', $this->site->id)->whereBetween('date', $range);
    }

    private function breakdownQ(array $range): Builder
    {
        return Sql::connection()->table('imported_breakdowns')->where('site_id', $this->site->id)->whereBetween('date', $range);
    }
}
