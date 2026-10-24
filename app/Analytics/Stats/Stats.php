<?php

namespace App\Analytics\Stats;

use App\Analytics\Support\Filters;
use App\Analytics\Support\Period;
use App\Analytics\Support\Sql;
use App\Models\Funnel;
use App\Models\Goal;
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

    private Imported $imported;

    public function __construct(public Site $site)
    {
        $this->imported = new Imported($site);
    }

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

    /** The currency the goal in the filters reports revenue in, when the site may use revenue reporting. */
    public function goalCurrency(Filters $f): ?string
    {
        $name = $f->goalName();

        return $name ? $this->currencyOf($this->goalByName($name)) : null;
    }

    private function goalByName(string $name): ?Goal
    {
        return $this->site->goals()->get()->first(fn ($g) => $g->name === $name || ($g->type === 'event' && $g->value === $name));
    }

    private function currencyOf(?Goal $goal): ?string
    {
        return $goal && $goal->type === 'event' && $goal->currency && $this->site->revenueAllowed() ? $goal->currency : null;
    }

    /** Revenue of an event in the goal's currency: converted when it was recorded, or as sent when it already is in that currency. */
    private static function revenueExpr(string $currency): string
    {
        $currency = preg_replace('/[^A-Z]/', '', $currency);

        return "COALESCE(e.revenue_reporting, CASE WHEN e.currency = '{$currency}' THEN e.revenue END)";
    }

    /** SUM and AVG over the revenue column, for a query over `events as e`. */
    private static function revenueAgg(string $currency): string
    {
        $x = self::revenueExpr($currency);

        return "COALESCE(SUM({$x}), 0) as revenue, COUNT({$x}) as revenue_events";
    }

    private static function average(object|array $r): float
    {
        $r = (array) $r;

        return ($r['revenue_events'] ?? 0) ? round((float) $r['revenue'] / (int) $r['revenue_events'], 2) : 0.0;
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
        $agg = (array) $this->visitsQ($p, $f)->selectRaw(self::AGG)->first();
        $pageviews = (int) $this->eventsQ($p, $f)->where('e.name', 'pageview')->count();
        if ($range = $this->imported->range($p, $f)) {
            $t = $this->imported->totals($range);
            foreach (['visitors', 'visits', 'bounces', 'dur'] as $k) {
                $agg[$k] = (int) $agg[$k] + $t[$k];
            }
            $pageviews += $t['pageviews'];
        }

        return self::derive($agg + ['pageviews' => $pageviews]);
    }

    private function goalTopStats(Period $p, Filters $f): array
    {
        $currency = $this->goalCurrency($f);
        $row = $this->eventsQ($p, $f)->selectRaw('COUNT(DISTINCT e.visitor_id) as uniq, COUNT(*) as total'.($currency ? ', '.self::revenueAgg($currency) : ''))->first();
        $visitors = (int) $this->visitsQ($p, $f->without('goal'))->selectRaw('COUNT(DISTINCT v.visitor_id) as n')->value('n');
        $out = ['unique_conversions' => (int) $row->uniq, 'total_conversions' => (int) $row->total];
        if ($currency) {
            $out += ['total_revenue' => round((float) $row->revenue, 2), 'average_revenue' => self::average($row)];
        }

        return $out + ['conversion_rate' => $visitors ? round($row->uniq / $visitors * 100, 1) : 0, 'visitors' => $visitors];
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

    /** The earliest day with any data, imported or collected, for the "all time" period. */
    public function firstDataAt(): ?CarbonImmutable
    {
        $native = $this->firstVisitAt();
        if (! $this->imported->exists()) {
            return $native;
        }
        $imported = CarbonImmutable::parse($this->site->imported_start->toDateString(), $this->site->timezone);

        return $native && $native->lt($imported) ? $native : $imported;
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
            if ($range = $this->imported->range($p, $f)) {
                foreach ($this->imported->series($range, $p) as $label => $t) {
                    $b = $byBucket[$label] ?? [];
                    foreach (['visitors', 'visits', 'bounces', 'dur'] as $k) {
                        $b[$k] = (int) ($b[$k] ?? 0) + $t[$k];
                    }
                    $b['pageviews'] = (int) ($b['pageviews'] ?? 0) + $t['pageviews'];
                    $byBucket[$label] = $b;
                }
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
        $currency = $this->goalCurrency($f);
        $be = $this->bucket('e.created_at', $p);
        $conv = [];
        foreach ($this->eventsQ($p, $f)->selectRaw("{$be} as t, COUNT(DISTINCT e.visitor_id) as uniq, COUNT(*) as total".($currency ? ', '.self::revenueAgg($currency) : ''))->groupBy('t')->get() as $r) {
            $conv[$r->t] = $r;
        }
        $bv = $this->bucket('v.started_at', $p);
        $vis = [];
        foreach ($this->visitsQ($p, $f->without('goal'))->selectRaw("{$bv} as t, COUNT(DISTINCT v.visitor_id) as n")->groupBy('t')->get() as $r) {
            $vis[$r->t] = (int) $r->n;
        }
        $plot = ['unique_conversions' => [], 'total_conversions' => [], ...($currency ? ['total_revenue' => [], 'average_revenue' => []] : []), 'conversion_rate' => []];
        foreach ($labels as $l) {
            $u = (int) ($conv[$l]->uniq ?? 0);
            $plot['unique_conversions'][] = $u;
            $plot['total_conversions'][] = (int) ($conv[$l]->total ?? 0);
            if ($currency) {
                $plot['total_revenue'][] = round((float) ($conv[$l]->revenue ?? 0), 2);
                $plot['average_revenue'][] = isset($conv[$l]) ? self::average($conv[$l]) : 0;
            }
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
        if (($range = $this->imported->range($p, $f)) && Imported::supports($dim)) {
            return $this->mergedVisitRows($dim, $p, $f, $range, $limit, $offset, $search);
        }
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

    /**
     * Native rows and imported rows for a dimension added together by name, then ordered and paged as one list.
     *
     * @param  array{0:string,1:string}  $range
     */
    private function mergedVisitRows(string $dim, Period $p, Filters $f, array $range, int $limit, int $offset, ?string $search): array
    {
        $col = 'v.'.$dim;
        $q = $this->visitsQ($p, $f)->selectRaw("{$col} as name, ".self::AGG)->groupBy($col)->limit(5000);
        if ($dim !== 'source') {
            $q->whereNotNull($col)->where($col, '!=', '');
        }
        $this->applySearch($q, $col, $search);
        $merged = [];
        $add = function (string $key, array $r) use (&$merged) {
            $row = &$merged[$key];
            $row ??= ['name' => $key === '' ? null : $key, 'visitors' => 0, 'visits' => 0, 'bounces' => 0, 'dur' => 0, 'pv' => 0];
            foreach (['visitors', 'visits', 'bounces', 'dur', 'pv'] as $k) {
                $row[$k] += (int) $r[$k];
            }
            unset($row);
        };
        foreach ($q->get() as $r) {
            $add((string) ($r->name ?? ''), (array) $r);
        }
        foreach ($this->imported->breakdown($dim, $range, $search) as $r) {
            $add($r->name, (array) $r);
        }
        if ($dim === 'source' && $search === null && ($direct = $this->imported->direct($range))) {
            $add('', (array) $direct);
        }
        uasort($merged, fn ($a, $b) => [$b['visitors'], (string) $a['name']] <=> [$a['visitors'], (string) $b['name']]);
        $page = array_slice(array_values($merged), $offset, $limit);

        $names = array_values(array_filter(array_column($page, 'name')));
        $views = $dim === 'exit_page' ? $this->pageviewsByPath($p, $f, $names) : [];
        $importedViews = $dim === 'exit_page' ? $this->imported->lookup('page', $range, $names) : [];
        $out = [];
        foreach ($page as $r) {
            $d = self::derive($r);
            $row = ['name' => $r['name'] ?? Filters::DIRECT, 'visitors' => $d['visitors'], 'visits' => $d['visits'], 'pageviews' => $d['pageviews'], 'bounce_rate' => $d['bounce_rate'], 'visit_duration' => $d['visit_duration']];
            if ($dim === 'exit_page') {
                $total = ($views[$r['name']] ?? 0) + (int) ($importedViews[$r['name']]->pv ?? 0);
                $row['exit_rate'] = $total ? min(100, (int) round($d['visits'] / $total * 100)) : 0;
            }
            $out[] = $row;
        }

        return $out;
    }

    /** @param array{0:string,1:string} $range */
    private function mergedPageRows(Period $p, Filters $f, array $range, int $limit, int $offset, ?string $search): array
    {
        $q = $this->eventsQ($p, $f)->where('e.name', 'pageview')->selectRaw('e.pathname as name, COUNT(DISTINCT e.visitor_id) as visitors, COUNT(*) as pageviews')->groupBy('e.pathname')->limit(5000);
        $this->applySearch($q, 'e.pathname', $search);
        $merged = [];
        foreach ($q->get() as $r) {
            $merged[(string) $r->name] = ['name' => $r->name, 'visitors' => (int) $r->visitors, 'pageviews' => (int) $r->pageviews];
        }
        foreach ($this->imported->breakdown('page', $range, $search) as $r) {
            $row = &$merged[$r->name];
            $row ??= ['name' => $r->name, 'visitors' => 0, 'pageviews' => 0];
            $row['visitors'] += (int) $r->visitors;
            $row['pageviews'] += (int) $r->pv;
            unset($row);
        }
        uasort($merged, fn ($a, $b) => [$b['visitors'], (string) $a['name']] <=> [$a['visitors'], (string) $b['name']]);
        $page = array_slice(array_values($merged), $offset, $limit);

        $names = array_column($page, 'name');
        $visits = $bounces = [];
        if ($names) {
            foreach ($this->visitsQ($p, $f)->whereIn('v.entry_page', $names)->selectRaw('v.entry_page as name, COUNT(*) as visits, COALESCE(SUM(CASE WHEN v.is_bounce THEN 1 ELSE 0 END), 0) as bounces')->groupBy('v.entry_page')->get() as $r) {
                $visits[$r->name] = (int) $r->visits;
                $bounces[$r->name] = (int) $r->bounces;
            }
            foreach ($this->imported->lookup('entry_page', $range, $names) as $name => $r) {
                $visits[$name] = ($visits[$name] ?? 0) + (int) $r->visits;
                $bounces[$name] = ($bounces[$name] ?? 0) + (int) $r->bounces;
            }
        }

        return array_map(fn ($r) => ['name' => $r['name'], 'visitors' => $r['visitors'], 'pageviews' => $r['pageviews'], 'bounce_rate' => ($visits[$r['name']] ?? 0) ? (int) round($bounces[$r['name']] / $visits[$r['name']] * 100) : null], $page);
    }

    private function pageRows(Period $p, Filters $f, int $limit, int $offset, ?string $search): array
    {
        if ($range = $this->imported->range($p, $f)) {
            return $this->mergedPageRows($p, $f, $range, $limit, $offset, $search);
        }
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
        $currency = $this->goalCurrency($f);
        $q = $this->eventsQ($p, $f)->selectRaw("{$col} as name, COUNT(DISTINCT e.visitor_id) as uniq, COUNT(*) as total".($currency ? ', '.self::revenueAgg($currency) : ''))->groupBy($col)->orderByDesc('uniq')->orderBy('name')->limit($limit)->offset($offset);
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

            return ['name' => $name, 'visitors' => (int) $r->uniq, 'events' => (int) $r->total, 'conversion_rate' => $tot ? round($r->uniq / $tot * 100, 1) : 0]
                + (isset($r->revenue) ? ['revenue' => round((float) $r->revenue, 2), 'average_revenue' => self::average($r)] : []);
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
        $range = $this->imported->range($p, $f);
        $importedEvents = [];
        if ($range) {
            $visitors += $this->imported->totals($range)['visitors'];
            $importedEvents = $this->imported->events($range);
        }
        $out = [];
        foreach ($this->site->goals()->orderBy('created_at')->get() as $goal) {
            $g = new Filters([...$base->rules, ['is', 'goal', [$goal->name]]], $this->site);
            $currency = $this->currencyOf($goal);
            $r = $this->eventsQ($p, $g)->selectRaw('COUNT(DISTINCT e.visitor_id) as uniq, COUNT(*) as total'.($currency ? ', '.self::revenueAgg($currency) : ''))->first();
            $uniq = (int) $r->uniq;
            $total = (int) $r->total;
            if ($range) {
                $imp = $goal->type === 'page' ? $this->imported->pageGoal($range, $goal->value) : ($importedEvents[$goal->value] ?? ['visitors' => 0, 'events' => 0]);
                $uniq += $imp['visitors'];
                $total += $imp['events'];
            }
            $out[] = ['id' => $goal->id, 'name' => $goal->name, 'type' => $goal->type, 'visitors' => $uniq, 'events' => $total, 'conversion_rate' => $visitors ? round($uniq / $visitors * 100, 1) : 0]
                + ($currency ? ['currency' => $currency, 'revenue' => round((float) $r->revenue, 2), 'average_revenue' => self::average($r)] : []);
        }
        usort($out, fn ($a, $b) => $b['visitors'] <=> $a['visitors']);

        return $out;
    }

    /**
     * How many visitors got through each step of a funnel. A visitor completes a step when a matching event happens after the
     * event that completed the step before it; other activity in between is fine. Visit-level filters (country, source, device…)
     * narrow who is counted; filters on pages, goals and properties do not apply.
     *
     * @return array{name:string,steps:array<int,array<string,mixed>>,conversion_rate:float|int}
     */
    public function funnel(Funnel $funnel, Period $p, Filters $f): array
    {
        $steps = $funnel->steps()->with('goal')->get()->filter(fn ($s) => $s->goal)->values();
        $matchers = $steps->map(fn ($s) => $this->stepMatcher($s->goal))->all();
        $filters = new Filters(array_values(array_filter($f->rules, fn ($r) => isset(Filters::VISIT_DIMS[$r[1]]))), $this->site);
        $q = Sql::connection()->table('events as e')->join('visits as v', 'v.id', '=', 'e.visit_id')
            ->where('e.site_id', $this->site->id)->whereBetween('e.created_at', [$p->startSql(), $p->endSql()]);
        $filters->applyToEvents($q);
        $q->where(function ($w) use ($steps) {
            foreach ($steps as $s) {
                $w->orWhere(function ($x) use ($s) {
                    if ($s->goal->type === 'page') {
                        $x->where('e.name', 'pageview');
                        $v = $s->goal->value;
                        str_contains($v, '*') ? $x->where('e.pathname', Sql::like(), str_replace('*', '%', addcslashes($v, '%_\\'))) : $x->where('e.pathname', $v);
                    } else {
                        $x->where('e.name', $s->goal->value);
                    }
                });
            }
        });

        $reached = array_fill(0, max(1, $steps->count()), 0);
        $visitor = null;
        $next = 0;
        $close = function () use (&$reached, &$next, &$visitor) {
            if ($visitor !== null && $next > 0) {
                for ($i = 0; $i < $next; $i++) {
                    $reached[$i]++;
                }
            }
        };
        if ($steps->count() >= Funnel::MIN_STEPS) {
            foreach ($q->orderBy('e.visitor_id')->orderBy('e.created_at')->orderBy('e.id')->select('e.visitor_id', 'e.name', 'e.pathname')->cursor() as $e) {
                if ($e->visitor_id !== $visitor) {
                    $close();
                    $visitor = $e->visitor_id;
                    $next = 0;
                }
                if ($next < count($matchers) && $matchers[$next]($e->name, (string) $e->pathname)) {
                    $next++;
                }
            }
            $close();
        }

        $first = $reached[0];
        $out = [];
        foreach ($steps as $i => $s) {
            $n = $reached[$i];
            $prev = $i === 0 ? $n : $reached[$i - 1];
            $out[] = [
                'name' => $s->goal->name, 'visitors' => $n,
                'conversion_rate' => $first ? round($n / $first * 100, 1) : 0,
                'conversion_rate_step' => $prev ? round($n / $prev * 100, 1) : 0,
                'dropoff' => $i === 0 ? 0 : $prev - $n,
                'dropoff_percentage' => $i === 0 || ! $prev ? 0 : round(($prev - $n) / $prev * 100, 1),
            ];
        }
        $last = $out ? $out[count($out) - 1]['visitors'] : 0;

        return ['id' => $funnel->id, 'name' => $funnel->name, 'steps' => $out, 'conversion_rate' => $first ? round($last / $first * 100, 1) : 0];
    }

    /** @return \Closure(string,string):bool */
    private function stepMatcher(Goal $goal): \Closure
    {
        if ($goal->type !== 'page') {
            return fn (string $name) => $name === $goal->value;
        }
        $regex = '#^'.str_replace('\\*', '.*', preg_quote($goal->value, '#')).'$#u';

        return fn (string $name, string $path) => $name === 'pageview' && preg_match($regex, $path) === 1;
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
