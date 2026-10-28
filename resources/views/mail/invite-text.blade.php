@if ($invited)
{{ $inviter->name }} invited you to {{ $site->domain }} on {{ config('app.name') }}. You will be able to {{ $access }}.

Accept the invitation and set up your account:
{{ $url }}

The link works once and expires in {{ $hours }} hours. If you were not expecting this, ignore this email and nothing happens: no account is created until you open the link.
@else
{{ $inviter->name }} gave you access to {{ $site->domain }} on {{ config('app.name') }}. You can {{ $access }}.

Open the dashboard: {{ $url }}
@endif
