<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CampaignLink;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Campaign links and tracking pixels of a site, managed by its owners and admins. */
class CampaignLinkController extends Controller
{
    public function index(Request $request)
    {
        $site = $this->site($request);
        $links = CampaignLink::where('site_id', $site->id)->orderByDesc('id')->get();
        $totals = DB::table('campaign_link_counts')->whereIn('link_id', $links->pluck('id'))->groupBy('link_id')
            ->selectRaw('link_id, sum(hits) as total')->pluck('total', 'link_id');
        $recent = DB::table('campaign_link_counts')->whereIn('link_id', $links->pluck('id'))->where('day', '>=', now()->subDays(29)->toDateString())
            ->groupBy('link_id')->selectRaw('link_id, sum(hits) as total')->pluck('total', 'link_id');

        return ['allowed' => $site->campaignLinksAllowed(), 'data' => $links->map(fn ($l) => $this->present($l, (int) ($totals[$l->id] ?? 0), (int) ($recent[$l->id] ?? 0)))->all()];
    }

    public function store(Request $request)
    {
        $site = $this->site($request);
        abort_unless($site->campaignLinksAllowed(), 403, 'Campaign links are not part of this plan.');
        $data = $this->validated($request, $site);
        $link = CampaignLink::create($data + ['site_id' => $site->id, 'slug' => CampaignLink::newSlug()]);

        return response()->json(['data' => $this->present($link, 0, 0)], 201);
    }

    public function update(Request $request, string $site, CampaignLink $link)
    {
        $s = $this->site($request);
        abort_unless($link->site_id === $s->id, 404);
        abort_unless($s->campaignLinksAllowed(), 403, 'Campaign links are not part of this plan.');
        $link->update($this->validated($request, $s, $link->kind));

        return ['data' => $this->present($link->fresh(), 0, 0)];
    }

    public function destroy(Request $request, string $site, CampaignLink $link)
    {
        $s = $this->site($request);
        abort_unless($link->site_id === $s->id, 404);
        DB::table('campaign_link_counts')->where('link_id', $link->id)->delete();
        $link->delete();

        return ['ok' => true];
    }

    private function site(Request $request): Site
    {
        /** @var Site $site */
        $site = $request->attributes->get('site');
        abort_if($site->isRollup(), 422, 'A rollup has no campaign links of its own.');

        return $site;
    }

    private function validated(Request $request, Site $site, ?string $kind = null): array
    {
        $rules = ['name' => 'required|string|max:120'] + array_fill_keys(CampaignLink::UTM, 'nullable|string|max:255');
        if ($kind === null) {
            $rules['kind'] = 'required|in:link,pixel';
        }
        $data = $request->validate($rules);
        $kind ??= $data['kind'];
        $data['kind'] = $kind;
        if ($kind === 'link') {
            $destination = trim((string) $request->validate(['destination' => 'required|string|max:1000'])['destination']);
            $host = strtolower((string) parse_url($destination, PHP_URL_HOST));
            $scheme = strtolower((string) parse_url($destination, PHP_URL_SCHEME));
            $own = $host === $site->domain || str_ends_with($host, '.'.$site->domain);
            if (! in_array($scheme, ['http', 'https'], true) || ! $own) {
                throw ValidationException::withMessages(['destination' => "The address must be a page on {$site->domain}, starting with https://."]);
            }
            $data['destination'] = $destination;
        } else {
            $data['destination'] = null;
        }
        foreach (CampaignLink::UTM as $key) {
            $data[$key] = isset($data[$key]) && trim($data[$key]) !== '' ? trim($data[$key]) : null;
        }
        if ($kind === 'pixel' && ! $data['utm_campaign']) {
            throw ValidationException::withMessages(['utm_campaign' => 'A tracking pixel needs a campaign name.']);
        }

        return $data;
    }

    private function present(CampaignLink $l, int $total, int $recent): array
    {
        return ['id' => $l->id, 'slug' => $l->slug, 'kind' => $l->kind, 'name' => $l->name, 'destination' => $l->destination,
            'utm_source' => $l->utm_source, 'utm_medium' => $l->utm_medium, 'utm_campaign' => $l->utm_campaign, 'utm_content' => $l->utm_content, 'utm_term' => $l->utm_term,
            'url' => $l->kind === 'link' ? url('/l/'.$l->slug) : url('/p/'.$l->slug.'.gif'), 'total' => $total, 'last_30_days' => $recent];
    }
}
