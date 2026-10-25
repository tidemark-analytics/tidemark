<?php

namespace App\Alerts;

use App\Analytics\Stats\Stats;
use App\Analytics\Support\Filters;
use App\Analytics\Support\Period;
use App\Models\AlertRule;
use App\Models\Site;
use Carbon\CarbonImmutable;

/**
 * Looks at a site's numbers and says whether a rule fires. Only aggregates are used. A rule keeps what it needs between runs in its
 * `state` (the evaluator changes it; the caller saves it), so a condition that stays true is announced once, not every five minutes.
 */
class RuleEvaluator
{
    /** The least time between two alerts of the same rule, in minutes. */
    public const COOLDOWN = ['traffic_spike' => 360, 'traffic_drop' => 360, 'goal_milestone' => 0, 'goal_conversions' => 0, 'new_top_referrer' => 0];

    /** @return array{event:string,summary:string,data:array}|null */
    public function evaluate(AlertRule $rule, Site $site, CarbonImmutable $now): ?array
    {
        $now = $now->setTimezone($site->timezone);
        $stats = new Stats($site);

        return match ($rule->type) {
            'traffic_spike' => $this->spike($rule, $site, $stats, $now),
            'traffic_drop' => $this->drop($rule, $site, $stats, $now),
            'goal_milestone' => $this->milestone($rule, $site, $stats, $now),
            'goal_conversions' => $this->conversions($rule, $site, $stats, $now),
            'new_top_referrer' => $this->newReferrer($rule, $site, $stats, $now),
            default => null,
        };
    }

    public static function inQuietHours(AlertRule $rule, CarbonImmutable $now): bool
    {
        if (! $rule->quiet_start || ! $rule->quiet_end || $rule->quiet_start === $rule->quiet_end) {
            return false;
        }
        $t = $now->format('H:i');

        return $rule->quiet_start < $rule->quiet_end ? ($t >= $rule->quiet_start && $t < $rule->quiet_end) : ($t >= $rule->quiet_start || $t < $rule->quiet_end);
    }

    private function period(CarbonImmutable $from, CarbonImmutable $to, string $tz): Period
    {
        return new Period('custom', $from->utc(), $to->utc(), 'hour', $tz);
    }

    /** Visitors so far today, and the average of the same stretch of the day on the last four weeks' same weekday. */
    private function todayAndUsual(Site $site, Stats $stats, CarbonImmutable $now): array
    {
        $start = $now->startOfDay();
        $span = $now->getTimestamp() - $start->getTimestamp();
        $f = new Filters([], $site);
        $today = $stats->visitorCount($this->period($start, $now, $site->timezone), $f);
        $past = [];
        for ($w = 1; $w <= 4; $w++) {
            $from = $start->subWeeks($w);
            $past[] = $stats->visitorCount($this->period($from, $from->addSeconds($span), $site->timezone), $f);
        }

        return [$today, array_sum($past) / 4, $span];
    }

    private function spike(AlertRule $rule, Site $site, Stats $stats, CarbonImmutable $now): ?array
    {
        [$today, $usual] = $this->todayAndUsual($site, $stats, $now);
        $pct = (int) ($rule->config['percent'] ?? 100);
        $min = (int) ($rule->config['min_visitors'] ?? 50);
        if ($today - $usual < $min || $today < $usual * (1 + $pct / 100)) {
            return null;
        }
        $change = $usual > 0 ? round(($today - $usual) / $usual * 100) : null;

        return $this->event('traffic.spike', "Traffic is up on {$site->domain}: {$today} visitors so far today".($change !== null ? ", {$change}% more than usual for a ".$now->format('l') : '').'.', ['visitors_today' => $today, 'usual_visitors' => round($usual, 1), 'change_percent' => $change]);
    }

    private function drop(AlertRule $rule, Site $site, Stats $stats, CarbonImmutable $now): ?array
    {
        if ($now->hour < 6) { // too early in the day to say anything
            return null;
        }
        [$today, $usual] = $this->todayAndUsual($site, $stats, $now);
        $pct = (int) ($rule->config['percent'] ?? 50);
        $min = (int) ($rule->config['min_visitors'] ?? 50);
        if ($usual < $min || $today > $usual * (1 - $pct / 100)) {
            return null;
        }
        $change = round(($usual - $today) / $usual * 100);

        return $this->event('traffic.drop', "Traffic is down on {$site->domain}: {$today} visitors so far today, {$change}% fewer than usual for a ".$now->format('l').'.', ['visitors_today' => $today, 'usual_visitors' => round($usual, 1), 'change_percent' => -$change]);
    }

