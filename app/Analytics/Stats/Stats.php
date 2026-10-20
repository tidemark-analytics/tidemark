<?php

namespace App\Analytics\Stats;

use App\Analytics\Support\Filters;
use App\Analytics\Support\Period;
use App\Analytics\Support\Sql;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * All dashboard numbers, computed the way Plausible does:
 *   visitors / visits / bounce rate / visit duration come from the `visits` table (a visit belongs to the period it started in);
 *   pageviews and goal conversions come from `events`.
 * Everything is plain portable SQL (SQLite, MySQL/MariaDB, PostgreSQL); only date bucketing differs per driver (see Sql).
 */
class Stats
{
    public const VISIT_DIMS = ['channel', 'source', 'referrer', 'utm_medium', 'utm_source', 'utm_campaign', 'utm_content', 'utm_term', 'country', 'region', 'city', 'browser', 'os', 'device', 'entry_page', 'exit_page'];

    public const DIMS = [...self::VISIT_DIMS, 'page'];

    public function __construct(public Site $site) {}

    // ------------------------------------------------------------------------------------------------ builders
    private function visitsQ(Period $p, Filters $f, bool $applyFilters = true): Builder
    {
        $col = $p->key === 'realtime' ? 'v.ended_at' : 'v.started_at';
        $q = Sql::connection()->table('visits as v')->where('v.site_id', $this->site->id)->whereBetween($col, [$p->startSql(), $p->endSql()]);

        return $applyFilters ? $f->applyToVisits($q) : $q;
    }

    private function eventsQ(Period $p, Filters $f): Builder
    {
        $q = Sql::connection()->table('events as e')->join('visits as v', 'v.id', '=', 'e.visit_id')
            ->where('e.site_id', $this->site->id)->whereBetween('e.created_at', [$p->startSql(), $p->endSql()]);

        return $f->applyToEvents($q);
    }

    private function bucket(string $col, Period $p): string
    {
        return Sql::bucket($col, $p->interval, $p->offset())->getValue(Sql::connection()->getQueryGrammar());
    }

    private const AGG = 'COUNT(DISTINCT v.visitor_id) as visitors, COUNT(*) as visits, COALESCE(SUM(CASE WHEN v.is_bounce THEN 1 ELSE 0 END), 0) as bounces, COALESCE(SUM(v.duration), 0) as dur, COALESCE(SUM(v.pageviews), 0) as pv';

    private static function derive(object|array $r): array
    {
        $r = (array) $r;
        $visits = (int) ($r['visits'] ?? 0);
        $pageviews = (int) ($r['pageviews'] ?? $r['pv'] ?? 0);

        return [
            'visitors' => (int) ($r['visitors'] ?? 0),
            'visits' => $visits,
            'pageviews' => $pageviews,
            'views_per_visit' => $visits ? round($pageviews / $visits, 2) : 0,
            'bounce_rate' => $visits ? (int) round(((int) $r['bounces']) / $visits * 100) : 0,
            'visit_duration' => $visits ? (int) round(((int) $r['dur']) / $visits) : 0,
        ];
    }

    // ------------------------------------------------------------------------------------------------ top stats
    /** @return array<string,int|float> */
    public function topStats(Period $p, Filters $f): array
    {
        if ($goal = $f->goalName()) {
            return $this->goalTopStats($p, $f);
        }
        $agg = $this->visitsQ($p, $f)->selectRaw(self::AGG)->first();
        $pageviews = (int) $this->eventsQ($p, $f)->where('e.name', 'pageview')->count();

        return self::derive((array) $agg + ['pageviews' => $pageviews]);
    }

    private function goalTopStats(Period $p, Filters $f): array
    {
        $row = $this->eventsQ($p, $f)->selectRaw('COUNT(DISTINCT e.visitor_id) as uniq, COUNT(*) as total')->first();
        $visitors = (int) $this->visitsQ($p, $f->without('goal'))->selectRaw('COUNT(DISTINCT v.visitor_id) as n')->value('n');

        return ['unique_conversions' => (int) $row->uniq, 'total_conversions' => (int) $row->total, 'conversion_rate' => $visitors ? round($row->uniq / $visitors * 100, 1) : 0, 'visitors' => $visitors];
    }

    public function currentVisitors(Filters $f): int
    {
        $q = Sql::connection()->table('visits as v')->where('v.site_id', $this->site->id)->where('v.ended_at', '>=', gmdate('Y-m-d H:i:s', time() - 300));

        return (int) $f->applyToVisits($q)->selectRaw('COUNT(DISTINCT v.visitor_id) as n')->value('n');
    }

