<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Faculty Evaluation Summary — {{ $offering->evaluatee->name }}</title>
<x-bisu-favicon />
@include('reports.report-styles')</head><body>
@include('reports.report-toolbar')
<main class="sheet">
@if(session('status'))<p class="notice no-print">{{ session('status') }}</p>@endif
@if($errors->any())<div class="errors no-print">{{ $errors->first() }}</div>@endif
<div class="annex">Annex D</div><h1>FACULTY EVALUATION AND DEVELOPMENT<br>ACKNOWLEDGEMENT FORM</h1>
<form method="post" action="{{ route('evaluation.report.save', ['offering' => $offering, ...request()->only(['department', 'form', 'cycle'])]) }}">@csrf<input type="hidden" name="annex" value="D">
<h2>A. FACULTY MEMBER INFORMATION</h2>
<div class="meta"><div><b>Name of Faculty Evaluated:</b> {{ $offering->evaluatee->name }}</div><div><b>Department/College:</b> {{ $offering->evaluatee->instructorDepartment?->name ?? '—' }}</div><div><b>Campus:</b> {{ $offering->campus->name }}</div><div><b>Semester/Term & Academic Year:</b> {{ $offering->semester->name }} / {{ $offering->semester->schoolYear->name }}</div></div>
<label for="rank">Current Faculty Rank</label><input id="rank" name="rank" value="{{ old('rank', $details['rank'] ?? '') }}" @readonly(!$canEdit)><div class="print-text">{{ $details['rank'] ?? '________________________' }}</div>
@include('reports.department-heads')
<h2>B. FACULTY EVALUATION SUMMARY</h2>
<table><thead><tr><th>Overall Rating</th><th>Student Evaluation of Teachers (SET)</th><th>Supervisor’s Evaluation of Faculty (SEF)</th></tr></thead><tbody><tr><td>Computed rating (%)</td>@foreach(['SET','SEF'] as $instrument)<td class="numeric">{{ $ratings[$instrument] === null ? ($instrument === 'SEF' ? 'Pending supervisor evaluation' : 'No results available') : number_format($ratings[$instrument], 2).'%' }}</td>@endforeach</tr></tbody></table>
<p class="muted">Each instrument is reported separately. Rating = (total score ÷ maximum possible score) × 100. For the original 15-item instruments, the maximum is 75. Summary percentages are weighted by the number of complete responses. No combined SET/SEF weighting is assumed.</p>
<p class="muted">Based on {{ $rows->where('instrument', 'SET')->sum('count') }} completed student response(s) and {{ $rows->where('instrument', 'SEF')->sum('count') }} completed supervisor response(s). Pending responses are not counted as zero.</p>
<h2>C. Development Plan</h2><p class="muted">To be jointly accomplished by the Supervisor and Faculty.</p>
@foreach(['areas' => 'Areas for Improvement', 'activities' => 'Proposed Learning and Development Activities', 'action_plan' => 'Action Plan'] as $key => $label)
<div class="section"><label for="{{ $key }}">{{ $label }}</label><textarea id="{{ $key }}" name="{{ $key }}" @readonly(!$canEdit)>{{ old($key, $details[$key] ?? '') }}</textarea><div class="print-text">{{ $details[$key] ?? "________________________________________________________________________________\n\n________________________________________________________________________________" }}</div></div>
@endforeach
<p>I acknowledge that I have received and reviewed the faculty evaluation conducted for the period mentioned above. I understand that my signature below does not necessarily indicate agreement with the evaluation but confirms that I have been given the opportunity to discuss it with my supervisor.</p>
<div class="signatures">@foreach(['supervisor' => 'SUPERVISOR', 'faculty' => 'FACULTY'] as $key => $label)<div><b>{{ $label }}</b><div class="signature"></div><span class="muted">Signature</span>@if($key === 'supervisor')@include('reports.signatory-select', ['label' => 'Supervisor'])@else<label for="{{ $key }}_name">Name</label><input id="{{ $key }}_name" name="{{ $key }}_name" value="{{ old($key.'_name', $details[$key.'_name'] ?? ($key === 'faculty' ? $offering->evaluatee->name : '')) }}" @readonly(!$canEdit)><div class="print-text">{{ $details[$key.'_name'] ?? ($key === 'faculty' ? $offering->evaluatee->name : '________________________') }}</div>@endif<label for="{{ $key }}_date">Date Signed</label><input id="{{ $key }}_date" type="date" name="{{ $key }}_date" value="{{ old($key.'_date', $details[$key.'_date'] ?? '') }}" @readonly(!$canEdit)><div class="print-text">{{ $details[$key.'_date'] ?? '________________________' }}</div></div>@endforeach</div>
@if($canEdit)<p class="no-print"><button type="submit">Save Development Plan</button> <span class="muted">Save changes before printing. Signatures are completed on the printed form.</span></p>@endif
</form>
<section class="summary"><h1>FACULTY EVALUATION SUMMARY REPORT</h1><p><b>{{ $offering->evaluatee->name }}</b> · {{ $offering->semester->name }} / {{ $offering->semester->schoolYear->name }}</p>
<h2>Results by Evaluation Type</h2>
<table><thead><tr><th>Evaluation Type</th><th>Complete Responses</th><th>Rating (%)</th></tr></thead><tbody>
@foreach($rows->groupBy('instrument') as $type => $group)
@php($responseCount = $group->sum('count'))
<tr><td>{{ \App\Support\EvaluationType::LABELS[$type] ?? 'Other / Unspecified' }}</td><td class="numeric">{{ $responseCount }}</td><td class="numeric">{{ $responseCount ? number_format($group->sum(fn ($row) => $row['percentage'] * $row['count']) / $responseCount, 2).'%' : 'No results available' }}</td></tr>
@endforeach
</tbody></table>
@forelse($rows as $row)
<h2>{{ $row['form']->name }}</h2><p><b>{{ $row['assignment']->evaluation_type_label }}</b><br>{{ $row['assignment']->course?->code ?? 'Faculty assignment' }} · {{ $row['assignment']->section }} · {{ $row['count'] }} complete response(s)</p>
<table><thead><tr><th>Mean Total</th><th>Maximum</th><th>Computed Rating</th></tr></thead><tbody><tr><td>{{ $row['mean_total'] === null ? '—' : number_format($row['mean_total'], 2) }}</td><td>{{ $row['maximum'] }}</td><td>{{ $row['percentage'] === null ? 'No results available' : number_format($row['percentage'], 2).'%' }}</td></tr></tbody></table>
<table><thead><tr><th>Benchmark Statement</th><th>Mean Rating</th></tr></thead><tbody>@foreach($row['items']->groupBy('section') as $section => $items)<tr><th colspan="2">{{ $section ?: 'Criteria' }}</th></tr>@foreach($items as $item)<tr><td>{{ $item['label'] }}</td><td class="numeric">{{ $item['mean'] === null ? '—' : number_format($item['mean'], 2) }}</td></tr>@endforeach @endforeach</tbody></table>
@empty<p>No evaluation results available.</p>@endforelse
<p class="muted">Generated {{ now()->format('F j, Y g:i A') }}. Only current assigned forms and complete responses are included. Individual evaluator identities are omitted.</p></section>
</main>@include('reports.live-preview')
</body></html>
