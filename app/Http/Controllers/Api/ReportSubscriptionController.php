<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReportSubscription;
use App\Models\Site;
use App\Reports\ReportWindow;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The signed-in person's own e-mail reports, one choice per site they belong to. */
class ReportSubscriptionController extends Controller
{
    public function index(Request $r)
    {
        $user = $r->user();
        $subs = ReportSubscription::where('user_id', $user->id)->pluck('frequency', 'site_id');

        return [
            'mail' => AuthController::mailConfigured(),
            'data' => Site::memberOf($user)->orderBy('domain')->get()->map(fn (Site $s) => ['id' => $s->id, 'domain' => $s->domain, 'frequency' => $subs[$s->id] ?? null])->values(),
        ];
    }

    public function update(Request $r, string $site)
    {
        $data = $r->validate(['frequency' => ['required', Rule::in([...ReportSubscription::FREQUENCIES, 'off'])]]);
        $s = Site::memberOf($r->user())->whereKey($site)->firstOrFail();
        $match = ['user_id' => $r->user()->id, 'site_id' => $s->id];
        if ($data['frequency'] === 'off') {
            ReportSubscription::where($match)->delete();

            return ['frequency' => null];
        }
        $sub = ReportSubscription::where($match)->first();
        if (! $sub || $sub->frequency !== $data['frequency']) {
            // the first report is the next one that ends, not the one that has just ended
            $last = ReportWindow::latest($data['frequency'], CarbonImmutable::now($s->timezone))->key();
            $sub = ReportSubscription::updateOrCreate($match, ['frequency' => $data['frequency'], 'last_period_end' => $last]);
        }

        return ['frequency' => $sub->frequency];
    }
}
