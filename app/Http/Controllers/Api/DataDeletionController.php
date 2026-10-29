<?php

namespace App\Http\Controllers\Api;

use App\Analytics\Support\Filters;
use App\Analytics\Support\Sql;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Delete collected visits that match a filter, for when something sensitive was recorded or a test run polluted the data.
 *
 * The unit is the visit: a visit that matches (by what it is, or by any event in it) is deleted whole, with every event and property
 * recorded during it. Taking single events out of a visit would leave its page count, bounce flag and duration wrong, so reports
 * stay consistent only if visits go whole. Imported history is daily totals and is not touched.
 */
class DataDeletionController extends Controller
{
    private const BATCH = 500;

    public function preview(Request $request)
    {
        [$site, $filters, $from, $to] = $this->scope($request);

        return $this->counts($site, $filters, $from, $to);
    }

    public function destroy(Request $request)
    {
        [$site, $filters, $from, $to] = $this->scope($request);
        $request->validate(['confirm_visits' => 'required|integer|min:0']);
        $before = $this->counts($site, $filters, $from, $to);
        abort_unless($before['visits'] === (int) $request->input('confirm_visits'), 422, 'The number of matching visits changed. Preview it again before deleting.');
        abort_if($before['visits'] === 0, 422, 'Nothing matches, so there is nothing to delete.');

        $db = Sql::connection();
        $visits = $events = 0;
        while (($ids = $this->matching($site, $filters, $from, $to)->limit(self::BATCH)->pluck('v.id')->all()) !== []) {
            $eventIds = $db->table('events')->where('site_id', $site->id)->whereIn('visit_id', $ids)->pluck('id')->all();
            foreach (array_chunk($eventIds, 500) as $chunk) {
                $db->table('event_props')->where('site_id', $site->id)->whereIn('event_id', $chunk)->delete();
            }
            $events += $db->table('events')->where('site_id', $site->id)->whereIn('visit_id', $ids)->delete();
            $visits += $db->table('visits')->where('site_id', $site->id)->whereIn('id', $ids)->delete();
        }

        $user = $request->user();
        DB::table('data_deletions')->insert([
            'site_id' => $site->id, 'user_id' => $user?->id, 'user_name' => $user?->name,
            'filters' => json_encode($filters->rules), 'date_from' => $from?->toDateString(), 'date_to' => $to?->toDateString(),
            'visits' => $visits, 'events' => $events, 'created_at' => now(),
        ]);

        return ['visits' => $visits, 'events' => $events];
    }

    /** The most recent deletions, newest first, with who ran them. */
    public function history(Request $request)
    {
        /** @var Site $site */
        $site = $request->attributes->get('site');

        return ['data' => DB::table('data_deletions')->where('site_id', $site->id)->orderByDesc('id')->limit(20)->get()->map(fn ($d) => [
            'id' => $d->id, 'by' => $d->user_name ?? 'A removed account', 'filters' => json_decode($d->filters, true) ?: [],
            'date_from' => $d->date_from, 'date_to' => $d->date_to, 'visits' => $d->visits, 'events' => $d->events, 'at' => CarbonImmutable::parse($d->created_at)->toIso8601String(),
        ])->all()];
    }

    /** @return array{0: Site, 1: Filters, 2: ?CarbonImmutable, 3: ?CarbonImmutable} */
    private function scope(Request $request): array
    {
        /** @var Site $site */
        $site = $request->attributes->get('site');
        abort_if($site->isRollup(), 422, 'A rollup has no data of its own. Delete from its sites instead.');
        $request->validate(['filters' => 'nullable|array|max:20', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $filters = new Filters(Filters::sanitize((array) $request->input('filters', [])), $site);
        $from = $request->filled('from') ? CarbonImmutable::parse($request->input('from'), $site->timezone)->startOfDay() : null;
        $to = $request->filled('to') ? CarbonImmutable::parse($request->input('to'), $site->timezone)->endOfDay() : null;
        abort_if($filters->rules === [] && ! $from && ! $to, 422, 'Add at least one condition or a date range. To delete everything, use Reset statistics.');

        return [$site, $filters, $from, $to];
    }

    /**
     * Visits that match every rule. Visit rules constrain the visit itself; each event rule (page, goal, property) keeps visits that have a
     * matching event, as on the dashboard. The event rules go in as `id IN (subquery)` because the correlated form the dashboard uses
     * crawls on SQLite once a site has thousands of visits.
     */
    private function matching(Site $site, Filters $filters, ?CarbonImmutable $from, ?CarbonImmutable $to): Builder
    {
        $db = Sql::connection();
        $q = $db->table('visits as v')->where('v.site_id', $site->id);
        if ($from) {
            $q->where('v.started_at', '>=', $from->utc()->toDateTimeString());
        }
        if ($to) {
            $q->where('v.started_at', '<=', $to->utc()->toDateTimeString());
        }
        foreach ($filters->rules as $rule) {
            $one = new Filters([$rule], $site);
            if (isset(Filters::VISIT_DIMS[$rule[1]])) {
                $one->applyToVisits($q);

                continue;
            }
            $events = $db->table('events as e')->where('e.site_id', $site->id)->select('e.visit_id');
            $q->whereIn('v.id', $one->applyToEvents($events));
        }

        return $q;
    }

    private function counts(Site $site, Filters $filters, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $visits = $this->matching($site, $filters, $from, $to)->count();
        $events = Sql::connection()->table('events')->where('site_id', $site->id)
            ->whereIn('visit_id', $this->matching($site, $filters, $from, $to)->select('v.id'))->count();

        return ['visits' => $visits, 'events' => $events];
    }
}
