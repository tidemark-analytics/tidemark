{{ ucfirst($r['window']->frequency) }} report for {{ $r['site']->domain }}
{{ $r['window']->label() }}

@foreach ($r['metrics'] as $m)
{{ str_pad($m['label'], 15) }}{{ $m['value'] }}@if ($m['change'] !== null) ({{ $m['change'] > 0 ? '+' : '' }}{{ $m['change'] }}%)@endif

@endforeach
(Changes compare with the period before.)
@foreach ([['Top pages', 'pages'], ['Top sources', 'sources'], ['Top countries', 'countries']] as [$title, $key])
@if (count($r[$key]))

{{ $title }}
@foreach ($r[$key] as $row)
- {{ \Illuminate\Support\Str::limit($row['name'], 60) }}: {{ $row['value'] }}
@endforeach
@endif
@endforeach
@if (count($r['goals']))

Goals
@foreach ($r['goals'] as $g)
- {{ $g['name'] }}: {{ number_format($g['visitors']) }} ({{ $g['conversion_rate'] }}%)@isset($g['revenue']), {{ number_format($g['revenue'], 2) }} {{ $g['currency'] }}@endisset

@endforeach
@endif
@if (count($r['notes']))

Notes on the graph
@foreach ($r['notes'] as $n)
- {{ $n['date'] }}: {{ $n['note'] }}
@endforeach
@endif

Open the dashboard: {{ $dashboard }}

You get this because you asked for {{ $r['window']->frequency }} reports of {{ $r['site']->domain }}. There are no tracking pixels or tracked links in this email.
Change your reports: {{ $settings }}
Unsubscribe: {{ $unsubscribe }}
