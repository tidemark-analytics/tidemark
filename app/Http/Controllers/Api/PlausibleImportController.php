<?php

namespace App\Http\Controllers\Api;

use App\Analytics\Stats\Stats;
use App\Analytics\Support\Sources;
use App\Analytics\Support\Sql;
use App\Contracts\Entitlements;
use App\Contracts\LimitsImportedHistory;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Imports the CSV export of a Plausible site (Settings → Imports & exports → Export). The browser reads the zip and sends the rows in
 * batches; they are kept as daily totals (see Imported) and only for days before Tidemark started collecting for the site, so nothing
 * is counted twice. Importing again starts from scratch, which makes a repeated or interrupted import harmless.
 */
class PlausibleImportController extends Controller
{
    private const MAX_ROWS = 5000;

    /** Dimensions the generic `rows` may carry (plus custom events as `event`). */
    private const DIMS = ['country', 'region', 'browser', 'os', 'device', 'page', 'entry_page', 'exit_page', 'event'];

    /** Forget earlier imports and tell the browser which days it may send. */
    public function start(Request $request)
    {
        /** @var Site $site */
        $site = $request->attributes->get('site');
        abort_if($site->isRollup(), 422, 'Data can only be imported into a site that collects its own.');
        $this->clear($site);
        [$from, $until] = $this->allowedDays($site);

        return ['from' => $from, 'until' => $until];
    }

    public function store(Request $request)
    {
        /** @var Site $site */
        $site = $request->attributes->get('site');
        $data = $request->validate([
            'daily' => 'sometimes|array|max:'.self::MAX_ROWS,
            'rows' => 'sometimes|array|max:'.self::MAX_ROWS,
            'sources' => 'sometimes|array|max:'.self::MAX_ROWS,
        ]);
        [$from, $until] = $this->allowedDays($site);
        $db = Sql::connection();
        $skipped = 0;
        $day = function (mixed $d) use ($from, $until, &$skipped): ?string {
            if (! is_string($d) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || ! checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4))) {
                return null;
            }
            if (($from && $d < $from) || ($until && $d > $until) || $d < '2000-01-01') {
                $skipped++;

                return null;
            }