    public function firstVisitAt(): ?CarbonImmutable
    {
        $t = Sql::connection()->table('visits')->where('site_id', $this->site->id)->min('started_at');

        return $t ? CarbonImmutable::parse($t, 'UTC') : null;
    }

    // ------------------------------------------------------------------------------------------------ time series
    /** @return array{labels:array<int,string>, plot:array<string,array<int,int|float>>} */
    public function timeseries(Period $p, Filters $f): array
    {
        $labels = $p->buckets();
        if ($f->goalName()) {
            return $this->goalSeries($p, $f, $labels);
        }

        $byBucket = [];
        if ($p->key === 'realtime') { // activity per minute, from events
            $b = $this->bucket('e.created_at', $p);
            foreach ($this->eventsQ($p, $f)->selectRaw("{$b} as t, COUNT(DISTINCT e.visitor_id) as visitors, SUM(CASE WHEN e.name = 'pageview' THEN 1 ELSE 0 END) as pageviews")->groupBy('t')->get() as $r) {
                $byBucket[$r->t] = ['visitors' => (int) $r->visitors, 'pageviews' => (int) $r->pageviews, 'visits' => (int) $r->visitors, 'bounces' => 0, 'dur' => 0];
            }
        } else {
            $b = $this->bucket('v.started_at', $p);
            foreach ($this->visitsQ($p, $f)->selectRaw("{$b} as t, ".self::AGG)->groupBy('t')->get() as $r) {
                $byBucket[$r->t] = (array) $r;
            }
            $be = $this->bucket('e.created_at', $p);
            foreach ($this->eventsQ($p, $f)->where('e.name', 'pageview')->selectRaw("{$be} as t, COUNT(*) as pageviews")->groupBy('t')->get() as $r) {
                $byBucket[$r->t]['pageviews'] = (int) $r->pageviews;
            }
        }

        $plot = array_fill_keys(['visitors', 'visits', 'pageviews', 'views_per_visit', 'bounce_rate', 'visit_duration'], []);
        foreach ($labels as $l) {
            $d = self::derive(($byBucket[$l] ?? []) + ['visitors' => 0, 'visits' => 0, 'pageviews' => 0, 'bounces' => 0, 'dur' => 0]);
            foreach ($plot as $k => $_) {
                $plot[$k][] = $d[$k];
            }
        }

        return ['labels' => $labels, 'plot' => $plot];
    }

    private function goalSeries(Period $p, Filters $f, array $labels): array
    {
        $be = $this->bucket('e.created_at', $p);
        $conv = [];
        foreach ($this->eventsQ($p, $f)->selectRaw("{$be} as t, COUNT(DISTINCT e.visitor_id) as uniq, COUNT(*) as total")->groupBy('t')->get() as $r) {
            $conv[$r->t] = $r;
        }
        $bv = $this->bucket('v.started_at', $p);
        $vis = [];
        foreach ($this->visitsQ($p, $f->without('goal'))->selectRaw("{$bv} as t, COUNT(DISTINCT v.visitor_id) as n")->groupBy('t')->get() as $r) {
            $vis[$r->t] = (int) $r->n;
        }
        $plot = ['unique_conversions' => [], 'total_conversions' => [], 'conversion_rate' => []];
        foreach ($labels as $l) {
            $u = (int) ($conv[$l]->uniq ?? 0);
            $plot['unique_conversions'][] = $u;
            $plot['total_conversions'][] = (int) ($conv[$l]->total ?? 0);
            $plot['conversion_rate'][] = ($vis[$l] ?? 0) ? round($u / $vis[$l] * 100, 1) : 0;
        }

        return ['labels' => $labels, 'plot' => $plot];
    }

    // ------------------------------------------------------------------------------------------------ breakdowns
    /**
     * @param  array{limit?:int,page?:int,search?:?string}  $o
     * @return array{results:array<int,array<string,mixed>>, has_more:bool}
     */
    public function breakdown(string $dim, Period $p, Filters $f, array $o = []): array
    {
        abort_unless(in_array($dim, self::DIMS, true), 422, 'Unknown dimension');
        if ($p->key === 'realtime') { // "current visitors" = the last five minutes
            $p = clone $p;
            $p->start = CarbonImmutable::now('UTC')->subMinutes(5);
        }
        $limit = max(1, min((int) ($o['limit'] ?? 9), 1000));
        $offset = max(0, ((int) ($o['page'] ?? 1) - 1) * $limit);
        $search = isset($o['search']) && $o['search'] !== '' ? $o['search'] : null;

        $rows = $f->goalName() ? $this->conversionRows($dim, $p, $f, $limit + 1, $offset, $search) : ($dim === 'page'
            ? $this->pageRows($p, $f, $limit + 1, $offset, $search)
            : $this->visitRows($dim, $p, $f, $limit + 1, $offset, $search));

        return ['results' => array_slice($rows, 0, $limit), 'has_more' => count($rows) > $limit];
    }

