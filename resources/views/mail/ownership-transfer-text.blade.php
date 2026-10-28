{{ $previousOwner->name }} wants to make you the owner of {{ $organization->name }} on {{ config('app.name') }}. As owner you would hold its plan and billing, and decide who its admins are; {{ $previousOwner->name }} would stay on as an admin.

Nothing changes unless you accept. Review the offer in Settings > Organization:
{{ $url }}

The offer expires in {{ $hours }} hours.
