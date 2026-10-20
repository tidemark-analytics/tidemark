<?php

namespace App\Http\Middleware;

use App\Support\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Installer::isInstalled() && ! $request->is('install*', 'up', 'js/*')) {
            return redirect('/install');
        }

        return $next($request);
    }
}
