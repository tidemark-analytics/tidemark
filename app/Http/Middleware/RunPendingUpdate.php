<?php

namespace App\Http\Middleware;

use App\Updates\Updater;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** After a self-update the first request runs the migrations with the freshly installed code. */
class RunPendingUpdate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (is_file(storage_path('framework/update-pending'))) {
            app(Updater::class)->finishIfPending();
        }

        return $next($request);
    }
}
