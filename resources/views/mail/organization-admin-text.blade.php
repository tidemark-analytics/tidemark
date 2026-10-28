@if ($invited)
{{ $inviter->name }} invited you to help manage {{ $organization->name }} on {{ config('app.name') }}. You will be able to see and change every site in it, add sites, and manage its teams. Billing and the organization itself stay with {{ $inviter->name }}.

Accept the invitation and set up your account:
{{ $url }}

The link works once and expires in {{ $hours }} hours. If you were not expecting this, ignore this email and nothing happens: no account is created until you open the link.
@else
{{ $inviter->name }} made you an admin of {{ $organization->name }} on {{ config('app.name') }}. You can now see and change every site in it, add sites, and manage its teams. Billing and the organization itself stay with {{ $inviter->name }}.

Open your sites: {{ $url }}
@endif
