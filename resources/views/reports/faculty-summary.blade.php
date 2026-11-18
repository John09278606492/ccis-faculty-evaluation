<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Faculty Evaluation and Development Acknowledgement Form — {{ $offering->evaluatee->name }}</title>
<x-bisu-favicon />
@include('reports.report-styles')
{{-- Mirrors the official Annex D form: A4, half-inch margins, Arial, black borders, #D9E2F3 header shading. --}}
<style>
.annex-d{color:#000}.annex-d h1{font-size:16px;margin:4px 0 22px}.annex-d h2{font-size:14px;margin:22px 0 8px}
.annex-d table{margin:6px 0 14px}.annex-d td{border:1px solid #000;padding:6px 8px;vertical-align:top}
.annex-d .shaded{background:#d9e2f3}.annex-d .center{text-align:center;vertical-align:middle}
.annex-d .info td{border:0;padding:3px 0;vertical-align:middle}.annex-d .colon{text-align:center}
.annex-d .sign td{vertical-align:middle}.annex-d .signature-space{height:40px}
.annex-d label{display:inline;font-weight:normal;margin:0}.annex-d .plan label{display:block;margin-bottom:6px}
.annex-d .acknowledgement{font-weight:bold;margin:20px 0 8px}
@page{size:A4 portrait;margin:12.7mm}
@media print{
    .annex-d{font-size:11pt;line-height:1.15}.annex-d h1{font-size:11pt;margin:0 0 5mm}.annex-d h2{font-size:11pt;margin:4mm 0 2mm}
    .annex-d table{font-size:11pt;margin:1mm 0 3mm}.annex-d td{padding:1.2mm 1.8mm;border-color:#000;vertical-align:top}
    .annex-d .center,.annex-d .sign td,.annex-d .info td{vertical-align:middle}.annex-d .info td{padding:.6mm 0}
    .annex-d .shaded{background:#d9e2f3!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .annex-d label{font-size:11pt;margin:0}.annex-d .print-text{margin:0}
    .annex-d .plan .print-text{min-height:26mm;margin-top:1mm}.annex-d .signature-space{height:7.5mm}.annex-d .sign{break-inside:avoid;page-break-inside:avoid}
    .annex-d .acknowledgement{margin:4mm 0 2mm}
}
</style>
</head><body>
@include('reports.report-toolbar')
<main class="sheet annex-d">
@if(session('status'))<p class="notice no-print">{{ session('status') }}</p>@endif
@if($errors->any())<div class="errors no-print">{{ $errors->first() }}</div>@endif
<h1>FACULTY EVALUATION AND DEVELOPMENT ACKNOWLEDGEMENT FORM</h1>
<form method="post" action="{{ route('evaluation.report.save', ['offering' => $offering, ...request()->only(['department', 'form', 'cycle'])]) }}">@csrf<input type="hidden" name="annex" value="D">

<h2>A. FACULTY MEMBER INFORMATION</h2>
<table class="info"><colgroup><col style="width:33%"><col style="width:3%"><col></colgroup><tbody>
<tr><td>Name of Faculty Evaluated</td><td class="colon">:</td><td>{{ $offering->evaluatee->name }}</td></tr>
<tr><td>Department/College</td><td class="colon">:</td><td>{{ $offering->evaluatee->instructorDepartment?->name }}</td></tr>
<tr><td><label for="rank">Current Faculty Rank</label></td><td class="colon">:</td><td><input id="rank" name="rank" value="{{ old('rank', $details['rank'] ?? '') }}" @readonly(!$canEdit)><div class="print-text" data-empty="">{{ $details['rank'] ?? '' }}</div></td></tr>
<tr><td>Semester/Term &amp; Academic Year</td><td class="colon">:</td><td>{{ $offering->semester->name }} / {{ $offering->semester->schoolYear->name }}</td></tr>
</tbody></table>

<h2>B. FACULTY EVALUATION SUMMARY</h2>
<table class="overall"><colgroup><col style="width:50%"><col style="width:50%"></colgroup><tbody>
<tr><td colspan="2" class="center">Overall Rating</td></tr>
<tr><td class="center shaded">Student Evaluation of Teachers (SET)</td><td class="center shaded">Supervisor’s Evaluation of Faculty (SEF)</td></tr>
<tr>@foreach(['SET', 'SEF'] as $instrument)<td class="center">{{ $ratings[$instrument] === null ? ($instrument === 'SEF' ? 'Pending supervisor evaluation' : 'No results available') : number_format($ratings[$instrument], 2).'%' }}</td>@endforeach</tr>
</tbody></table>

<h2>C. Development Plan (to be jointly accomplished by the Supervisor and Faculty)</h2>
<table class="plan"><tbody>
@foreach(['areas' => 'Areas for Improvement', 'activities' => 'Proposed Learning and Development Activities', 'action_plan' => 'Action Plan'] as $key => $label)
<tr><td><label for="{{ $key }}">{{ $label }}</label><textarea id="{{ $key }}" name="{{ $key }}" @readonly(!$canEdit)>{{ old($key, $details[$key] ?? '') }}</textarea><div class="print-text" data-empty="">{{ $details[$key] ?? '' }}</div></td></tr>
@endforeach
</tbody></table>

<p class="acknowledgement">I acknowledge that I have received and reviewed the faculty evaluation conducted for the period mentioned above. I understand that my signature below does not necessarily indicate agreement with the evaluation but confirms that I have been given the opportunity to discuss it with my supervisor.</p>
<table class="sign"><colgroup><col style="width:14%"><col style="width:3%"><col></colgroup><tbody>
@foreach(['supervisor' => 'SUPERVISOR', 'faculty' => 'FACULTY'] as $key => $label)
<tr><td colspan="3" class="center shaded">{{ $label }}</td></tr>
<tr><td>Signature</td><td class="colon">:</td><td class="signature-space"></td></tr>
<tr><td>Name</td><td class="colon">:</td><td>@if($key === 'supervisor')@include('reports.signatory-select', ['label' => 'Supervisor name', 'bare' => true])@else<input id="faculty_name" name="faculty_name" aria-label="Faculty name" value="{{ old('faculty_name', $details['faculty_name'] ?? $offering->evaluatee->name) }}" @readonly(!$canEdit)><div class="print-text" data-empty="">{{ $details['faculty_name'] ?? $offering->evaluatee->name }}</div>@endif</td></tr>
<tr><td>Date Signed</td><td class="colon">:</td><td><input id="{{ $key }}_date" type="date" name="{{ $key }}_date" aria-label="{{ ucfirst($key) }} date signed" value="{{ old($key.'_date', $details[$key.'_date'] ?? '') }}" @readonly(!$canEdit)><div class="print-text" data-empty="">{{ $details[$key.'_date'] ?? '' }}</div></td></tr>
@endforeach
</tbody></table>
@if($canEdit)<p class="no-print"><button type="submit">Save Development Plan</button> <span class="muted">Save changes before printing. Signatures are completed on the printed form.</span></p>@endif
</form>
</main>@include('reports.live-preview')
</body></html>