    private function goalStats(AlertRule $rule, Site $site, Stats $stats, CarbonImmutable $now): ?array
    {
        $goal = $site->goals()->find($rule->config['goal_id'] ?? null);
        if (! $goal) {
            return null;
        }
        $top = $stats->topStats($this->period($now->startOfDay(), $now, $site->timezone), new Filters([['is', 'goal', [$goal->name]]], $site));

        return ['goal' => $goal->name, 'unique' => (int) $top['unique_conversions'], 'total' => (int) $top['total_conversions'], 'revenue' => $top['total_revenue'] ?? null, 'currency' => $stats->goalCurrency(new Filters([['is', 'goal', [$goal->name]]], $site))];
    }

    private function milestone(AlertRule $rule, Site $site, Stats $stats, CarbonImmutable $now): ?array
    {
        $g = $this->goalStats($rule, $site, $stats, $now);
        $count = (int) ($rule->config['count'] ?? 0);
        $today = $now->toDateString();
        if (! $g || $count < 1 || $g['unique'] < $count || ($rule->state['milestone_date'] ?? null) === $today) {
            return null;
        }
        $rule->state = ['milestone_date' => $today];

        return $this->event('goal.milestone', "“{$g['goal']}” reached {$count} conversion".($count === 1 ? '' : 's')." today on {$site->domain}.", ['goal' => $g['goal'], 'milestone' => $count, 'conversions_today' => $g['unique']] + ($g['revenue'] !== null ? ['revenue_today' => $g['revenue'], 'currency' => $g['currency']] : []));
    }

    private function conversions(AlertRule $rule, Site $site, Stats $stats, CarbonImmutable $now): ?array
    {
        $g = $this->goalStats($rule, $site, $stats, $now);
        if (! $g) {
            return null;
        }
        $today = $now->toDateString();
        $state = $rule->state ?? [];
        $first = ! isset($state['date']);
        // the first run only notes where things stand, so conversions from before the rule existed aren't announced
        $seen = $first ? $g['total'] : (($state['date'] ?? null) === $today ? (int) $state['total'] : 0);
        $rule->state = ['date' => $today, 'total' => $g['total']];
        $new = $g['total'] - $seen;
        if ($new < 1) {
            return null;
        }

        return $this->event('goal.conversions', "{$new} new conversion".($new === 1 ? '' : 's')." of “{$g['goal']}” on {$site->domain} ({$g['total']} today).", ['goal' => $g['goal'], 'new_conversions' => $new, 'conversions_today' => $g['total']] + ($g['revenue'] !== null ? ['revenue_today' => $g['revenue'], 'currency' => $g['currency']] : []));
    }

    private function newReferrer(AlertRule $rule, Site $site, Stats $stats, CarbonImmutable $now): ?array
    {
        $min = (int) ($rule->config['min_visitors'] ?? 20);
        $f = new Filters([], $site);
        $today = $now->toDateString();
        $told = ($rule->state['date'] ?? null) === $today ? ($rule->state['told'] ?? []) : [];
        $rows = $stats->breakdown('source', $this->period($now->startOfDay(), $now, $site->timezone), $f, ['limit' => 10])['results'];
        $fresh = array_values(array_filter($rows, fn ($r) => $r['name'] !== Filters::DIRECT && $r['visitors'] >= $min && ! in_array($r['name'], $told, true)));
        if (! $fresh) {
            return null;
        }
        $before = $stats->breakdown('source', $this->period($now->startOfDay()->subDays(28), $now->startOfDay()->subSecond(), $site->timezone), $f, ['limit' => 100])['results'];
        $known = array_column($before, 'name');
        $new = array_values(array_filter($fresh, fn ($r) => ! in_array($r['name'], $known, true)));
        $rule->state = ['date' => $today, 'told' => array_values(array_unique([...$told, ...array_column($fresh, 'name')]))];
        if (! $new) {
            return null;
        }
        $names = implode(', ', array_column($new, 'name'));

        return $this->event('traffic.new_source', "New traffic source on {$site->domain}: {$names}.", ['sources' => array_map(fn ($r) => ['name' => $r['name'], 'visitors' => $r['visitors']], $new)]);
    }

    private function event(string $event, string $summary, array $data): array
    {
        return ['event' => $event, 'summary' => $summary, 'data' => $data];
    }
}