    private function col(string $dim): string
    {
        return $dim === 'page' ? 'e.pathname' : 'v.'.$dim;
    }

    private function applySearch(Builder $q, string $col, ?string $search): void
    {
        if ($search !== null) {
            $q->where($col, Sql::like(), '%'.addcslashes($search, '%_\\').'%');
        }
    }

    private function visitRows(string $dim, Period $p, Filters $f, int $limit, int $offset, ?string $search): array
    {
        $col = 'v.'.$dim;
        $q = $this->visitsQ($p, $f)->selectRaw("{$col} as name, ".self::AGG)->groupBy($col)->orderByDesc('visitors')->orderBy('name')->limit($limit)->offset($offset);
        if ($dim !== 'source') {
            $q->whereNotNull($col)->where($col, '!=', '');
        }
        $this->applySearch($q, $col, $search);
        $rows = $q->get();

        $out = [];
        $exitRates = $dim === 'exit_page' ? $this->pageviewsByPath($p, $f, $rows->pluck('name')->filter()->all()) : [];
        foreach ($rows as $r) {
            $d = self::derive($r);
            $row = ['name' => $r->name ?? Filters::DIRECT, 'visitors' => $d['visitors'], 'visits' => $d['visits'], 'pageviews' => $d['pageviews'], 'bounce_rate' => $d['bounce_rate'], 'visit_duration' => $d['visit_duration']];
            if ($dim === 'exit_page') {
                $views = $exitRates[$r->name] ?? 0;
                $row['exit_rate'] = $views ? min(100, (int) round($d['visits'] / $views * 100)) : 0;
            }
            $out[] = $row;
        }

        return $out;
    }

    private function pageRows(Period $p, Filters $f, int $limit, int $offset, ?string $search): array
    {
        $q = $this->eventsQ($p, $f)->where('e.name', 'pageview')->selectRaw('e.pathname as name, COUNT(DISTINCT e.visitor_id) as visitors, COUNT(*) as pageviews')
            ->groupBy('e.pathname')->orderByDesc('visitors')->orderBy('name')->limit($limit)->offset($offset);
        $this->applySearch($q, 'e.pathname', $search);
        $rows = $q->get();
        $names = $rows->pluck('name')->all();

        // bounce rate of a page = bounce rate of the visits that entered on it
        $bounce = [];
        if ($names) {
            foreach ($this->visitsQ($p, $f)->whereIn('v.entry_page', $names)->selectRaw('v.entry_page as name, COUNT(*) as visits, COALESCE(SUM(CASE WHEN v.is_bounce THEN 1 ELSE 0 END), 0) as bounces')->groupBy('v.entry_page')->get() as $r) {
                $bounce[$r->name] = $r->visits ? (int) round($r->bounces / $r->visits * 100) : null;
            }
        }

        return $rows->map(fn ($r) => ['name' => $r->name, 'visitors' => (int) $r->visitors, 'pageviews' => (int) $r->pageviews, 'bounce_rate' => $bounce[$r->name] ?? null])->all();
    }

    /** Goal filter active: rows become unique/total conversions plus conversion rate against the segment's visitors. */
    private function conversionRows(string $dim, Period $p, Filters $f, int $limit, int $offset, ?string $search): array
    {
        $col = $this->col($dim);
        $q = $this->eventsQ($p, $f)->selectRaw("{$col} as name, COUNT(DISTINCT e.visitor_id) as uniq, COUNT(*) as total")->groupBy($col)->orderByDesc('uniq')->orderBy('name')->limit($limit)->offset($offset);
        if ($dim !== 'source') {
            $q->whereNotNull($col);
        }
        $this->applySearch($q, $col, $search);
        $rows = $q->get();
        $names = $rows->pluck('name')->filter()->all();

        $totals = [];
        $base = $f->without('goal');
        if ($names || $rows->contains(fn ($r) => $r->name === null)) {
            if ($dim === 'page') {
                $t = $this->eventsQ($p, $base)->where('e.name', 'pageview')->whereIn('e.pathname', $names)->selectRaw('e.pathname as name, COUNT(DISTINCT e.visitor_id) as n')->groupBy('e.pathname')->get();
            } else {
                $t = $this->visitsQ($p, $base)->selectRaw("v.{$dim} as name, COUNT(DISTINCT v.visitor_id) as n")->groupBy("v.{$dim}")->when($names, fn ($q) => $q->where(fn ($w) => $w->whereIn("v.{$dim}", $names)->orWhereNull("v.{$dim}")))->get();
            }
            foreach ($t as $r) {
                $totals[$r->name ?? Filters::DIRECT] = (int) $r->n;
            }
        }

        return $rows->map(function ($r) use ($totals) {
            $name = $r->name ?? Filters::DIRECT;
            $tot = $totals[$name] ?? 0;

            return ['name' => $name, 'visitors' => (int) $r->uniq, 'events' => (int) $r->total, 'conversion_rate' => $tot ? round($r->uniq / $tot * 100, 1) : 0];
        })->all();
    }

