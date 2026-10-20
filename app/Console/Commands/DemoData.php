<?php

namespace App\Console\Commands;

use App\Analytics\Support\Sources;
use App\Analytics\Support\Sql;
use App\Models\Goal;
use App\Models\Site;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DemoData extends Command
{
    protected $signature = 'tidemark:demo {--days=45} {--visitors=120 : average visits per day} {--email=demo@example.com} {--domain=demo.example.com}';

    protected $description = 'Create a demo user and site filled with realistic fake traffic';

    public function handle(): int
    {
        $user = User::firstOrCreate(['email' => $this->option('email')], ['name' => 'Demo User', 'password' => 'password', 'is_admin' => true]);
        $site = Site::firstOrCreate(['domain' => $this->option('domain')], ['timezone' => 'America/New_York', 'public' => false, 'track_outbound' => true, 'track_downloads' => true]);
        if (! $site->members()->where('users.id', $user->id)->exists()) {
            $site->members()->attach($user->id, ['role' => 'owner', 'id' => (string) Str::uuid()]);
        }
        foreach ([['event', 'Signup'], ['page', '/pricing'], ['event', 'Outbound Link: Click'], ['event', 'File Download']] as [$type, $value]) {
            Goal::firstOrCreate(['site_id' => $site->id, 'type' => $type, 'value' => $value]);
        }
        $db = Sql::connection();
        foreach (['event_props', 'events', 'visits'] as $t) {
            $db->table($t)->where('site_id', $site->id)->delete();
        }

        $pages = ['/' => 30, '/pricing' => 14, '/blog' => 10, '/blog/privacy-first-analytics' => 9, '/blog/shared-hosting-tips' => 7, '/docs' => 8, '/docs/install' => 7, '/download' => 12, '/about' => 3, '/signup' => 6, '/thanks' => 2];
        $sources = [[null, null, 28], ['google.com', null, 26], ['duckduckgo.com', null, 6], ['bing.com', null, 4], ['news.ycombinator.com', null, 8], ['github.com', null, 7], ['reddit.com', null, 5], ['twitter.com', null, 5], ['linkedin.com', null, 3], ['chatgpt.com', null, 4], ['newsletter.example.net', null, 2]];
        $campaigns = [null, null, null, null, null, null, ['newsletter', 'email', 'spring-launch'], ['google', 'cpc', 'brand-search'], ['twitter', 'social', 'launch-thread']];
        $browsers = ['Firefox' => 28, 'Chrome' => 42, 'Safari' => 14, 'Edge' => 10, 'Opera' => 3, 'Samsung Internet' => 3];
        $oses = ['Windows' => 34, 'Mac' => 22, 'GNU/Linux' => 18, 'Android' => 14, 'iOS' => 12];
        $countries = [['US', 'New York', 'NY', 22], ['US', 'Austin', 'TX', 8], ['DE', 'Berlin', 'BE', 11], ['GB', 'London', 'ENG', 9], ['FR', 'Paris', 'IDF', 6], ['CA', 'Toronto', 'ON', 5], ['IN', 'Bengaluru', 'KA', 8], ['BR', 'São Paulo', 'SP', 6], ['JP', 'Tokyo', '13', 5], ['AU', 'Sydney', 'NSW', 4], ['NL', 'Amsterdam', 'NH', 5], ['KR', 'Seoul', '11', 4], ['ES', 'Madrid', 'MD', 3], ['SE', 'Stockholm', 'AB', 2], ['PL', 'Warsaw', 'MZ', 2]];
        $pick = function (array $weighted) {
            $total = array_sum(array_map(fn ($w) => is_array($w) ? end($w) : $w, $weighted));
            $r = mt_rand(1, max(1, (int) $total));
            foreach ($weighted as $k => $w) {
                $r -= is_array($w) ? end($w) : $w;
                if ($r <= 0) {
                    return is_array($w) ? $w : $k;
                }
            }

            return array_key_first($weighted);
        };

        $days = (int) $this->option('days');
        $visits = $events = $props = [];
        $n = 0;
        for ($d = $days; $d >= 0; $d--) {
            $dow = (int) now()->subDays($d)->format('w');
            $count = (int) ($this->option('visitors') * (in_array($dow, [0, 6]) ? 0.62 : 1.12) * (0.8 + mt_rand(0, 40) / 100) * (1 + (($days - $d) / $days) * 0.6));
            for ($i = 0; $i < $count; $i++) {
                $start = now()->subDays($d)->startOfDay()->addSeconds((int) (86400 * (0.12 + 0.8 * (mt_rand(0, 1000) / 1000) ** 1.3)));
                if ($start->gt(now())) {
                    continue;
                }
                $vid = (string) Str::uuid();
                $visitor = substr(md5($vid), 0, 32);
                [$ref] = $pick($sources);
                $utm = $campaigns[array_rand($campaigns)];
                $src = Sources::classify($ref, ['source' => $utm[0] ?? null, 'medium' => $utm[1] ?? null, 'campaign' => $utm[2] ?? null], $site->domain);
                [$cc, $city, $region] = $pick($countries);
                $os = $pick($oses);
                $mobile = in_array($os, ['Android', 'iOS']);
                $device = $mobile ? 'Mobile' : (mt_rand(0, 3) ? 'Laptop' : 'Desktop');
                $entry = array_key_first(array_slice($pages, mt_rand(0, 6), 1));
                $views = mt_rand(1, 100) < 46 ? 1 : mt_rand(2, 6);
                $t = $start->toImmutable();
                $path = $entry;
                $row = [];
                for ($v = 0; $v < $views; $v++) {
                    if ($v > 0) {
                        $path = $pick($pages);
                        $t = $t->addSeconds(mt_rand(15, 150));
                    }
                    $row[] = ['visit_id' => $vid, 'name' => 'pageview', 'pathname' => $path, 'at' => $t, 'props' => null];
                }
                if (mt_rand(1, 100) <= 9) {
                    $t = $t->addSeconds(mt_rand(3, 40));
                    $row[] = ['visit_id' => $vid, 'name' => 'Signup', 'pathname' => $path, 'at' => $t, 'props' => ['plan' => ['Starter', 'Pro', 'Team'][mt_rand(0, 2)], 'method' => ['email', 'github'][mt_rand(0, 1)]]];
                }
                if ($ref === 'github.com' || mt_rand(1, 100) <= 12) {
                    $t = $t->addSeconds(mt_rand(3, 30));
                    $row[] = ['visit_id' => $vid, 'name' => 'Outbound Link: Click', 'pathname' => $path, 'at' => $t, 'props' => ['url' => ['https://github.com/example/repo', 'https://example.org/docs'][mt_rand(0, 1)]]];
                }
                if ($path === '/download' && mt_rand(1, 100) <= 55) {
                    $t = $t->addSeconds(mt_rand(3, 30));
                    $row[] = ['visit_id' => $vid, 'name' => 'File Download', 'pathname' => $path, 'at' => $t, 'props' => ['url' => '/files/tidemark-latest.zip']];
                }
                $nEvents = count($row);
                $visits[] = [
                    'id' => $vid, 'site_id' => $site->id, 'visitor_id' => $visitor, 'started_at' => $start->utc()->format('Y-m-d H:i:s'), 'ended_at' => $t->utc()->format('Y-m-d H:i:s'),
                    'entry_page' => $entry, 'exit_page' => $path, 'hostname' => $site->domain, 'referrer' => $src['referrer'], 'source' => $src['source'], 'channel' => $src['channel'],
                    'utm_medium' => $utm[1] ?? null, 'utm_source' => $utm[0] ?? null, 'utm_campaign' => $utm[2] ?? null, 'utm_content' => null, 'utm_term' => null,
                    'country' => $cc, 'region' => $region, 'city' => $city, 'browser' => $pick($browsers), 'os' => $os, 'device' => $device,
                    'pageviews' => $views, 'events' => $nEvents, 'is_bounce' => $nEvents <= 1, 'duration' => max(0, $t->getTimestamp() - $start->getTimestamp()),
                ];
                foreach ($row as $e) {
                    $events[] = ['site_id' => $site->id, 'visit_id' => $vid, 'visitor_id' => $visitor, 'name' => $e['name'], 'pathname' => $e['pathname'], 'hostname' => $site->domain, 'created_at' => $e['at']->utc()->format('Y-m-d H:i:s'), '_props' => $e['props']];
                }
                $n++;
            }
        }
        foreach (array_chunk($visits, 150) as $c) {
            $db->table('visits')->insert($c);
        }
        foreach (array_chunk($events, 150) as $chunk) {
            $db->table('events')->insert(array_map(fn ($e) => array_diff_key($e, ['_props' => 1]), $chunk));
            $ids = $db->table('events')->where('site_id', $site->id)->orderByDesc('id')->limit(count($chunk))->pluck('id')->reverse()->values();
            foreach ($chunk as $i => $e) {
                foreach ($e['_props'] ?? [] as $k => $v) {
                    $props[] = ['site_id' => $site->id, 'event_id' => $ids[$i], 'key' => $k, 'value' => (string) $v];
                }
            }
        }
        foreach (array_chunk($props, 300) as $c) {
            $db->table('event_props')->insert($c);
        }

        $this->info("Demo ready: {$n} visits, ".count($events).' events.');
        $this->line('Login: '.$this->option('email').' / password   (site: '.$site->domain.')');

        return self::SUCCESS;
    }
}
