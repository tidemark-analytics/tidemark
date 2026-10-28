<?php

namespace App\Http\Middleware;

use App\Models\SharedLink;
use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves {site} (a domain) and checks the ability:
 *   view   — any member, admins, anyone when the dashboard is public, or a valid shared link (X-Share-Auth [+ X-Share-Password])
 *   manage — owners and admins of the site
 *   owner  — the site owner
 */
class SiteAccess
{
    public function handle(Request $request, Closure $next, string $ability = 'view'): Response
    {
        $domain = strtolower((string) $request->route('site'));
        $site = Site::where('domain', $domain)->first();
        if (! $site) {
            return response()->json(['error' => 'Site not found'], 404);
        }
        $user = Auth::user();
        $role = $site->roleOf($user);

        $allowed = match ($ability) {
            'owner' => $role === 'owner' || $site->isAccountAdmin($user), // an account admin does what the owner does on the account's sites
            'manage' => in_array($role, ['owner', 'admin'], true),
            default => $role !== null || $site->public,
        };

        if (! $allowed && $ability === 'view' && ($slug = $request->header('X-Share-Auth', $request->query('auth')))) {
            $link = SharedLink::where('site_id', $site->id)->where('slug', (string) $slug)->first();
            if ($link) {
                if (! $link->passwordMatches($request->header('X-Share-Password'))) {
                    return response()->json(['error' => 'password_required'], 401);
                }
                $allowed = true;
            }
        }
        if (! $allowed) {
            return response()->json(['error' => $user ? 'Forbidden' : 'Unauthenticated'], $user ? 403 : 401);
        }

        $request->attributes->set('site', $site);
        $request->attributes->set('role', $role);

        return $next($request);
    }
}
