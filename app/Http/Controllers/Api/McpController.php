<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mcp\McpServer;
use Illuminate\Http\Request;

/** The MCP endpoint: JSON-RPC over POST, authenticated with an API key (read-only keys are enough). */
class McpController extends Controller
{
    public function handle(Request $request, McpServer $server)
    {
        // a key is required, never a browser session: nothing here can be reached by a page the visitor happens to have open
        if (! $request->attributes->get('via_api_key')) {
            return response()->json(McpServer::error(null, -32001, 'Send an API key as a Bearer token. Create one in Account settings → Security.'), 401);
        }
        $message = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($message)) {
            return response()->json(McpServer::error(null, -32700, 'Parse error'), 400);
        }
        $user = $request->user();

        if (array_is_list($message) && $message) { // a batch
            $replies = array_values(array_filter(array_map(fn ($m) => $server->handle($m, $user), $message)));

            return $replies ? response()->json($replies) : response('', 202);
        }
        $reply = $server->handle($message, $user);

        return $reply === null ? response('', 202) : response()->json($reply);
    }

    /** There is no server-initiated stream. */
    public function stream()
    {
        return response()->json(['error' => 'This MCP server answers POST requests only.'], 405, ['Allow' => 'POST']);
    }
}
