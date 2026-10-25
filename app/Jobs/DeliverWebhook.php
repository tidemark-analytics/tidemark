<?php

namespace App\Jobs;

use App\Alerts\WebhookUrl;
use App\Models\AlertWebhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/** Posts one alert to a webhook. Failures are retried with growing pauses, and the latest outcome is kept on the webhook. */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @param array<string,mixed> $payload */
    public function __construct(public string $webhookId, public array $payload) {}

    /** @return array<int,int> seconds to wait before each retry */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        if (! ($hook = AlertWebhook::find($this->webhookId))) {
            return;
        }
        $status = self::deliver($hook, $this->payload);
        if ($status < 200 || $status >= 300) {
            // 4xx other than a timeout or rate limit won't get better by waiting
            if ($status >= 400 && $status < 500 && ! in_array($status, [408, 425, 429], true)) {
                return;
            }
            throw new \RuntimeException("Webhook {$hook->id} answered {$status}");
        }
    }

    /** The HTTP status the webhook answered with (0 when it could not be reached). The outcome is saved on the webhook. */
    public static function deliver(AlertWebhook $hook, array $payload): int
    {
        $note = null;
        $status = 0;
        $check = WebhookUrl::inspect($hook->url);
        if ($check['problem']) {
            $note = $check['problem'];
        } else {
            try {
                $body = self::body($hook, $payload);
                $headers = ['User-Agent' => 'Tidemark-Webhooks', 'Tidemark-Event' => $payload['event'], 'Tidemark-Delivery' => $payload['id']];
                if ($hook->kind === 'generic' && $hook->secret) {
                    $t = time();
                    $headers['Tidemark-Signature'] = "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $hook->secret);
                }
                // connect to the address that was just checked, so the name can't resolve somewhere else a moment later
                $res = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()->withHeaders($headers)->withBody($body, 'application/json')
                    ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$check['host']}:{$check['port']}:{$check['ip']}"]]])->post($hook->url);
                $status = $res->status();
                $note = $res->successful() ? null : 'It answered with HTTP '.$status.'.';
            } catch (\Throwable $e) {
                $note = 'It could not be reached.';
            }
        }
        $hook->forceFill(['last_status' => $status, 'last_error' => $note, 'last_at' => now()])->save();

        return $status;
    }

    private static function body(AlertWebhook $hook, array $p): string
    {
        $text = '*'.$p['summary']."*\n".$p['site']['url'];

        return (string) json_encode(match ($hook->kind) {
            'slack' => ['text' => $text],
            'discord' => ['content' => mb_substr(str_replace('*', '**', $text), 0, 1900)],
            default => $p,
        }, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
