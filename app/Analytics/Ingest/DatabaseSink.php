<?php

namespace App\Analytics\Ingest;

use App\Analytics\Support\Sql;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Stitches hits into visits (30 minutes of inactivity ends a visit) and stores the event + its props. */
class DatabaseSink implements EventSink
{
    public const VISIT_TIMEOUT = 30; // minutes

    public function record(array $hit): void
    {
        $db = Sql::connection();
        $now = $hit['at'];
        $isPageview = $hit['name'] === 'pageview';
        $cacheKey = 'tdm:v:'.$hit['site_id'].':'.$hit['visitor_id'];

        $visitId = Cache::get($cacheKey);
        $visit = $visitId ? $db->table('visits')->where('id', $visitId)->where('site_id', $hit['site_id'])
            ->where('ended_at', '>=', date('Y-m-d H:i:s', strtotime($now) - self::VISIT_TIMEOUT * 60))->first() : null;
        if (! $visit) {
            $visit = $db->table('visits')->where('site_id', $hit['site_id'])->where('visitor_id', $hit['visitor_id'])
                ->where('ended_at', '>=', date('Y-m-d H:i:s', strtotime($now) - self::VISIT_TIMEOUT * 60))->orderByDesc('ended_at')->first();
        }

        if ($visit) {
            $events = $visit->events + 1;
            $db->table('visits')->where('id', $visit->id)->update([
                'ended_at' => $now,
                'exit_page' => $isPageview ? $hit['pathname'] : $visit->exit_page,
                'pageviews' => $visit->pageviews + ($isPageview ? 1 : 0),
                'events' => $events,
                'is_bounce' => $events <= 1,
                'duration' => max(0, strtotime($now) - strtotime($visit->started_at)),
            ]);
            $visitId = $visit->id;
        } else {
            $visitId = (string) Str::uuid();
            $db->table('visits')->insert([
                'id' => $visitId, 'site_id' => $hit['site_id'], 'visitor_id' => $hit['visitor_id'],
                'started_at' => $now, 'ended_at' => $now,
                'entry_page' => $hit['pathname'], 'exit_page' => $hit['pathname'], 'hostname' => $hit['hostname'],
                'referrer' => $hit['referrer'], 'source' => $hit['source'], 'channel' => $hit['channel'],
                'utm_medium' => $hit['utm']['medium'] ?? null, 'utm_source' => $hit['utm']['source'] ?? null,
                'utm_campaign' => $hit['utm']['campaign'] ?? null, 'utm_content' => $hit['utm']['content'] ?? null, 'utm_term' => $hit['utm']['term'] ?? null,
                'country' => $hit['country'], 'region' => $hit['region'], 'city' => $hit['city'],
                'browser' => $hit['browser'], 'os' => $hit['os'], 'device' => $hit['device'],
                'pageviews' => $isPageview ? 1 : 0, 'events' => 1, 'is_bounce' => true, 'duration' => 0,
            ]);
        }
        Cache::put($cacheKey, $visitId, now()->addMinutes(self::VISIT_TIMEOUT));

        $eventId = $db->table('events')->insertGetId([
            'site_id' => $hit['site_id'], 'visit_id' => $visitId, 'visitor_id' => $hit['visitor_id'],
            'name' => $hit['name'], 'pathname' => $hit['pathname'], 'hostname' => $hit['hostname'], 'created_at' => $now,
        ]);
        if ($hit['props']) {
            $rows = [];
            foreach ($hit['props'] as $k => $v) {
                $rows[] = ['site_id' => $hit['site_id'], 'event_id' => $eventId, 'key' => $k, 'value' => $v];
            }
            $db->table('event_props')->insert($rows);
        }
    }
}
