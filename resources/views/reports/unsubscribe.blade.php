<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Email reports</title>
<style>
  :root{--bg:#f3f6f6;--card:#fff;--ink:#10242b;--muted:#4f6b73;--line:#dde6e8;--brand:#0f6f78}
  @media (prefers-color-scheme:dark){:root{--bg:#0b1519;--card:#122127;--ink:#e6f0f2;--muted:#9bb3ba;--line:#223840;--brand:#3fb6c1}}
  body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--ink);font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
  main{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:28px;max-width:420px;margin:16px}
  h1{font-size:20px;margin:0 0 8px}p{margin:0 0 16px;color:var(--muted)}
  button{font:inherit;font-weight:600;background:var(--brand);color:#fff;border:0;border-radius:8px;padding:10px 18px;cursor:pointer}
  a{color:var(--brand)}
</style></head>
<body><main>
@if ($done)
  <h1>You're unsubscribed</h1>
  <p>You won't get any more {{ $frequency }} reports{{ $domain ? ' for '.$domain : '' }}. You can turn them back on in your account settings.</p>
  <a href="{{ url('/settings/reports') }}">Account settings</a>
@elseif ($domain)
  <h1>Stop {{ $frequency }} reports?</h1>
  <p>You'll no longer get the {{ $frequency }} email report for {{ $domain }}.</p>
  <form method="post">@csrf<button>Unsubscribe</button></form>
@else
  <h1>Already unsubscribed</h1>
  <p>This link doesn't match an active report, so there is nothing more to stop.</p>
  <a href="{{ url('/settings/reports') }}">Account settings</a>
@endif
</main></body></html>
