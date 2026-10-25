<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Submitted Annex A and B Evaluation Copies</title>
@include('reports.partials.evaluation-copy-style')
</head><body>
<div class="toolbar"><strong>{{ $copies->count() }} selected evaluation {{ $copies->count() === 1 ? 'copy' : 'copies' }}</strong><span>{{ $reveal ? 'Identity shown' : 'Identity hidden' }}</span>@if($canReveal)<a href="{{ $anonymousUrl }}{{ $reveal ? '' : '&identity=1' }}">{{ $reveal ? 'Hide Identity' : 'Show Identity' }}</a>@endif<button onclick="window.print()">Print / Save PDF</button><span>Long bond · 8.5 × 13 inches · Portrait · 100% · Headers/footers off</span><small>Annex A: two separate responses per sheet, side by side. Annex B: one response in two columns, as in the original instrument. An odd Annex A selection leaves the other half blank.</small></div>
@foreach($sheets as $sheet)
<section class="print-sheet sheet-{{ $sheet['annex'] }}" data-print-sheet="{{ $sheet['annex'] }}">
@foreach($sheet['copies'] as $copy) @include('reports.partials.evaluation-copy', ['copy' => $copy]) @endforeach
@if($sheet['annex'] === 'A' && count($sheet['copies']) === 1)<div class="unused-half" aria-hidden="true"></div>@endif
</section>
@endforeach
@include('reports.live-preview')
</body></html>
