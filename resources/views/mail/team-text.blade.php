@if ($invited)
{{ $inviter->name }} invited you to the {{ $team->name }} team on {{ config('app.name') }}. Being on the team gives you access to the sites it is shared with.

Accept the invitation and set up your account:
{{ $url }}

The link works once and expires in {{ $hours }} hours. If you were not expecting this, ignore this email and nothing happens: no account is created until you open the link.
@else
{{ $inviter->name }} added you to the {{ $team->name }} team on {{ config('app.name') }}. Being on the team gives you access to the sites it is shared with.

Open your sites: {{ $url }}
@endif
