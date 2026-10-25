<?php

namespace App\Alerts;

use App\Http\Controllers\Api\AuthController;
use App\Jobs\DeliverWebhook;
use App\Mail\AlertMail;
use App\Models\AlertRule;
use App\Models\AlertWebhook;
use App\Models\Site;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** Hands a fired alert to its channels: e-mail to the site's owners and admins, and each webhook the rule names. */
class AlertDispatcher
{
    public static function payload(Site $site, array $event): array
    {
        return [
            'id' => (string) Str::uuid(),
            'event' => $event['event'],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'site' => ['id' => $site->id, 'domain' => $site->domain, 'url' => url('/'.$site->domain)],
            'summary' => $event['summary'],
            'data' => $event['data'],
        ];
    }

    public function send(AlertRule $rule, Site $site, array $event): void
    {
        $payload = self::payload($site, $event);
        if ($rule->email && AuthController::mailConfigured()) {
            $people = $site->members()->wherePivotIn('role', ['owner', 'admin'])->get();
            foreach ($people as $person) {
                try {
                    Mail::to($person)->send(new AlertMail($site, $payload));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
        foreach (AlertWebhook::where('site_id', $site->id)->whereIn('id', $rule->webhook_ids ?? [])->get() as $hook) {
            try {
                DeliverWebhook::dispatch($hook->id, $payload);
            } catch (\Throwable $e) {
                report($e); // a synchronous queue surfaces a failed delivery here; it is also recorded on the webhook
            }
        }
    }
}
