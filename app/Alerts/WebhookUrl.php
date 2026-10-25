<?php

namespace App\Alerts;

/**
 * Where an alert may be sent. Webhook addresses come from site managers, so unless the operator allows it they must be https
 * addresses that resolve to the public internet: an alert can't be used to reach the server's own network.
 */
class WebhookUrl
{
    /** Why this address can't be used, or null when it can. */
    public static function problem(string $url): ?string
    {
        return self::inspect($url)['problem'];
    }

    /**
     * @return array{problem:?string, host:string, port:int, ip:?string}
     */
    public static function inspect(string $url): array
    {
        $p = parse_url($url);
        $private = (bool) config('tidemark.webhooks.allow_private');
        $scheme = strtolower($p['scheme'] ?? '');
        $host = strtolower(trim($p['host'] ?? '', '[]'));
        $port = (int) ($p['port'] ?? ($scheme === 'http' ? 80 : 443));
        $fail = fn (string $why) => ['problem' => $why, 'host' => $host, 'port' => $port, 'ip' => null];

        if (! in_array($scheme, $private ? ['http', 'https'] : ['https'], true) || $host === '') {
            return $fail($private ? 'Use an http or https address.' : 'Use a secure https address.');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return $fail('The address can\'t contain a username or password.');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            return $fail('That address could not be found.');
        }
        foreach ($ips as $ip) {
            if (! $private && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $fail('That address points to a private network. Webhooks can only reach public addresses.');
            }
        }

        return ['problem' => null, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }
}
