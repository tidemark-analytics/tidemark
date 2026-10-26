<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Support\Favicon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** A site's icon for the dashboard's site lists. Missing icons answer 404 (cached) and the interface shows a letter tile instead. */
class FaviconController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var Site $site */
        $site = $request->attributes->get('site');
        $icon = $site->isRollup() ? null : Favicon::for($site->domain);
        if (! $icon) {
            return response('', 404, ['Cache-Control' => 'private, max-age=3600']);
        }

        return response($icon['body'], 200, [
            'Content-Type' => $icon['type'],
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
