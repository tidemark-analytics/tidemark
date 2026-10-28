<?php

namespace App\Mcp;

use App\Http\Controllers\Api\StatsApiController;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A read-only Model Context Protocol server over Streamable HTTP (JSON-RPC 2.0, one JSON reply per POST). It exposes the Stats API as
 * tools an AI assistant can call, for the sites the API key's owner belongs to. Only aggregate numbers come back, and nothing can be changed.
 */
class McpServer
{
    public const VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private const PERIODS = ['day', '7d', '30d', 'month', '6mo', '12mo', 'year', 'custom'];

    private const METRICS = ['visitors', 'visits', 'pageviews', 'views_per_visit', 'bounce_rate', 'visit_duration', 'events', 'conversion_rate', 'total_revenue', 'average_revenue'];

    private const PROPERTIES = ['event:page', 'visit:entry_page', 'visit:exit_page', 'visit:source', 'visit:referrer', 'visit:channel', 'visit:utm_medium', 'visit:utm_source', 'visit:utm_campaign', 'visit:utm_content', 'visit:utm_term', 'visit:country', 'visit:region', 'visit:city', 'visit:browser', 'visit:os', 'visit:device'];

    public function __construct(private StatsApiController $stats) {}

    /**
     * Answers one JSON-RPC message.
     *
     * @return array<string,mixed>|null null for a notification, which has no reply
     */
    public function handle(mixed $message, User $user): ?array
    {
        if (! is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || ! isset($message['method']) || ! is_string($message['method'])) {
            return self::error($message['id'] ?? null, -32600, 'Invalid request');
        }
        $id = $message['id'] ?? null;
        $isNotification = ! array_key_exists('id', $message);
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        $result = match ($message['method']) {
            'initialize' => $this->initialize($params),
            'ping' => (object) [],
            'tools/list' => ['tools' => $this->tools()],
            'tools/call' => $this->call($params, $user),
            default => null,
        };
        if ($isNotification) {
            return null;
        }
        if ($result instanceof McpError) {
            return self::error($id, $result->code, $result->message);
        }
        if ($result === null) {
            return self::error($id, -32601, 'Method not found');
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    public static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private function initialize(array $params): array
    {
        $asked = (string) ($params['protocolVersion'] ?? '');

        return [
            'protocolVersion' => in_array($asked, self::VERSIONS, true) ? $asked : self::VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'tidemark', 'title' => 'Tidemark Analytics', 'version' => (string) config('tidemark.version')],
            'instructions' => 'Read-only access to privacy-friendly web analytics. Start with list_sites to find the site ids (their domains). All numbers are aggregates; there is no visitor-level data. Periods follow the site\'s own timezone.',
        ];
    }

    /** @return array<int, array<string,mixed>> */
    public function tools(): array
    {
        $site = ['type' => 'string', 'description' => 'The site\'s domain, for example example.com. list_sites shows the ones you can read.'];
        $period = ['type' => 'string', 'enum' => self::PERIODS, 'default' => '30d', 'description' => 'The window to report on. "custom" needs date as "YYYY-MM-DD,YYYY-MM-DD".'];
        $date = ['type' => 'string', 'description' => 'For custom periods "YYYY-MM-DD,YYYY-MM-DD"; otherwise a day the period is counted back from (default today).'];
        $filters = ['type' => 'string', 'description' => 'Optional filters, for example "visit:country==US;event:page==/blog/*;visit:source==Google|Bing". Operators: == (is), != (is not), =~ (contains); | separates alternatives, ; separates filters. Properties: '.implode(', ', self::PROPERTIES).', event:goal, event:props:<name>.'];
        $metrics = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::METRICS], 'description' => 'Which numbers to return. Default: visitors.'];
        $read = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $tool = fn (string $name, string $title, string $description, array $props, array $required = ['site_id']) => [
            'name' => $name, 'title' => $title, 'description' => $description,
            'inputSchema' => ['type' => 'object', 'properties' => $props, 'required' => $required, 'additionalProperties' => false],
            'annotations' => $read + ['title' => $title],
        ];

