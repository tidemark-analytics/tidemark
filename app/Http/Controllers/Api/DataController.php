<?php

namespace App\Http\Controllers\Api;

use App\Analytics\Support\Sql;
use App\Contracts\Entitlements;
use App\Contracts\LimitsImportedHistory;
use App\Http\Controllers\Controller;
use App\Models\Goal;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Moves a site's raw data between installs (self-hosted to self-hosted, or self-hosted to Tidemark Cloud).
 * The file is newline-delimited JSON: one header line, then one line per visit with its events and their properties.
 */
class DataController extends Controller
{
    private const FORMAT = 'tidemark-export';

    private const MAX_VISITS_PER_REQUEST = 1000;

    private const MAX_EVENTS_PER_VISIT = 2000;

    /** Visit columns that travel in the file, with the longest string each may hold. */
    private const VISIT_STRINGS = [
        'hostname' => 100, 'entry_page' => 500, 'exit_page' => 500, 'referrer' => 500, 'source' => 150, 'channel' => 24,
        'utm_medium' => 255, 'utm_source' => 255, 'utm_campaign' => 255, 'utm_content' => 255, 'utm_term' => 255,
        'region' => 20, 'city' => 100, 'browser' => 40, 'os' => 40, 'device' => 12,
    ];

    public function export(Request $request): StreamedResponse
    {
        /** @var Site $site */
        $site = $request->attributes->get('site');
        $gzip = function_exists('deflate_init');
        $name = $site->domain.'_'.now()->format('Ymd').'.ndjson'.($gzip ? '.gz' : '');

        return response()->stream(function () use ($site, $gzip) {
            @set_time_limit(0);
            $ctx = $gzip ? deflate_init(ZLIB_ENCODING_GZIP) : null;
            $write = function (string $data, int $mode = ZLIB_NO_FLUSH) use ($ctx) {
                echo $ctx ? deflate_add($ctx, $data, $mode) : $data;
                flush();
            };
            $line = fn (array $row) => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

            $write($line([
                'format' => self::FORMAT, 'version' => 1, 'domain' => $site->domain, 'timezone' => $site->timezone, 'exported_at' => now()->toIso8601String(),
                'goals' => $site->goals()->get(['type', 'value'])->map->only(['type', 'value'])->all(),
            ]));

            $db = Sql::connection();
            $db->table('visits')->where('site_id', $site->id)->orderBy('id')->chunkById(500, function ($visits) use ($db, $site, $write, $line) {
                $ids = $visits->pluck('id')->all();
                $events = $db->table('events')->where('site_id', $site->id)->whereIn('visit_id', $ids)->orderBy('id')->get()->groupBy('visit_id');
                $props = $db->table('event_props')->where('site_id', $site->id)->whereIn('event_id', $events->flatten(1)->pluck('id')->all())->get()->groupBy('event_id');
                $out = '';
                foreach ($visits as $v) {
                    $row = (array) $v;
                    unset($row['site_id']);
                    $row['is_bounce'] = (bool) $row['is_bounce'];
                    $out .= $line(['visit' => $row, 'events' => ($events[$v->id] ?? collect())->map(fn ($e) => [
                        'name' => $e->name, 'pathname' => $e->pathname, 'hostname' => $e->hostname, 'created_at' => $e->created_at,
                        'props' => ($props[$e->id] ?? collect())->pluck('value', 'key')->all() ?: new \stdClass,
                    ])->values()->all()]);
                }
                $write($out);
            }, 'id');
            if ($ctx) {
                echo deflate_add($ctx, '', ZLIB_FINISH);
            }
        }, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function import(Request $request, Entitlements $entitlements)
    {
        /** @var Site $site */
        $site = $request->attributes->get('site');
        $data = $request->validate([
            'header' => 'sometimes|array',
            'visits' => 'required|array|max:'.self::MAX_VISITS_PER_REQUEST,
        ]);

        if (isset($data['header'])) {
            if (($data['header']['format'] ?? null) !== self::FORMAT || (int) ($data['header']['version'] ?? 0) !== 1) {
                return response()->json(['message' => 'This is not a Tidemark export file.'], 422);
            }
            foreach (array_slice((array) ($data['header']['goals'] ?? []), 0, 100) as $g) {
                if (in_array($g['type'] ?? null, ['event', 'page'], true) && is_string($g['value'] ?? null) && $g['value'] !== '') {
                    Goal::firstOrCreate(['site_id' => $site->id, 'type' => $g['type'], 'value' => Str::limit($g['value'], 500, '')]);
                }
            }
        }

        $cutoff = ($entitlements instanceof LimitsImportedHistory && ($c = $entitlements->importCutoff($site))) ? CarbonImmutable::instance($c) : null;
        $now = CarbonImmutable::now('UTC');
        $db = Sql::connection();
        $rows = [];
        $outdated = $invalid = 0;
        foreach ($data['visits'] as $item) {
            $visit = $this->visitRow($site, $item['visit'] ?? null, $now);
            if (! $visit) {
                $invalid++;

                continue;
            }
            if ($cutoff && CarbonImmutable::parse($visit['ended_at'], 'UTC')->lt($cutoff)) {
                $outdated++;

                continue;
            }
            $rows[$visit['id']] = [$visit, (array) ($item['events'] ?? [])];
        }

        // A visit id already in this site means the file was imported before; one that belongs to another site (the same
        // install copying between its own sites) gets a fresh id so the two sites never share rows.
        $taken = $rows ? $db->table('visits')->whereIn('id', array_keys($rows))->pluck('site_id', 'id')->all() : [];
        $known = [];
        foreach ($taken as $id => $owner) {
            if ($owner === $site->id) {
                $known[] = $id;

                continue;
            }
            [$visit, $events] = $rows[$id];
            unset($rows[$id]);
            $visit['id'] = (string) Str::uuid();
            $rows[$visit['id']] = [$visit, $events];
        }
        $skipped = 0;
        $imported = 0;
        $importedEvents = 0;
        $db->transaction(function () use ($rows, $known, $db, $site, &$skipped, &$imported, &$importedEvents) {
            $visits = $plain = [];
            foreach ($rows as $id => [$visit, $events]) {
                if (in_array($id, $known, true)) {
                    $skipped++;

                    continue;
                }
                $visits[] = $visit;
                $imported++;
                foreach (array_slice($events, 0, self::MAX_EVENTS_PER_VISIT) as $e) {
                    $row = $this->eventRow($site, $visit, $e);
                    if (! $row) {
                        continue;
                    }
                    $importedEvents++;
                    $props = $this->props($e['props'] ?? null);
                    if ($props) {
                        $eventId = $db->table('events')->insertGetId($row);
                        $db->table('event_props')->insert(array_map(fn ($k) => ['site_id' => $site->id, 'event_id' => $eventId, 'key' => $k, 'value' => $props[$k]], array_keys($props)));
                    } else {
                        $plain[] = $row;
                    }
                }
            }
            foreach (array_chunk($visits, 200) as $chunk) {
                $db->table('visits')->insert($chunk);
            }
            foreach (array_chunk($plain, 500) as $chunk) {
                $db->table('events')->insert($chunk);
            }
        });

        return ['imported' => $imported, 'events' => $importedEvents, 'skipped' => $skipped, 'outdated' => $outdated, 'invalid' => $invalid];
    }

    /** @return array<string,mixed>|null */
    private function visitRow(Site $site, mixed $v, CarbonImmutable $now): ?array
    {
        if (! is_array($v) || ! is_string($v['visitor_id'] ?? null) || strlen($v['visitor_id']) !== 32) {
            return null;
        }
        $start = $this->time($v['started_at'] ?? null, $now);
        $end = $this->time($v['ended_at'] ?? null, $now);
        if (! $start || ! $end || $end->lt($start)) {
            return null;
        }
        $row = [
            'id' => Str::isUuid($v['id'] ?? null) ? strtolower($v['id']) : (string) Str::uuid(),
            'site_id' => $site->id,
            'visitor_id' => $v['visitor_id'],
            'started_at' => $start->format('Y-m-d H:i:s'),
            'ended_at' => $end->format('Y-m-d H:i:s'),
        ];
        foreach (self::VISIT_STRINGS as $col => $max) {
            $row[$col] = isset($v[$col]) && is_scalar($v[$col]) && $v[$col] !== '' ? mb_substr((string) $v[$col], 0, $max) : null;
        }
        $row['channel'] ??= 'Direct';
        $row['country'] = is_string($v['country'] ?? null) && preg_match('/^[A-Za-z]{2}$/', $v['country']) ? strtoupper($v['country']) : null;
        $row['pageviews'] = max(0, min(4294967295, (int) ($v['pageviews'] ?? 0)));
        $row['events'] = max(0, min(4294967295, (int) ($v['events'] ?? 0)));
        $row['is_bounce'] = (bool) ($v['is_bounce'] ?? true);
        $row['duration'] = max(0, min(4294967295, (int) ($v['duration'] ?? 0)));

        return $row;
    }

    /** @return array<string,mixed>|null */
    private function eventRow(Site $site, array $visit, mixed $e): ?array
    {
        if (! is_array($e) || ! is_string($e['name'] ?? null) || $e['name'] === '') {
            return null;
        }
        $at = $this->time($e['created_at'] ?? null, CarbonImmutable::now('UTC'));
        if (! $at) {
            return null;
        }

        return [
            'site_id' => $site->id, 'visit_id' => $visit['id'], 'visitor_id' => $visit['visitor_id'],
            'name' => mb_substr($e['name'], 0, 120),
            'pathname' => is_string($e['pathname'] ?? null) ? mb_substr($e['pathname'], 0, 500) : null,
            'hostname' => is_string($e['hostname'] ?? null) ? mb_substr($e['hostname'], 0, 100) : null,
            'created_at' => $at->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string,string> */
    private function props(mixed $props): array
    {
        $out = [];
        foreach (is_array($props) ? array_slice($props, 0, 30, true) : [] as $k => $v) {
            if (is_scalar($v) && (string) $k !== '') {
                $out[mb_substr((string) $k, 0, 120)] = mb_substr((string) $v, 0, 500);
            }
        }

        return $out;
    }

    /** Parses a timestamp as UTC; null when it is missing, unreadable, or in the future. */
    private function time(mixed $value, CarbonImmutable $now): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            $t = CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }

        return $t->gt($now->addDay()) || $t->year < 2000 ? null : $t;
    }
}
