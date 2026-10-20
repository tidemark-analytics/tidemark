<?php

namespace App\Analytics\Support;

use App\Models\Site;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * Dashboard filters, Plausible v2 style: [["is", "country", ["US"]], ["contains", "page", ["/blog"]], ...]
 * Operators: is, is_not, contains, contains_not. A `*` in an `is` value is a wildcard.
 */
class Filters
{
    /** dimension => visits column */
    public const VISIT_DIMS = [
        'channel' => 'channel', 'source' => 'source', 'referrer' => 'referrer',
        'utm_medium' => 'utm_medium', 'utm_source' => 'utm_source', 'utm_campaign' => 'utm_campaign',
        'utm_content' => 'utm_content', 'utm_term' => 'utm_term',
        'country' => 'country', 'region' => 'region', 'city' => 'city',
        'browser' => 'browser', 'os' => 'os', 'device' => 'device',
        'entry_page' => 'entry_page', 'exit_page' => 'exit_page',
    ];

    /** dimension => events column */
    public const EVENT_DIMS = ['page' => 'pathname', 'hostname' => 'hostname'];

    public const DIRECT = 'Direct / None';

    /** @param array<int,array{0:string,1:string,2:array<int,string>}> $rules */
    public function __construct(public array $rules = [], public ?Site $site = null) {}

    public static function fromRequest(Request $r, ?Site $site = null): self
    {
        $raw = $r->input('filters');
        $list = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);

        return new self(self::sanitize(is_array($list) ? $list : []), $site);
    }

    public static function sanitize(array $list): array
    {
        $out = [];
        foreach (array_slice($list, 0, 20) as $r) {
            if (! is_array($r) || count($r) < 3) {
                continue;
            }
            [$op, $dim, $values] = $r;
            $values = array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 300) : null, (array) $values), fn ($v) => $v !== null && $v !== ''));
            $known = isset(self::VISIT_DIMS[$dim]) || isset(self::EVENT_DIMS[$dim]) || $dim === 'goal' || str_starts_with((string) $dim, 'prop:');
            if (in_array($op, ['is', 'is_not', 'contains', 'contains_not'], true) && $known && $values) {
                $out[] = [$op, $dim, $values];
            }
        }

        return $out;
    }

    public function has(string $dim): bool
    {
        return (bool) array_filter($this->rules, fn ($r) => $r[1] === $dim);
    }

    public function without(string ...$dims): self
    {
        return new self(array_values(array_filter($this->rules, fn ($r) => ! in_array($r[1], $dims, true))), $this->site);
    }

    public function goalName(): ?string
    {
        foreach ($this->rules as [$op, $dim, $values]) {
            if ($dim === 'goal' && $op === 'is') {
                return $values[0];
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------------------------------------------
    /** Constrain a query over `visits as v`. Event-level rules become EXISTS subqueries. */
    public function applyToVisits(Builder $q): Builder
    {
        foreach ($this->rules as [$op, $dim, $values]) {
            if (isset(self::VISIT_DIMS[$dim])) {
                $this->where($q, 'v.'.self::VISIT_DIMS[$dim], $op, $values, $dim);
            } else {
                $dim2 = $dim;
                $q->whereExists(function ($sub) use ($op, $dim2, $values) {
                    $sub->from('events as fe')->selectRaw('1')->whereColumn('fe.visit_id', 'v.id')->whereColumn('fe.site_id', 'v.site_id');
                    $this->eventRule($sub, 'fe', $op, $dim2, $values);
                });
            }
        }

        return $q;
    }

    /** Constrain a query over `events as e join visits as v`. */
    public function applyToEvents(Builder $q): Builder
    {
        foreach ($this->rules as [$op, $dim, $values]) {
            if (isset(self::VISIT_DIMS[$dim])) {
                $this->where($q, 'v.'.self::VISIT_DIMS[$dim], $op, $values, $dim);
            } else {
                $this->eventRule($q, 'e', $op, $dim, $values);
            }
        }

        return $q;
    }

    private function eventRule(Builder $q, string $alias, string $op, string $dim, array $values): void
    {
        if (isset(self::EVENT_DIMS[$dim])) {
            $this->where($q, "{$alias}.".self::EVENT_DIMS[$dim], $op, $values, $dim);
        } elseif ($dim === 'goal') {
            $this->goal($q, $alias, $op, $values);
        } else { // prop:key
            $key = substr($dim, 5);
            $negate = in_array($op, ['is_not', 'contains_not'], true);
            $positive = $negate ? ($op === 'is_not' ? 'is' : 'contains') : $op;
            $method = $negate ? 'whereNotExists' : 'whereExists';
            $q->{$method}(function ($p) use ($alias, $key, $positive, $values) {
                $p->from('event_props as pp')->selectRaw('1')->whereColumn('pp.event_id', "{$alias}.id")->where('pp.key', $key);
                $this->where($p, 'pp.value', $positive, $values, 'prop');
            });
        }
    }

    /** `goal is X`: events that satisfy goal X (a custom event name, or pageviews of a path). */
    private function goal(Builder $q, string $alias, string $op, array $values): void
    {
        $negate = $op === 'is_not';
        $q->where(function ($w) use ($alias, $values, $negate) {
            foreach ($values as $name) {
                $goal = $this->site?->goals()->get()->first(fn ($g) => $g->name === $name || ($g->type === 'event' && $g->value === $name));
                $w->{$negate ? 'where' : 'orWhere'}(function ($x) use ($alias, $goal, $name, $negate) {
                    if ($goal && $goal->type === 'page') {
                        $x->where("{$alias}.name", $negate ? '!=' : '=', 'pageview');
                        $this->where($x, "{$alias}.pathname", $negate ? 'is_not' : 'is', [$goal->value], 'page');
                    } else {
                        $x->where("{$alias}.name", $negate ? '!=' : '=', $goal?->value ?? $name);
                    }
                });
            }
        });
    }

    private function where(Builder $q, string $col, string $op, array $values, string $dim): void
    {
        $negate = in_array($op, ['is_not', 'contains_not'], true);
        $contains = in_array($op, ['contains', 'contains_not'], true);
        $match = function ($x, string $v) use ($col, $contains, $dim) {
            if ($dim === 'source' && $v === self::DIRECT) {
                $x->whereNull($col);
            } elseif ($contains) {
                $x->where($col, Sql::like(), '%'.addcslashes($v, '%_\\').'%');
            } elseif (str_contains($v, '*')) {
                $x->where($col, Sql::like(), str_replace('*', '%', addcslashes($v, '%_\\')));
            } else {
                $x->where($col, $v);
            }
        };

        if (! $negate) {
            $q->where(function ($w) use ($values, $match) {
                foreach ($values as $v) {
                    $w->orWhere(fn ($x) => $match($x, $v));
                }
            });

            return;
        }
        // negated: none of the values may match; rows with no value count as "not matching", unless "Direct / None" is excluded
        $nullsPass = ! ($dim === 'source' && in_array(self::DIRECT, $values, true));
        $q->where(function ($w) use ($col, $values, $match, $nullsPass) {
            if ($nullsPass) {
                $w->whereNull($col)->orWhere(function ($x) use ($values, $match) {
                    foreach ($values as $v) {
                        $x->whereNot(fn ($y) => $match($y, $v));
                    }
                });
            } else {
                $w->whereNotNull($col)->where(function ($x) use ($values, $match) {
                    foreach ($values as $v) {
                        $x->whereNot(fn ($y) => $match($y, $v));
                    }
                });
            }
        });
    }
}
