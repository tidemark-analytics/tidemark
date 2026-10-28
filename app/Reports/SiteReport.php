<?php

namespace App\Reports;

use App\Analytics\Stats\Stats;
use App\Analytics\Support\Filters;
use App\Analytics\Support\Period;
use App\Models\Annotation;
use App\Models\Site;
use App\Models\User;

/** The numbers in one report e-mail: totals against the period before, the top pages, sources and countries, goals and notes. */
class SiteReport
{
    public const METRICS = [
        'visitors' => 'Visitors',
        'visits' => 'Visits',
        'pageviews' => 'Pageviews',
        'bounce_rate' => 'Bounce rate',
        'visit_duration' => 'Visit duration',
    ];

    public static function build(Site $site, ReportWindow $w, User $user): array
    {
        $stats = new Stats($site);
        $f = new Filters([], $site);
        $p = Period::resolve('custom', $w->end, $site->timezone, $w->start->toDateString(), $w->end->toDateString());
        $cur = $stats->topStats($p, $f);
        $prev = $stats->topStats($p->comparison('previous_period'), $f);

        $metrics = [];
        foreach (self::METRICS as $key => $label) {
            $metrics[] = ['key' => $key, 'label' => $label, 'value' => self::format($key, $cur[$key]), 'change' => self::change($cur[$key], $prev[$key]), 'good_when_down' => $key === 'bounce_rate'];
        }

        $role = $site->memberRole($user);

        return [
            'site' => $site,
            'window' => $w,
            'empty' => ($cur['visitors'] ?? 0) === 0,
            'visitors' => $cur['visitors'],
            'metrics' => $metrics,
            'pages' => self::rows($stats->breakdown('page', $p, $f, ['limit' => 5])['results'], 'pageviews'),
            'sources' => self::rows($stats->breakdown('source', $p, $f, ['limit' => 5])['results'], 'visitors'),
            'countries' => array_map(fn ($r) => ['name' => self::country($r['name'])] + $r, self::rows($stats->breakdown('country', $p, $f, ['limit' => 5])['results'], 'visitors')),
            'goals' => array_slice(array_values(array_filter($stats->goals($p, $f), fn ($g) => $g['visitors'] > 0)), 0, 5),
            'notes' => Annotation::where('site_id', $site->id)->visibleTo($user, $role)->whereBetween('date', [$w->start->toDateString(), $w->end->toDateString()])
                ->orderBy('date')->limit(10)->get()->map(fn ($a) => ['date' => $a->date->format('j M'), 'note' => $a->note])->all(),
        ];
    }

    /** Percentage change, or null when there is nothing to compare against. */
    public static function change(float|int|null $cur, float|int|null $prev): ?float
    {
        return $prev ? round(($cur - $prev) / $prev * 100, 1) : null;
    }

    public static function format(string $key, float|int $v): string
    {
        return match ($key) {
            'bounce_rate' => $v.'%',
            'visit_duration' => $v >= 60 ? intdiv((int) $v, 60).'m '.((int) $v % 60).'s' : (int) $v.'s',
            default => number_format($v),
        };
    }

    private static function country(string $code): string
    {
        return strlen($code) === 2 && class_exists(\Locale::class) ? (\Locale::getDisplayRegion('-'.strtoupper($code), 'en') ?: $code) : $code;
    }

    /** Keep a row's name and the number that ranks it. */
    private static function rows(array $rows, string $by): array
    {
        return array_map(fn ($r) => ['name' => (string) $r['name'], 'value' => number_format($r[$by])], $rows);
    }
}
