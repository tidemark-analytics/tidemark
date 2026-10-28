<?php

namespace App\Analytics\Ingest;

use App\Analytics\Support\Sql;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Stitches hits into visits (30 minutes of inactivity ends a visit) and stores the event + its props. A hit that is not interactive
 * (an engagement measurement sent when the visitor leaves a page) is attached to the visit that is still open and changes nothing about
 * it, so it cannot clear a bounce or lengthen a visit; with no open visit it is dropped rather than starting a visit of its own. It is a
 * measurement of one page view, so sent again (the visitor left the tab and came back) it replaces the earlier one instead of adding a row.
 */
class DatabaseSink implements EventSink
{
    public const VISIT_TIMEOUT = 30; // minutes

    public function record(array $hit): void
    {
        $db = Sql::connection();
        $now = $hit['at'];
        $isPageview = $hit['name'] === 'pageview';
        $interactive = $hit['interactive'] ?? true;
        $cacheKey = 'tdm:v:'.$hit['site_id'].':'.$hit['visitor_id'];

        $visitId = Cache::get($cacheKey);
        $visit = $visitId ? $db->table('visits')->where('id', $visitId)->where('site_id', $hit['site_id'])
            ->where('ended_at', '>=', date('Y-m-d H:i:s', strtotime($now) - self::VISIT_TIMEOUT * 60))->first() : null;
        if (! $visit) {
            $visit = $db->table('visits')->where('site_id', $hit['site_id'])->where('visitor_id', $hit['visitor_id'])
                ->where('ended_at', '>=', date('Y-m-d H:i:s', strtotime($now) - self::VISIT_TIMEOUT * 60))->orderByDesc('ended_at')->first();
        }

        if (! $interactive) {
            if (! $visit) {
                return;
            }
            $visitId = $visit->id;
            if ($this->replaceEarlierMeasurement($db, $visitId, $hit)) {
                return;
            }
        } elseif ($visit) {
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
        if ($interactive) {
            Cache::put($cacheKey, $visitId, now()->addMinutes(self::VISIT_TIMEOUT));
        }

        $eventId = $db->table('events')->insertGetId([
            'site_id' => $hit['site_id'], 'visit_id' => $visitId, 'visitor_id' => $hit['visitor_id'],
            'name' => $hit['name'], 'pathname' => $hit['pathname'], 'hostname' => $hit['hostname'], 'created_at' => $now,
        ] + (empty($hit['revenue']) ? [] : ['revenue' => $hit['revenue']['amount'], 'currency' => $hit['revenue']['currency'], 'revenue_reporting' => $hit['revenue']['reporting']])); // only events with revenue need the columns, so an update still collects until it has migrated
        $this->storeProps($db, $hit, $eventId);
    }

    /** @param array<string,mixed> $hit */
    private function storeProps(ConnectionInterface $db, array $hit, int $eventId): void
    {
        if ($hit['props']) {
            $rows = [];
            foreach ($hit['props'] as $k => $v) {
                $rows[] = ['site_id' => $hit['site_id'], 'event_id' => $eventId, 'key' => $k, 'value' => $v];
            }
            $db->table('event_props')->insert($rows);
        }
    }

    /**
     * True when a non-interactive hit took the place of the visit's earlier one for the same page view: same name and page, and no
     * pageview of that page since (which would make this the measurement of a new view).
     *
     * @param  array<string,mixed>  $hit
     */
    private function replaceEarlierMeasurement(ConnectionInterface $db, string $visitId, array $hit): bool
    {
        $earlier = $db->table('events')->where('visit_id', $visitId)->where('name', $hit['name'])->where('pathname', $hit['pathname'])->orderByDesc('id')->first();
        if (! $earlier) {
            return false;
        }
        $view = $db->table('events')->where('visit_id', $visitId)->where('name', 'pageview')->where('pathname', $hit['pathname'])->orderByDesc('id')->value('id');
        if ($view !== null && $view > $earlier->id) {
            return false;
        }
        $db->table('events')->where('id', $earlier->id)->update(['created_at' => $hit['at']]);
        $db->table('event_props')->where('event_id', $earlier->id)->delete();
        $this->storeProps($db, $hit, $earlier->id);

        return true;
    }
}
