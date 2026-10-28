@if ($invited)
{{ $inviter->name }} invited you to help manage their {{ config('app.name') }} account. You will be able to see and change every site on it, add sites, and manage its teams. Billing and the account itself stay with {{ $inviter->name }}.

Accept the invitation and set up your account:
{{ $url }}

The link works once and expires in {{ $hours }} hours. If you were not expecting this, ignore this email and nothing happens: no account is created until you open the link.
@else
{{ $inviter->name }} made you an admin of their {{ config('app.name') }} account. You can now see and change every site on it, add sites, and manage its teams. Billing and the account itself stay with {{ $inviter->name }}.

Open your sites: {{ $url }}
@endif
