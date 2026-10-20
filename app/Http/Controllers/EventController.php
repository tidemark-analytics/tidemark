<?php

namespace App\Http\Controllers;

use App\Analytics\Ingest\Collector;
use Illuminate\Http\Request;

class EventController extends Controller
{
    /** POST /api/event — Plausible-compatible collection endpoint. */
    public function store(Request $request, Collector $collector)
    {
        $r = $collector->handle($request);

        return response($r['body'], $r['status'])->header('Content-Type', 'text/plain');
    }
}
