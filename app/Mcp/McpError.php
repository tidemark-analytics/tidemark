<?php

namespace App\Mcp;

/** A JSON-RPC error to send back for a message. */
class McpError
{
    public function __construct(public int $code, public string $message) {}
}
