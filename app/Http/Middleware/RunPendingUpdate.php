<?php

namespace App\Http\Middleware;

use App\Updates\Updater;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** After an update (in-app or by FTP) the first request runs the migrations with the freshly installed code. */
class RunPendingUpdate
{
    public function handle(Request $request, Closure $next): Response
    {
        $updater = app(Updater::class);
        if ($updater->hasPendingWork()) {
            $updater->finishIfPending();
        }

        return $next($request);
    }
}
