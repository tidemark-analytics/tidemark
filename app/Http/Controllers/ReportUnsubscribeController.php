<?php

namespace App\Http\Controllers;

use App\Models\ReportSubscription;

/** The unsubscribe link in each report e-mail. The POST also answers the one-click unsubscribe of mail apps. */
class ReportUnsubscribeController extends Controller
{
    public function show(string $token)
    {
        $sub = ReportSubscription::with('site')->where('token', $token)->first();

        return view('reports.unsubscribe', ['done' => false, 'domain' => $sub?->site?->domain, 'frequency' => $sub?->frequency]);
    }

    public function destroy(string $token)
    {
        $sub = ReportSubscription::with('site')->where('token', $token)->first();
        $sub?->delete();

        return view('reports.unsubscribe', ['done' => $sub !== null, 'domain' => $sub?->site?->domain, 'frequency' => $sub?->frequency]);
    }
}
