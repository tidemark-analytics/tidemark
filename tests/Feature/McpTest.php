<?php

namespace Tests\Feature;

use App\Mcp\McpServer;
use App\Models\ApiKey;
use App\Models\Funnel;
use App\Models\Goal;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The MCP server and the read-only API keys that open it: what an assistant can read, and what it can't. */
class McpTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $user;

    private string $read;

    private string $full;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create(['domain' => 'example.com', 'timezone' => 'UTC']);
        Site::create(['domain' => 'other.org', 'timezone' => 'UTC']);
        $this->user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'password']);
        $this->site->members()->attach($this->user->id, ['role' => 'viewer', 'id' => (string) Str::uuid()]);
        [, $this->read] = ApiKey::issue($this->user, 'assistant', 'read');
        [, $this->full] = ApiKey::issue($this->user, 'script', 'full');
        $this->visits(now()->subDays(2)->toDateString(), 5);
    }

    private function visits(string $day, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $vid = (string) Str::uuid();
            $visitor = md5($vid);
            DB::table('visits')->insert(['id' => $vid, 'site_id' => $this->site->id, 'visitor_id' => $visitor, 'started_at' => "$day 10:00:00", 'ended_at' => "$day 10:05:00", 'pageviews' => 2, 'is_bounce' => false, 'duration' => 120, 'entry_page' => '/', 'exit_page' => '/pricing', 'country' => 'DE', 'source' => 'Google']);
            DB::table('events')->insert(['site_id' => $this->site->id, 'visit_id' => $vid, 'visitor_id' => $visitor, 'name' => 'pageview', 'pathname' => '/pricing', 'created_at' => "$day 10:00:00"]);
            DB::table('events')->insert(['site_id' => $this->site->id, 'visit_id' => $vid, 'visitor_id' => $visitor, 'name' => 'Signup', 'pathname' => '/pricing', 'created_at' => "$day 10:01:00"]);
        }
    }

    private function rpc(array $message, ?string $token = null)
    {
        return $this->withToken($token ?? $this->read)->postJson('/api/mcp', $message + ['jsonrpc' => '2.0']);
    }

    private function tool(string $name, array $arguments = [], ?string $token = null)
    {
        return $this->rpc(['id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]], $token);
    }

    public function test_the_handshake_names_the_server_and_agrees_a_protocol_version(): void
    {
        $r = $this->rpc(['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']]])->assertOk();
        $this->assertSame('2025-03-26', $r->json('result.protocolVersion'));
        $this->assertSame('tidemark', $r->json('result.serverInfo.name'));
        $this->assertArrayHasKey('tools', $r->json('result.capabilities'));

        $this->assertSame('2025-06-18', $this->rpc(['id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']])->json('result.protocolVersion'));
        $this->rpc(['method' => 'notifications/initialized'])->assertStatus(202);
        $this->rpc(['id' => 3, 'method' => 'ping'])->assertOk()->assertJson(['id' => 3]);
    }

    public function test_every_tool_is_read_only_and_described(): void
    {
        $tools = $this->rpc(['id' => 1, 'method' => 'tools/list'])->assertOk()->json('result.tools');
        $this->assertEqualsCanonicalizing(['list_sites', 'get_stats', 'get_timeseries', 'get_breakdown', 'get_goals', 'list_funnels', 'get_funnel', 'get_current_visitors', 'get_annotations'], array_column($tools, 'name'));
        foreach ($tools as $t) {
            $this->assertTrue($t['annotations']['readOnlyHint'], $t['name']);
            $this->assertFalse($t['annotations']['destructiveHint'], $t['name']);
            $this->assertSame('object', $t['inputSchema']['type']);
            $this->assertNotSame('', $t['description']);
            $this->assertFalse($t['inputSchema']['additionalProperties']);
        }
    }

    public function test_assistants_read_the_numbers_of_their_own_sites(): void
    {
        $sites = $this->tool('list_sites')->assertOk()->json('result');
        $this->assertFalse($sites['isError']);
        $this->assertSame([['domain' => 'example.com', 'timezone' => 'UTC', 'role' => 'viewer']], $sites['structuredContent']['sites'], 'only the sites the key owner belongs to');

        $stats = $this->tool('get_stats', ['site_id' => 'example.com', 'period' => '7d', 'metrics' => ['visitors', 'pageviews'], 'compare' => 'previous_period'])->json('result');
        $this->assertSame(5, $stats['structuredContent']['results']['visitors']['value']);
        $this->assertSame(5, $stats['structuredContent']['results']['pageviews']['value']);
        $this->assertStringContainsString('"visitors"', $stats['content'][0]['text']);

        $series = $this->tool('get_timeseries', ['site_id' => 'example.com', 'period' => '7d'])->json('result.structuredContent.results');
        $this->assertCount(7, $series);
        $this->assertSame(5, array_sum(array_column($series, 'visitors')));

        $rows = $this->tool('get_breakdown', ['site_id' => 'example.com', 'property' => 'event:page', 'period' => '7d', 'metrics' => ['visitors'], 'limit' => 5])->json('result.structuredContent.results');
        $this->assertSame([['page' => '/pricing', 'visitors' => 5]], $rows);

        $this->assertSame(0, $this->tool('get_current_visitors', ['site_id' => 'example.com'])->json('result.structuredContent.visitors'));
        $this->assertSame([], $this->tool('get_annotations', ['site_id' => 'example.com'])->json('result.structuredContent.results'));
    }

    public function test_goals_and_funnels(): void
    {
        $signup = Goal::create(['site_id' => $this->site->id, 'type' => 'event', 'value' => 'Signup']);
        $pricing = Goal::create(['site_id' => $this->site->id, 'type' => 'page', 'value' => '/pricing']);
        $goals = $this->tool('get_goals', ['site_id' => 'example.com', 'period' => '7d'])->json('result.structuredContent.results');
        $this->assertSame(['Signup' => 5, 'Visit /pricing' => 5], collect($goals)->pluck('visitors', 'name')->all());

        $list = $this->tool('list_funnels', ['site_id' => 'example.com'])->json('result.structuredContent');
        $this->assertTrue($list['allowed']);
        $this->assertSame([], $list['results']);

        $funnel = Funnel::create(['site_id' => $this->site->id, 'name' => 'Pricing to signup']);
        foreach ([$pricing, $signup] as $i => $g) {
            $funnel->steps()->create(['goal_id' => $g->id, 'position' => $i + 1]);
        }
        $this->assertSame([['id' => $funnel->id, 'name' => 'Pricing to signup', 'steps' => 2]], $this->tool('list_funnels', ['site_id' => 'example.com'])->json('result.structuredContent.results'));
        $r = $this->tool('get_funnel', ['site_id' => 'example.com', 'funnel_id' => $funnel->id, 'period' => '7d'])->json('result');
        $this->assertFalse($r['isError']);
        $this->assertSame(['Visit /pricing' => 5, 'Signup' => 5], collect($r['structuredContent']['steps'])->pluck('visitors', 'name')->all());
    }

    public function test_problems_come_back_as_tool_errors_and_other_sites_stay_hidden(): void
    {
        $r = $this->tool('get_stats', ['site_id' => 'other.org'])->assertOk()->json('result');
        $this->assertTrue($r['isError'], 'a site the key owner is not a member of');
        $this->assertSame('Site not found', $r['content'][0]['text']);

        $this->assertTrue($this->tool('get_stats', [])->json('result.isError'));
        $this->assertTrue($this->tool('get_stats', ['site_id' => 'example.com', 'period' => 'forever'])->json('result.isError'));
        $this->assertTrue($this->tool('get_breakdown', ['site_id' => 'example.com', 'property' => 'visit:ip'])->json('result.isError'));

        $this->assertSame(-32602, $this->tool('delete_everything')->json('error.code'));
        $this->assertSame(-32601, $this->rpc(['id' => 9, 'method' => 'resources/list'])->json('error.code'));
        $this->assertSame(-32600, $this->withToken($this->read)->postJson('/api/mcp', ['id' => 1, 'method' => 'ping'])->json('error.code'), 'missing jsonrpc version');
        $this->call('POST', '/api/mcp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->read], '{nope')->assertStatus(400)->assertJson(['error' => ['code' => -32700]]);
    }

    public function test_batches_get_one_reply_per_request(): void
    {
        $r = $this->withToken($this->read)->postJson('/api/mcp', [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ])->assertOk()->json();
        $this->assertSame([1, 2], array_column($r, 'id'));
    }

    public function test_a_key_is_required_and_a_browser_session_is_not_enough(): void
    {
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
        $this->withToken('tdm_nonsense')->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
        $this->flushHeaders();
        $this->actingAs($this->user)->withHeader('X-Requested-With', 'XMLHttpRequest')->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertUnauthorized()->assertJson(['error' => ['code' => -32001]]);
        $this->withToken($this->full)->getJson('/api/mcp')->assertStatus(405)->assertHeader('Allow', 'POST');
    }

    public function test_full_keys_work_too_and_read_only_keys_reach_nothing_else(): void
    {
        $this->assertSame('tidemark', $this->rpc(['id' => 1, 'method' => 'initialize', 'params' => []], $this->full)->json('result.serverInfo.name'));

        $get = fn (string $uri, string $token) => $this->withToken($token)->getJson($uri);
        $get('/api/v1/stats/aggregate?site_id=example.com&period=7d', $this->read)->assertOk()->assertJsonPath('results.visitors.value', 5);
        $get('/api/v1/stats/goals?site_id=example.com', $this->read)->assertOk();
        // everything that is not statistics is refused for a read-only key, and still works for a full one
        foreach (['/api/me', '/api/me/keys', '/api/sites/example.com', '/api/sites/example.com/stats/top', '/api/admin/users'] as $uri) {
            $get($uri, $this->read)->assertForbidden();
        }
        $get('/api/me', $this->full)->assertOk();
        $this->withToken($this->read)->postJson('/api/me/keys', ['name' => 'sneaky', 'scope' => 'full'])->assertForbidden();
        $this->withToken($this->read)->deleteJson('/api/sites/example.com')->assertForbidden();
        $this->assertSame(2, ApiKey::count());
    }

    public function test_keys_are_created_with_a_scope(): void
    {
        $api = fn (array $body) => $this->actingAs($this->user)->withHeader('X-Requested-With', 'XMLHttpRequest')->postJson('/api/me/keys', $body);
        $this->assertSame('read', $api(['name' => 'ro', 'scope' => 'read'])->assertCreated()->json('data.scope'));
        $this->assertSame('full', $api(['name' => 'default'])->assertCreated()->json('data.scope'));
        $api(['name' => 'bad', 'scope' => 'admin'])->assertStatus(422);
    }

    public function test_requests_are_rate_limited(): void
    {
        $ok = 0;
        foreach (range(1, 130) as $i) {
            if ($this->rpc(['id' => $i, 'method' => 'ping'])->getStatusCode() === 429) {
                break;
            }
            $ok++;
        }
        $this->assertGreaterThan(115, $ok);
        $this->assertLessThan(125, $ok, 'about 120 requests a minute per key owner');
    }

    public function test_the_server_knows_the_versions_it_speaks(): void
    {
        $this->assertSame('2025-06-18', McpServer::VERSIONS[0]);
    }
}
