<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Individual Faculty Evaluation Report — {{ $offering->evaluatee->name }}</title>
<x-bisu-favicon />@include('reports.report-styles')</head><body>
@include('reports.report-toolbar')
<main class="sheet">
@if(session('status'))<p class="notice no-print">{{ session('status') }}</p>@endif
@if($errors->any())<div class="errors no-print">{{ $errors->first() }}</div>@endif
<div class="annex">Annex C</div><h1>INDIVIDUAL FACULTY EVALUATION REPORT</h1>
<form method="post" action="{{ route('evaluation.report.save', ['offering' => $offering, ...request()->only(['department', 'form', 'cycle'])]) }}">@csrf<input type="hidden" name="annex" value="C">
<h2>A. Faculty Information</h2>
<div class="meta"><div><b>Name of Faculty Evaluated:</b> {{ $offering->evaluatee->name }}</div><div><b>Department/College:</b> {{ $offering->evaluatee->instructorDepartment?->name ?? '—' }}</div><div><b>Campus:</b> {{ $offering->campus->name }}</div><div><b>Semester/Term & Academic Year:</b> {{ $offering->semester->name }} / {{ $offering->semester->schoolYear->name }}</div></div>
<label for="rank">Current Faculty Rank</label><input id="rank" name="rank" value="{{ old('rank', $details['rank'] ?? '') }}" @readonly(!$canEdit)><div class="print-text">{{ $details['rank'] ?? '________________________' }}</div>
@include('reports.department-heads')
<h2>B. Summary of Average SET Rating</h2>
@php($studentRows = $setRows->values())
<table><thead><tr><th rowspan="2">Seq</th><th>(1)</th><th>(2)</th><th>(3)</th><th>(4)</th><th>(3 × 4)</th></tr><tr><th>Course Code</th><th>Year/Section</th><th>No. of Students</th><th>Average SET Rating</th><th>Weighted SET Score</th></tr></thead><tbody>
@forelse($studentRows as $index => $row)<tr><td>{{ $index + 1 }}</td><td>{{ $row['assignment']->course?->code ?? '—' }}</td><td>{{ $row['form']->settings['program_level'] ?? '' }} {{ $row['section'] }}</td><td class="numeric">{{ $row['count'] }}</td><td class="numeric">{{ $row['percentage'] === null ? '—' : number_format($row['percentage'], 2) }}</td><td class="numeric">{{ $row['percentage'] === null ? '—' : number_format($row['count'] * $row['percentage'], 2) }}</td></tr>
@empty<tr><td colspan="6">No student evaluation results available.</td></tr>@endforelse
<tr><th colspan="3">TOTAL</th><th class="numeric">{{ $studentRows->sum('count') }}</th><th>TOTAL</th><th class="numeric">{{ $studentRows->sum('count') ? number_format($studentRows->sum(fn ($row) => $row['count'] * $row['percentage']), 2) : '—' }}</th></tr></tbody></table>
<p class="muted">No. of Students counts complete student responses for each assignment. Weighted SET Score = No. of Students × Average SET Rating. SET Rating = total weighted score ÷ total complete responses. Scores use the maximum of the assigned instrument; the original 15-item Annex A uses 75.</p>
<div class="keep-together"><h2>C. SET and SEF Ratings</h2>
<table><thead><tr><th></th><th>SET Rating</th><th>SEF Rating</th></tr></thead><tbody><tr><th>OVERALL RATING</th>@foreach(['SET','SEF'] as $instrument)<td class="numeric">{{ $ratings[$instrument] === null ? ($instrument === 'SEF' ? 'Pending supervisor evaluation' : 'No results available') : number_format($ratings[$instrument], 2) }}</td>@endforeach</tr></tbody></table>
<p class="muted">Ratings are percentages, reported separately for SET and SEF. Calculations retain full precision; displayed figures are rounded to two decimal places.</p></div>
<p class="muted">Based on {{ $rows->where('instrument', 'SET')->sum('count') }} completed student response(s) and {{ $rows->where('instrument', 'SEF')->sum('count') }} completed supervisor response(s). Pending responses are not counted as zero.</p>
<h2>D. Summary of Qualitative Comments and Suggestions</h2>
@foreach(['SET' => 'Students', 'SEF' => 'Supervisor'] as $type => $label)
@php($comments = $rows->where('instrument', $type)->flatMap(fn ($row) => $row['comments'])->values())
<table><thead><tr><th style="width:50px">Seq</th><th>Comments and Suggestions from the {{ $label }}</th></tr></thead><tbody>@forelse($comments as $index => $comment)<tr><td>{{ $index + 1 }}</td><td style="white-space:pre-wrap">{{ $comment }}</td></tr>@empty<tr><td>—</td><td>No comments provided.</td></tr>@endforelse</tbody></table>
@endforeach
<div class="signatures" style="grid-template-columns:1fr">@foreach(['prepared' => 'Prepared by', 'reviewed' => 'Reviewed by'] as $key => $label)<div><b>{{ $label }}:</b><div class="signature"></div><span class="muted">{{ $key === 'prepared' ? 'Signature of Staff' : 'Signature of Authorized Official' }}</span>@include('reports.signatory-select', ['label' => ($key === 'prepared' ? 'Name of Staff' : 'Name of Authorized Official')])<label for="{{ $key }}_date">Date</label><input type="date" id="{{ $key }}_date" name="{{ $key }}_date" value="{{ old($key.'_date', $details[$key.'_date'] ?? '') }}" @readonly(!$canEdit)><div class="print-text">{{ $details[$key.'_date'] ?? '________________________' }}</div></div>@endforeach</div>
@if($canEdit)<p class="no-print"><button type="submit">Save Report Details</button> <span class="muted">Save before printing. Signatures are completed on paper.</span></p>@endif
</form>
<p class="muted">Generated {{ now()->format('F j, Y g:i A') }}. Current assigned forms only. Individual evaluator identities are omitted.</p>
</main>@include('reports.live-preview')
</body></html>