        return [
            $tool('list_sites', 'List sites', 'The sites this API key can read: their domain (use it as site_id elsewhere), timezone and your role.', [], []),
            $tool('get_stats', 'Get top stats', 'Totals for one period, such as visitors, pageviews, bounce rate and visit duration, optionally with the change against the previous period. With an event:goal filter the numbers are conversions.', [
                'site_id' => $site, 'period' => $period, 'date' => $date, 'metrics' => $metrics, 'filters' => $filters,
                'compare' => ['type' => 'string', 'enum' => ['previous_period'], 'description' => 'Add the percentage change against the period before.'],
            ]),
            $tool('get_timeseries', 'Get a time series', 'Numbers per day, hour or month across a period, for charts and trends.', [
                'site_id' => $site, 'period' => $period, 'date' => $date, 'metrics' => $metrics, 'filters' => $filters,
                'interval' => ['type' => 'string', 'enum' => ['minute', 'hour', 'day', 'week', 'month'], 'description' => 'Bucket size. Defaults to what suits the period.'],
            ]),
            $tool('get_breakdown', 'Break down by property', 'The top values of a property in a period, for example the most visited pages, top sources, countries or browsers.', [
                'site_id' => $site, 'property' => ['type' => 'string', 'enum' => self::PROPERTIES, 'description' => 'What to group by.'], 'period' => $period, 'date' => $date, 'metrics' => $metrics, 'filters' => $filters,
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 10, 'description' => 'How many rows.'], 'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            ], ['site_id', 'property']),
            $tool('get_goals', 'Get goal conversions', 'Each goal of the site with its unique visitors, total conversions, conversion rate and, for revenue goals, the revenue.', ['site_id' => $site, 'period' => $period, 'date' => $date, 'filters' => $filters]),
            $tool('list_funnels', 'List funnels', 'The site\'s funnels: id, name and number of steps. Funnels are part of some plans only; "allowed" says if this site has them.', ['site_id' => $site]),
            $tool('get_funnel', 'Get a funnel', 'How many visitors reached each step of a funnel in a period, with drop-off and conversion rates.', [
                'site_id' => $site, 'funnel_id' => ['type' => 'string', 'description' => 'A funnel id from list_funnels.'], 'period' => $period, 'date' => $date, 'filters' => $filters,
            ], ['site_id', 'funnel_id']),
            $tool('get_current_visitors', 'Get current visitors', 'How many visitors were on the site in the last five minutes.', ['site_id' => $site]),
            $tool('get_annotations', 'Get notes on the graph', 'The notes people added to dates on the graph ("launched v2", "newsletter went out") during a period, which explain changes in the numbers. Only the ones this key\'s owner may see.', ['site_id' => $site, 'period' => $period, 'date' => $date]),
        ];
    }

    private function call(array $params, User $user): array|McpError
    {
        $name = (string) ($params['name'] ?? '');
        if (! in_array($name, array_column($this->tools(), 'name'), true)) {
            return new McpError(-32602, 'Unknown tool: '.$name);
        }
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            $data = $name === 'list_sites' ? $this->sites($user) : $this->stat($name, $args, $user);
            $text = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return ['content' => [['type' => 'text', 'text' => $text]], 'structuredContent' => $data, 'isError' => false];
        } catch (HttpExceptionInterface $e) {
            return ['content' => [['type' => 'text', 'text' => $e->getMessage() ?: 'Request failed.']], 'isError' => true];
        }
    }

    /** @return array{sites: array<int, array{domain:string,timezone:string,role:string}>} */
    private function sites(User $user): array
    {
        return ['sites' => Site::memberOf($user)->orderBy('domain')->get()->map(fn ($s) => ['domain' => $s->domain, 'timezone' => $s->timezone, 'role' => $s->memberRole($user)])->all()];
    }

    /** Runs one of the Stats API endpoints as the key's owner and returns its answer as an object. */
    private function stat(string $tool, array $args, User $user): array
    {
        $query = array_filter([
            'site_id' => $args['site_id'] ?? null, 'period' => $args['period'] ?? null, 'date' => $args['date'] ?? null, 'filters' => $args['filters'] ?? null,
            'metrics' => isset($args['metrics']) && is_array($args['metrics']) ? implode(',', array_map('strval', $args['metrics'])) : null,
            'compare' => $args['compare'] ?? null, 'interval' => $args['interval'] ?? null, 'property' => $args['property'] ?? null,
            'limit' => $args['limit'] ?? null, 'page' => $args['page'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        $request = Request::create('/api/v1/stats/'.$tool, 'GET', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $query));
        $request->setUserResolver(fn () => $user);
        abort_if(! isset($query['site_id']), 422, 'site_id is required. Use list_sites to see the sites you can read.');

        $out = match ($tool) {
            'get_stats' => $this->stats->aggregate($request),
            'get_timeseries' => $this->stats->timeseries($request),
            'get_breakdown' => $this->stats->breakdown($request),
            'get_goals' => $this->stats->goals($request),
            'list_funnels' => $this->stats->funnels($request),
            'get_funnel' => $this->stats->funnel($request, (string) ($args['funnel_id'] ?? '')),
            'get_current_visitors' => ['visitors' => $this->stats->realtime($request)],
            'get_annotations' => $this->stats->annotations($request),
        };

        return (array) $out;
    }
}
