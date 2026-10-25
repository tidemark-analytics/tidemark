<?php

namespace App\Http\Controllers\Api;

use App\Analytics\Stats\Stats;
use App\Analytics\Support\Filters;
use App\Analytics\Support\Period;
use App\Http\Controllers\Controller;
use App\Models\Annotation;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Plausible Stats API v1 (the common subset): /api/v1/stats/{realtime/visitors,aggregate,timeseries,breakdown}.
 * Authenticate with `Authorization: Bearer <api key>`; `site_id` is the site's domain.
 */
class StatsApiController extends Controller
{
    private const METRICS = ['visitors', 'visits', 'pageviews', 'views_per_visit', 'bounce_rate', 'visit_duration', 'events', 'conversion_rate', 'total_revenue', 'average_revenue'];

    /** With a goal filter the stats are conversions: `visitors` are the unique conversions and `events` the total ones, as in Plausible. */
    private static function asGoalMetrics(array $stats, Filters $f): array
    {
        return $f->goalName() && isset($stats['unique_conversions']) ? $stats + ['visitors' => $stats['unique_conversions'], 'events' => $stats['total_conversions']] : $stats;
    }

    private const PROPS = [
        'event:page' => 'page', 'visit:entry_page' => 'entry_page', 'visit:exit_page' => 'exit_page', 'visit:source' => 'source', 'visit:referrer' => 'referrer',
        'visit:channel' => 'channel', 'visit:utm_medium' => 'utm_medium', 'visit:utm_source' => 'utm_source', 'visit:utm_campaign' => 'utm_campaign',
        'visit:utm_content' => 'utm_content', 'visit:utm_term' => 'utm_term', 'visit:country' => 'country', 'visit:region' => 'region', 'visit:city' => 'city',
        'visit:browser' => 'browser', 'visit:os' => 'os', 'visit:device' => 'device',
    ];

    /** @return array{0:Site,1:Stats,2:Period,3:Filters} */
    private function ctx(Request $r): array
    {
        $domain = strtolower((string) $r->input('site_id'));
        $site = Site::where('domain', $domain)->first();
        abort_if(! $site || $site->roleOf($r->user()) === null, 404, 'Site not found');
        $tz = $site->timezone;
        $period = (string) $r->input('period', '30d');
        $date = (string) $r->input('date', '');
        $now = CarbonImmutable::now($tz);
        $p = match ($period) {
            'day' => Period::resolve('day', $date ? CarbonImmutable::parse($date, $tz) : $now, $tz),
            '7d', '30d', 'month', '6mo', '12mo', 'year' => Period::resolve($period, $date ? CarbonImmutable::parse($date, $tz) : $now, $tz),
            'custom' => (function () use ($date, $tz) {
                [$from, $to] = array_pad(explode(',', $date), 2, null);
                abort_if(! $from || ! $to, 422, 'date must be "YYYY-MM-DD,YYYY-MM-DD" for custom periods');

                return Period::resolve('custom', CarbonImmutable::parse($to, $tz), $tz, $from, $to);
            })(),
            default => abort(422, 'Unsupported period'),
        };
        if ($r->filled('interval') && in_array($r->input('interval'), $p->allowedIntervals(), true)) {
            $p->interval = $r->input('interval');
        }

        return [$site, new Stats($site), $p, $this->filters((string) $r->input('filters', ''), $site)];
    }

    /** "event:page==/blog/*;visit:country==US|DE" → internal filter rules. */
    private function filters(string $raw, Site $site): Filters
    {
        $rules = [];
        foreach (array_filter(explode(';', $raw)) as $clause) {
            if (! preg_match('/^(.+?)(==|!=|=~)(.+)$/', $clause, $m)) {
                continue;
            }
            $dim = $m[1] === 'event:goal' ? 'goal' : (str_starts_with($m[1], 'event:props:') ? 'prop:'.substr($m[1], 12) : (self::PROPS[$m[1]] ?? null));
            if ($dim) {
                $rules[] = [['==' => 'is', '!=' => 'is_not', '=~' => 'contains'][$m[2]], $dim, explode('|', $m[3])];
            }
        }

        return new Filters(Filters::sanitize($rules), $site);
    }

    private function metrics(Request $r): array
    {
        $m = array_values(array_intersect(array_map('trim', explode(',', (string) $r->input('metrics', 'visitors'))), self::METRICS));

        return $m ?: ['visitors'];
    }

    /** Notes on the graph for the period that the key's owner may see. */
    public function annotations(Request $r)
    {
        [$site, , $p] = $this->ctx($r);
        $role = $site->roleOf($r->user());
        $rows = Annotation::where('site_id', $site->id)->visibleTo($r->user(), $role)->whereBetween('date', [$p->start->toDateString(), $p->end->toDateString()])
            ->orderBy('date')->orderBy('created_at')->get();

        return ['results' => $rows->map(fn ($a) => ['date' => $a->date->toDateString(), 'note' => $a->note, 'visibility' => $a->visibility])->all(), 'period' => $p->toArray()];
    }

    public function realtime(Request $r)
    {
        [, $stats, , $f] = $this->ctx($r);

        return $stats->currentVisitors($f);
    }

    public function aggregate(Request $r)
    {
        [, $stats, $p, $f] = $this->ctx($r);
        $metrics = $this->metrics($r);
        $cur = self::asGoalMetrics($stats->topStats($p, $f), $f);
        $out = [];
        $prev = $r->input('compare') === 'previous_period' ? self::asGoalMetrics($stats->topStats($p->comparison('previous_period'), $f), $f) : null;
        foreach ($metrics as $m) {
            if (! array_key_exists($m, $cur)) {
                continue;
            }
            $out[$m] = ['value' => $cur[$m]] + ($prev ? ['change' => ($prev[$m] ?? 0) ? round(($cur[$m] - $prev[$m]) / $prev[$m] * 100) : null] : []);
        }

        return ['results' => $out];
    }

    public function timeseries(Request $r)
    {
        [, $stats, $p, $f] = $this->ctx($r);
        $metrics = $this->metrics($r);
        $s = $stats->timeseries($p, $f);
        $s['plot'] = self::asGoalMetrics($s['plot'], $f);
        $rows = [];
        foreach ($s['labels'] as $i => $label) {
            $row = ['date' => in_array($p->interval, ['hour', 'minute'], true) ? str_replace(' ', ' ', substr($label, 0, 19)) : substr($label, 0, 10)];
            foreach ($metrics as $m) {
                if (isset($s['plot'][$m])) {
                    $row[$m] = $s['plot'][$m][$i];
                }
            }
            $rows[] = $row;
        }

        return ['results' => $rows];
    }

    public function breakdown(Request $r)
    {
        [, $stats, $p, $f] = $this->ctx($r);
        $prop = (string) $r->input('property');
        $dim = self::PROPS[$prop] ?? null;
        abort_if(! $dim, 422, 'Unsupported property');
        $limit = min(1000, max(1, (int) $r->input('limit', 100)));
        $rows = $stats->breakdown($dim, $p, $f, ['limit' => $limit, 'page' => $r->input('page', 1)])['results'];
        $metrics = $this->metrics($r);
        $key = substr($prop, strpos($prop, ':') + 1);

        return ['results' => array_map(fn ($row) => [$key => $row['name']] + array_intersect_key($row, array_flip($metrics)), $rows)];
    }
}