    /** @return array<string,int> */
    private function pageviewsByPath(Period $p, Filters $f, array $paths): array
    {
        if (! $paths) {
            return [];
        }
        $out = [];
        foreach ($this->eventsQ($p, $f)->where('e.name', 'pageview')->whereIn('e.pathname', $paths)->selectRaw('e.pathname as name, COUNT(*) as n')->groupBy('e.pathname')->get() as $r) {
            $out[$r->name] = (int) $r->n;
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------ goals & props
    /** @return array<int,array<string,mixed>> */
    public function goals(Period $p, Filters $f): array
    {
        $base = $f->without('goal');
        $visitors = (int) $this->visitsQ($p, $base)->selectRaw('COUNT(DISTINCT v.visitor_id) as n')->value('n');
        $out = [];
        foreach ($this->site->goals()->orderBy('created_at')->get() as $goal) {
            $g = new Filters([...$base->rules, ['is', 'goal', [$goal->name]]], $this->site);
            $r = $this->eventsQ($p, $g)->selectRaw('COUNT(DISTINCT e.visitor_id) as uniq, COUNT(*) as total')->first();
            $out[] = ['id' => $goal->id, 'name' => $goal->name, 'type' => $goal->type, 'visitors' => (int) $r->uniq, 'events' => (int) $r->total, 'conversion_rate' => $visitors ? round($r->uniq / $visitors * 100, 1) : 0];
        }
        usort($out, fn ($a, $b) => $b['visitors'] <=> $a['visitors']);

        return $out;
    }

    /** Custom property keys seen on events in the window. */
    public function propKeys(Period $p, Filters $f): array
    {
        return $this->propQ($p, $f)->selectRaw('pp.key as name, COUNT(DISTINCT e.visitor_id) as visitors, COUNT(*) as events')->groupBy('pp.key')->orderByDesc('visitors')->orderBy('name')->limit(100)->get()
            ->map(fn ($r) => ['name' => $r->name, 'visitors' => (int) $r->visitors, 'events' => (int) $r->events])->all();
    }

    public function propValues(string $key, Period $p, Filters $f, int $limit = 9, int $page = 1, ?string $search = null): array
    {
        $visitors = (int) $this->visitsQ($p, $f->without('goal'))->selectRaw('COUNT(DISTINCT v.visitor_id) as n')->value('n');
        $q = $this->propQ($p, $f)->where('pp.key', $key)->selectRaw('pp.value as name, COUNT(DISTINCT e.visitor_id) as visitors, COUNT(*) as events')->groupBy('pp.value')
            ->orderByDesc('visitors')->orderBy('name')->limit($limit + 1)->offset(max(0, ($page - 1) * $limit));
        $this->applySearch($q, 'pp.value', $search);
        $rows = $q->get()->map(fn ($r) => ['name' => $r->name, 'visitors' => (int) $r->visitors, 'events' => (int) $r->events, 'conversion_rate' => $visitors ? round($r->visitors / $visitors * 100, 1) : 0])->all();

        return ['results' => array_slice($rows, 0, $limit), 'has_more' => count($rows) > $limit];
    }

    private function propQ(Period $p, Filters $f): Builder
    {
        return $this->eventsQ($p, $f)->join('event_props as pp', 'pp.event_id', '=', 'e.id');
    }

    // ------------------------------------------------------------------------------------------------ site list
    /** Visitors in the last 24 hours, the 24 hours before, and an hourly sparkline. */
    public function last24h(): array
    {
        $now = CarbonImmutable::now('UTC');
        $tz = $this->site->timezone;
        $cur = Period::resolve('24h', CarbonImmutable::now($tz), $tz);
        $prev = $cur->comparison('previous_period');
        $f = new Filters([], $this->site);
        $visitors = (int) $this->visitsQ($cur, $f)->selectRaw('COUNT(DISTINCT v.visitor_id) as n')->value('n');
        $before = (int) $this->visitsQ($prev, $f)->selectRaw('COUNT(DISTINCT v.visitor_id) as n')->value('n');
        $series = $this->timeseries($cur, $f);

        return ['visitors' => $visitors, 'change' => $before ? round(($visitors - $before) / $before * 100) : ($visitors ? 100 : 0), 'series' => $series['plot']['visitors']];
    }
}
