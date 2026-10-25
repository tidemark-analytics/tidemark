<?php

namespace App\Http\Controllers\Api;

use App\Analytics\Stats\Stats;
use App\Analytics\Support\Filters;
use App\Analytics\Support\Period;
use App\Analytics\Support\SmallGroups;
use App\Http\Controllers\Controller;
use App\Models\Annotation;
use App\Models\Funnel;
use App\Models\Site;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    /** @return array{0:Site,1:Stats,2:Period,3:Filters} */
    private function ctx(Request $r): array
    {
        /** @var Site $site */
        $site = $r->attributes->get('site');
        $stats = new Stats($site);
        $first = $r->input('period') === 'all' ? $stats->firstDataAt() : null;
        $p = Period::fromRequest($r, $site->timezone, $first);
        $f = Filters::fromRequest($r, $site);
        // a public view can't narrow down to a group smaller than the site's minimum: such a filter shows no data at all
        if (($min = $this->minimum($r, $site)) && $f->rules && $stats->visitorCount($p, $f) < $min) {
            $f->nothing = true;
        }

        return [$site, $stats, $p, $f];
    }

    /** The smallest group this viewer may see: the site's minimum for public and shared views, nothing for people who belong to the site. */
    private function minimum(Request $r, Site $site): int
    {
        $min = (int) $site->min_group_size;

        return $r->attributes->get('role') === null && $min >= 2 ? $min : 0;
    }

    /** The notes on the graph for this window that this viewer may see (none for the realtime view). */
    private function annotations(Request $r, Site $site, Period $p): array
    {
        if ($p->key === 'realtime') {
            return [];
        }

        return Annotation::where('site_id', $site->id)->visibleTo($r->user(), $r->attributes->get('role'))
            ->whereBetween('date', [$p->start->toDateString(), $p->end->toDateString()])->orderBy('date')->orderBy('created_at')->get()
            ->map(fn ($a) => AnnotationController::present($a, $r->user(), $r->attributes->get('role')))->all();
    }

    private static function change(float|int|null $cur, float|int|null $prev): ?float
    {
        if ($prev === null || $prev == 0) {
            return $cur ? null : 0.0;
        }

        return round(($cur - $prev) / $prev * 100, 1);
    }

    /** The top-stats row: current value, previous value and percentage change for each metric. */
    public function top(Request $r)
    {
        [, $stats, $p, $f] = $this->ctx($r);
        $mode = $r->input('comparison') === 'year_over_year' ? 'year_over_year' : 'previous_period';
        $cur = $stats->topStats($p, $f);
        $before = in_array($p->key, ['all', 'realtime'], true) ? null : $p->comparison($mode);
        $prev = $before ? $stats->topStats($before, $f) : null;

        $out = [];
        foreach ($cur as $k => $v) {
            $out[$k] = ['value' => $v, 'previous' => $prev[$k] ?? null, 'change' => $prev ? self::change($v, $prev[$k] ?? null) : null];
        }

        return ['period' => $p->toArray(), 'kind' => $f->goalName() ? 'goal' : 'visitors', 'currency' => $stats->goalCurrency($f), 'stats' => $out, 'comparing' => $prev !== null, 'comparison_period' => $before?->toArray()];
    }

    public function graph(Request $r)
    {
        [$site, $stats, $p, $f] = $this->ctx($r);
        $series = $stats->timeseries($p, $f);
        $comparison = null;
        $mode = (string) $r->input('comparison', '');
        if (in_array($mode, ['previous_period', 'year_over_year'], true) && ! in_array($p->key, ['all', 'realtime'], true)) {
            $cp = $p->comparison($mode);
            $cp->interval = $p->interval;
            $comparison = $stats->timeseries($cp, $f);
        }

        return $series + ['period' => $p->toArray(), 'interval' => $p->interval, 'intervals' => $p->allowedIntervals(), 'comparison' => $comparison, 'kind' => $f->goalName() ? 'goal' : 'visitors', 'currency' => $stats->goalCurrency($f), 'annotations' => $this->annotations($r, $site, $p)];
    }

    public function breakdown(Request $r, string $site, string $dim)
    {
        [$s, $stats, $p, $f] = $this->ctx($r);
        $data = SmallGroups::trim($stats->breakdown($dim, $p, $f, ['limit' => $r->input('limit', 9), 'page' => $r->input('page', 1), 'search' => $r->input('search')]), $this->minimum($r, $s));

        return $data + ['mode' => $f->goalName() ? 'conversions' : 'visitors', 'currency' => $stats->goalCurrency($f)];
    }

    public function goals(Request $r)
    {
        [, $stats, $p, $f] = $this->ctx($r);

        return ['results' => $stats->goals($p, $f)];
    }

    /** The site's funnels (name and number of steps), for the dashboard's funnel picker. */
    public function funnels(Request $r)
    {
        [$site] = $this->ctx($r);
        if (! $site->funnelsAllowed()) {
            return ['allowed' => false, 'results' => []];
        }

        return ['allowed' => true, 'results' => $site->funnels()->withCount('steps')->orderBy('created_at')->get()->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'steps' => $f->steps_count])->all()];
    }

    public function funnel(Request $r, string $site, string $funnel)
    {
        [$s, $stats, $p, $f] = $this->ctx($r);
        abort_unless($s->funnelsAllowed(), 403, 'Funnels are not available on this plan.');

        return $stats->funnel($s->funnels()->findOrFail($funnel), $p, $f);
    }

    /** One column of the journeys explorer: what visitors did after (or before) the steps chosen so far. */
    public function journeys(Request $r)
    {
        [$site, $stats, $p, $f] = $this->ctx($r);
        if (! $site->journeysAllowed()) {
            return ['allowed' => false, 'total' => 0, 'items' => [], 'has_more' => false];
        }
        $raw = json_decode((string) $r->input('steps', '[]'), true);
        $steps = [];
        foreach (array_slice(is_array($raw) ? $raw : [], 0, 8) as $step) {
            if (is_array($step) && in_array($step[0] ?? null, ['page', 'group', 'event'], true) && is_string($step[1] ?? null) && $step[1] !== '') {
                $steps[] = [$step[0], mb_substr($step[1], 0, 500)];
            }
        }
        $direction = $r->input('direction') === 'end' ? 'end' : 'start';

        $out = $stats->journeys($p, $f, $steps, $direction, $r->input('search'), min(50, max(1, (int) $r->input('limit', 12))));
        $min = $this->minimum($r, $site);
        if ($min && $out['total'] < $min) {
            $out = ['total' => 0, 'items' => [], 'has_more' => false, 'hidden_below' => $min] + $out;
        } else {
            $out = SmallGroups::trim($out, $min, 'items');
        }

        return ['allowed' => true] + $out;
    }

    public function props(Request $r)
    {
        [, $stats, $p, $f] = $this->ctx($r);

        return ['results' => $stats->propKeys($p, $f)];
    }

    public function propValues(Request $r, string $site, string $key)
    {
        [$s, $stats, $p, $f] = $this->ctx($r);

        return SmallGroups::trim($stats->propValues($key, $p, $f, (int) $r->input('limit', 9), (int) $r->input('page', 1), $r->input('search')), $this->minimum($r, $s));
    }

    public function currentVisitors(Request $r)
    {
        [, $stats, , $f] = $this->ctx($r);

        return ['visitors' => $stats->currentVisitors($f)];
    }

    /** CSV export of everything on the dashboard, zipped (one file per panel), for the selected period and filters. */
    public function export(Request $r)
    {
        [$site, $stats, $p, $f] = $this->ctx($r);
        $csv = function (array $header, array $rows) {
            $h = fopen('php://temp', 'r+');
            fputcsv($h, $header);
            foreach ($rows as $row) {
                fputcsv($h, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, $row)); // no spreadsheet formula injection
            }
            rewind($h);

            return stream_get_contents($h);
        };

        $files = [];
        $s = $stats->timeseries($p, $f);
        $keys = array_keys($s['plot']);
        $rows = [];
        foreach ($s['labels'] as $i => $l) {
            $rows[] = array_merge([substr($l, 0, $p->interval === 'hour' || $p->interval === 'minute' ? 16 : 10)], array_map(fn ($k) => $s['plot'][$k][$i], $keys));
        }
        $files['visitors.csv'] = $csv(array_merge(['date'], $keys), $rows);
        if ($notes = $this->annotations($r, $site, $p)) {
            $files['annotations.csv'] = $csv(['date', 'note', 'shared_with'], array_map(fn ($a) => [$a['date'], $a['note'], ['private' => 'only the author', 'team' => 'the site', 'public' => 'public views'][$a['visibility']]], $notes));
        }
        foreach (['source' => 'sources', 'channel' => 'channels', 'utm_campaign' => 'campaigns', 'page' => 'pages', 'entry_page' => 'entry_pages', 'exit_page' => 'exit_pages',
            'country' => 'countries', 'region' => 'regions', 'city' => 'cities', 'browser' => 'browsers', 'os' => 'operating_systems', 'device' => 'devices'] as $dim => $name) {
            $res = SmallGroups::trim($stats->breakdown($dim, $p, $f, ['limit' => 1000]), $this->minimum($r, $site))['results'];
            $files[$name.'.csv'] = $csv(['name', 'visitors', 'visits', 'pageviews', 'bounce_rate', 'visit_duration'], array_map(fn ($x) => [$x['name'], $x['visitors'], $x['visits'] ?? '', $x['pageviews'] ?? '', $x['bounce_rate'] ?? '', $x['visit_duration'] ?? ''], $res));
        }
        $goals = $stats->goals($p, $f);
        $files['goals.csv'] = $csv(['name', 'unique_conversions', 'total_conversions', 'conversion_rate', 'currency', 'revenue', 'average_revenue'], array_map(fn ($g) => [$g['name'], $g['visitors'], $g['events'], $g['conversion_rate'], $g['currency'] ?? '', $g['revenue'] ?? '', $g['average_revenue'] ?? ''], $goals));
        if ($site->funnelsAllowed()) {
            $rows = [];
            foreach ($site->funnels()->orderBy('created_at')->get() as $funnel) {
                foreach ($stats->funnel($funnel, $p, $f)['steps'] as $i => $step) {
                    $rows[] = [$funnel->name, $i + 1, $step['name'], $step['visitors'], $step['conversion_rate'], $step['conversion_rate_step'], $step['dropoff']];
                }
            }
            if ($rows) {
                $files['funnels.csv'] = $csv(['funnel', 'step', 'goal', 'visitors', 'conversion_rate', 'conversion_rate_from_previous', 'dropoff'], $rows);
            }
        }

        $fileName = $site->domain.'_'.$p->start->format('Ymd').'_to_'.$p->end->format('Ymd');
        if (! class_exists(\ZipArchive::class)) {
            return response($files['visitors.csv'], 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename=\"{$fileName}.csv\""]);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'tdm');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($files as $n => $content) {
            $zip->addFromString($n, $content);
        }
        $zip->close();

        return response()->download($tmp, $fileName.'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }
}
