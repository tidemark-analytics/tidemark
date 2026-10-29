<?php

namespace App\Http\Controllers;

use App\Models\CampaignLink;

/**
 * The public side of campaign links. `/l/abc` counts a click and forwards to the destination with its UTM values, `/p/abc.gif` counts a view
 * and answers with a transparent pixel. Only a daily total per link is kept: nothing about the person, no cookie, no visit. The visit that
 * follows a click is recorded by the destination page's own tracker, which reads the UTM values from the address, so the campaign shows up in
 * Sources and Campaigns without being counted twice. A link that already exists keeps forwarding when a plan no longer allows new ones.
 */
class CampaignRedirectController extends Controller
{
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function link(string $slug)
    {
        $link = CampaignLink::where('slug', $slug)->where('kind', 'link')->with('site')->first();
        abort_unless($link && $link->site, 404);
        $target = $link->target();
        if ($link->site->campaignLinksAllowed()) {
            $link->countHit();
        }

        return redirect()->away($target, 302)->withHeaders(['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    public function pixel(string $slug)
    {
        $link = CampaignLink::where('slug', $slug)->where('kind', 'pixel')->with('site')->first();
        abort_unless($link && $link->site, 404);
        if ($link->site->campaignLinksAllowed()) {
            $link->countHit();
        }

        return response(base64_decode(self::GIF), 200, ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store, no-cache, must-revalidate', 'X-Robots-Tag' => 'noindex']);
    }
}
