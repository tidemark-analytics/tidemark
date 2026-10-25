<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** The published API description matches the routes that exist, so it can't drift from the code. */
class OpenApiTest extends TestCase
{
    use RefreshDatabase;

    private function doc(): array
    {
        return json_decode((string) file_get_contents(resource_path('openapi.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_it_is_served_publicly_with_this_installations_address(): void
    {
        $r = $this->getJson('/api/v1/openapi.json')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertSame('3.1.0', $r->json('openapi'));
        $this->assertSame(rtrim(url('/'), '/'), $r->json('servers.0.url'));
        $this->assertSame(config('tidemark.version'), $r->json('info.version'));
    }

    public function test_every_public_route_is_described_and_every_description_has_a_route(): void
    {
        $paths = $this->doc()['paths'];
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $uri = '/'.$route->uri();
            if (! preg_match('#^/api/(v1/stats/|v1/openapi\.json$|event$|mcp$)#', $uri)) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $routes[strtolower($method).' '.preg_replace('/\{(\w+)\??\}/', '{$1}', $uri)] = true;
            }
        }
        unset($routes['get /api/mcp']); // answers 405, there is nothing to describe
        $described = [];
        foreach ($paths as $path => $ops) {
            foreach ($ops as $method => $op) {
                $described[$method.' '.$path] = true;
            }
        }
        $this->assertSame([], array_keys(array_diff_key($routes, $described)), 'routes missing from resources/openapi.json');
        $this->assertSame([], array_keys(array_diff_key($described, $routes)), 'described endpoints that do not exist');
    }

    public function test_references_resolve_and_operation_ids_are_unique(): void
    {
        $doc = $this->doc();
        $json = json_encode($doc);
        preg_match_all('/"\$ref":"#\/([^"]+)"/', str_replace('\/', '/', $json), $m);
        foreach (array_unique($m[1]) as $pointer) {
            $node = $doc;
            foreach (explode('/', $pointer) as $part) {
                $this->assertArrayHasKey($part, $node, "#/{$pointer}");
                $node = $node[$part];
            }
        }
        $ids = [];
        foreach ($doc['paths'] as $ops) {
            foreach ($ops as $op) {
                $ids[] = $op['operationId'];
            }
        }
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function test_the_documented_metrics_and_properties_are_the_ones_the_api_accepts(): void
    {
        $doc = $this->doc();
        $props = $doc['components']['parameters']['property']['schema']['enum'];
        $this->assertContains('event:page', $props);
        $this->assertContains('visit:country', $props);
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'password']);
        foreach ($props as $p) { // each one is accepted by the breakdown endpoint
            $this->assertNotSame(422, $this->actingAs($user)->getJson('/api/v1/stats/breakdown?site_id=none.test&property='.urlencode($p))->getStatusCode(), $p);
        }
    }
}
