<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

/** The OpenAPI description of the Stats, Events and MCP endpoints, with this installation's address filled in. */
class OpenApiController extends Controller
{
    public function show()
    {
        $doc = json_decode((string) file_get_contents(resource_path('openapi.json')), true);
        $doc['servers'] = [['url' => rtrim(url('/'), '/'), 'description' => 'This Tidemark installation']];
        $doc['info']['version'] = (string) config('tidemark.version');

        return response()->json($doc, 200, ['Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'public, max-age=300'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