            return $d;
        };
        $n = fn (mixed $v): int => max(0, min(PHP_INT_MAX >> 8, (int) (is_numeric($v) ? $v : 0)));
        $str = fn (mixed $v, int $max): string => is_scalar($v) ? mb_substr((string) $v, 0, $max) : '';

        $daily = [];
        foreach ((array) ($data['daily'] ?? []) as $r) {
            if (is_array($r) && ($d = $day($r['date'] ?? null))) {
                $daily[] = ['site_id' => $site->id, 'date' => $d, 'visitors' => $n($r['visitors'] ?? 0), 'visits' => $n($r['visits'] ?? 0), 'pageviews' => $n($r['pageviews'] ?? 0), 'bounces' => $n($r['bounces'] ?? 0), 'duration' => $n($r['visit_duration'] ?? 0)];
            }
        }

        $rows = [];
        $add = function (string $d, string $dim, string $name, array $m) use (&$rows, $site) {
            $key = $d.'|'.$dim.'|'.$name;
            $row = &$rows[$key];
            $row ??= ['site_id' => $site->id, 'date' => $d, 'dim' => $dim, 'name' => $name, 'visitors' => 0, 'visits' => 0, 'pageviews' => 0, 'bounces' => 0, 'duration' => 0];
            foreach (['visitors', 'visits', 'pageviews', 'bounces', 'duration'] as $k) {
                $row[$k] += $m[$k] ?? 0;
            }
            unset($row);
        };
        foreach ((array) ($data['rows'] ?? []) as $r) {
            if (is_array($r) && in_array($r['dim'] ?? null, self::DIMS, true) && ($d = $day($r['date'] ?? null))) {
                $add($d, $r['dim'], $str($r['name'] ?? '', 500), ['visitors' => $n($r['visitors'] ?? 0), 'visits' => $n($r['visits'] ?? 0), 'pageviews' => $n($r['pageviews'] ?? 0), 'bounces' => $n($r['bounces'] ?? 0), 'duration' => $n($r['visit_duration'] ?? 0)]);
            }
        }
        foreach ((array) ($data['sources'] ?? []) as $r) {
            if (! is_array($r) || ! ($d = $day($r['date'] ?? null))) {
                continue;
            }
            $m = ['visitors' => $n($r['visitors'] ?? 0), 'visits' => $n($r['visits'] ?? 0), 'pageviews' => $n($r['pageviews'] ?? 0), 'bounces' => $n($r['bounces'] ?? 0), 'duration' => $n($r['visit_duration'] ?? 0)];
            $utm = ['source' => $str($r['utm_source'] ?? '', 255), 'medium' => $str($r['utm_medium'] ?? '', 255), 'campaign' => $str($r['utm_campaign'] ?? '', 255), 'content' => $str($r['utm_content'] ?? '', 255), 'term' => $str($r['utm_term'] ?? '', 255)];
            $referrer = $str($r['referrer'] ?? '', 500);
            $class = Sources::classify($referrer !== '' ? $referrer : null, $utm, $site->domain);
            $add($d, 'source', $str($r['source'] ?? '', 150), $m);
            $add($d, 'channel', $class['channel'], $m);
            if ($referrer !== '') {
                $add($d, 'referrer', $referrer, $m);
            }
            foreach ($utm as $k => $v) {
                if ($v !== '') {
                    $add($d, 'utm_'.$k, $v, $m);
                }
            }
        }

        $db->transaction(function () use ($db, $daily, $rows) {
            foreach (array_chunk($daily, 500) as $chunk) {
                $db->table('imported_visitors')->insert($chunk);
            }
            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                $db->table('imported_breakdowns')->insert($chunk);
            }
        });

        return ['days' => count($daily), 'rows' => count($rows), 'skipped' => $skipped];
    }

    /** Record what was imported so the dashboard knows which days it covers. */
    public function finish(Request $request)
    {
        /** @var Site $site */
        $site = $request->attributes->get('site');
        $r = Sql::connection()->table('imported_visitors')->where('site_id', $site->id)->selectRaw('MIN(date) as first, MAX(date) as last, COUNT(DISTINCT date) as days')->first();
        if (! $r || ! $r->first) {
            $this->clear($site);

            return ['days' => 0, 'from' => null, 'to' => null];
        }
        $site->forceFill(['imported_from' => 'plausible', 'imported_start' => $r->first, 'imported_end' => $r->last])->save();

        return ['days' => (int) $r->days, 'from' => (string) $r->first, 'to' => (string) $r->last];
    }

    public function destroy(Request $request)
    {
        $this->clear($request->attributes->get('site'));

        return ['ok' => true];
    }

    /** Removes everything imported for the site. */
    public static function clear(Site $site): void
    {
        $db = Sql::connection();
        $db->table('imported_visitors')->where('site_id', $site->id)->delete();
        $db->table('imported_breakdowns')->where('site_id', $site->id)->delete();
        $site->forceFill(['imported_from' => null, 'imported_start' => null, 'imported_end' => null])->save();
    }

    /**
     * The first and last day (site-local, inclusive) that may be imported: not older than the plan keeps, and before the day
     * Tidemark itself first recorded a visit. Null means no limit on that side.
     *
     * @return array{0:?string,1:?string}
     */
    private function allowedDays(Site $site): array
    {
        $entitlements = app(Entitlements::class);
        $cutoff = ($entitlements instanceof LimitsImportedHistory && ($c = $entitlements->importCutoff($site))) ? CarbonImmutable::instance($c)->setTimezone($site->timezone)->toDateString() : null;
        $native = (new Stats($site))->firstVisitAt();
        $until = $native ? $native->setTimezone($site->timezone)->subDay()->toDateString() : null;

        return [$cutoff, $until];
    }
}
