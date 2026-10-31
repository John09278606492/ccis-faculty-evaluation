<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $kind === 'tally' ? 'Tally Report' : 'Scores Report' }} — {{ $offering->evaluatee->name }}</title>
<x-bisu-favicon />
    <style>
        :root { color-scheme:light; font-family:Arial,Helvetica,sans-serif; color:#111827; background:#eef2f6; }
        * { box-sizing:border-box; }
        body { margin:0; }
        .toolbar { position:sticky; top:0; z-index:2; display:flex; flex-wrap:wrap; gap:10px; align-items:center; padding:12px 18px; background:white; border-bottom:1px solid #cbd5e1; }
        .toolbar a,.toolbar button { font:inherit; font-size:14px; border:1px solid #cbd5e1; border-radius:6px; background:white; color:#111827; padding:9px 14px; text-decoration:none; cursor:pointer; }
        .toolbar button { background:#4338ca; border-color:#4338ca; color:white; font-weight:bold; }
        .toolbar span { font-size:13px; color:#475569; }
        main { margin:20px auto; padding:24px; max-width:1200px; background:white; }
        h1 { font-size:19px; text-align:center; margin:0 0 8px; }
        h2 { font-size:14px; margin:0 0 8px; }
        .report { margin-top:24px; }
        .report:first-of-type { margin-top:16px; }
        .meta { font-size:12px; line-height:1.6; margin-bottom:10px; display:grid; grid-template-columns:1fr 1fr; gap:0 18px; }
        .summary { border:1px solid #94a3b8; background:#f8fafc; padding:8px 10px; font-size:12px; line-height:1.6; margin-bottom:10px; }
        .muted { color:#475569; font-size:11px; line-height:1.5; }
        .note { margin:8px 0; }
        .table-wrap { width:100%; overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:11px; }
        th,td { border:1px solid #64748b; padding:6px 5px; vertical-align:middle; text-align:center; overflow-wrap:anywhere; }
        thead th { background:#e2e8f0; font-weight:bold; }
        .context th { background:white; text-align:left; font-size:10px; line-height:1.4; }
        .left { text-align:left; }
        .numeric { font-variant-numeric:tabular-nums; }
        .section th { background:#f1f5f9; text-align:left; }
        .totals th,.totals td { background:#f1f5f9; font-weight:bold; }
        .tally { table-layout:fixed; }
        .tally col.evaluator { width:28mm; }
        .tally col.total,.tally col.average { width:20mm; }
        .tally td,.tally th { padding:6px 3px; }
        .question-guide { margin-top:12px; font-size:10px; columns:2; column-gap:24px; }
        .question-guide div { margin:0 0 5px; break-inside:avoid; line-height:1.4; }
        .panel + .panel { margin-top:22px; }
        .panel-label { font-size:11px; margin:8px 0; }
        .empty { padding:18px; text-align:center; }
        @page { size:A4 landscape; margin:10mm; }
        @media print {
            :root,body { background:white; color:black; }
            .toolbar { display:none !important; }
            main { max-width:none; margin:0; padding:0; }
            h1 { font-size:14pt; margin-bottom:5mm; }
            h2 { font-size:10pt; }
            .meta,.summary { font-size:8.5pt; }
            .summary { padding:2mm; }
            .muted,.panel-label { font-size:8pt; }
            .report { margin:0; break-before:page; }
            .report:first-of-type { break-before:auto; margin-top:0; }
            .panel + .panel { break-before:page; margin-top:0; }
            .table-wrap { overflow:visible; }
            table { font-size:8.5pt; width:100%; }
            thead { display:table-header-group; }
            tbody { break-inside:auto; }
            tr { break-inside:avoid; }
            th,td { padding:1.6mm 1mm; border-color:#555; }
            .scores th,.scores td { padding:1.1mm 1mm; }
            .tally th,.tally td { padding:1.7mm .7mm; }
            .context th { font-size:8pt; }
            .question-guide { font-size:7.5pt; }
            h2,.summary { break-after:avoid; }
        }
    </style>
    @include('reports.print-page-margins')
</head>
<body>
<nav class="toolbar" aria-label="Report actions">
    <button type="button" onclick="window.print()">Print / Save PDF</button>
    <a href="{{ route('evaluation.tabular-report', ['offering' => $offering, 'kind' => $kind === 'tally' ? 'scores' : 'tally', ...request()->only(['department','form','cycle','instrument'])]) }}">{{ $kind === 'tally' ? 'Scores Report' : 'Tally Report' }}</a>
    <span>A4 landscape · Current faculty, period and filters · Evaluator identities hidden</span>
</nav>
<main>
    <h1>{{ $kind === 'tally' ? 'EVALUATION TALLY REPORT' : 'EVALUATION SCORES REPORT' }}</h1>
    @forelse($reports as $report)
        @php
            $assignment = $report['assignment'];
            $score = $report['score'];
            $tally = $report['tally'];
            $summary = $tally['summary'];
            $contextLabel = $offering->evaluatee->name.' | '.($assignment->course?->code ?? 'Faculty evaluation').' | '.$assignment->evaluation_section_label.' | '.$report['instrument'];
            $format = fn ($value) => $value === null ? '—' : number_format($value, 2);
        @endphp
        <section class="report">
            <h2>{{ $report['instrument'] }}{{ $report['revision'] ? ' · Historical question set '.$report['revision'] : '' }}</h2>
            <div class="meta">
                <div><b>Faculty:</b> {{ $offering->evaluatee->name }}</div>
                <div><b>Campus:</b> {{ $offering->campus->name }}</div>
                <div><b>Teaching Department:</b> {{ $assignment->teachingDepartment?->name ?? $assignment->course?->department?->name ?? $offering->evaluatee->instructorDepartment?->name ?? '—' }}</div>
                <div><b>Period:</b> {{ $offering->semester->name }} / {{ $offering->semester->schoolYear->name }}</div>
                <div><b>Course / Class label:</b> {{ $assignment->course?->code ?? 'Faculty evaluation' }} / {{ $assignment->evaluation_section_label }}</div>
                <div><b>Instrument:</b> {{ $report['form']->name }} · Version {{ $report['form']->version ?? 1 }}</div>
            </div>
            <div class="summary">
                <b>Complete responses:</b> {{ $score['count'] }} &nbsp; <b>Incomplete:</b> {{ $score['incomplete_count'] }} &nbsp;
                <b>Mean / 5:</b> {{ $format($score['mean_5']) }} &nbsp; <b>Rating / 100:</b> {{ $format($score['percentage']) }} &nbsp;
                <b>Mean total per response:</b> {{ $format($score['mean_total']) }} / {{ $tally['maximum'] + 0 }}
            </div>
            @if($tally['section_summary'] ?? [])
                <h3>Responses by section</h3>
                <table aria-label="Section response summary"><thead><tr><th>Section</th><th>Complete</th><th>Incomplete</th><th>Average / {{ $tally['scale_maximum'] + 0 }}</th><th>Rating / 100</th></tr></thead><tbody>
                    @foreach($tally['section_summary'] as $section)<tr><td class="left">{{ $section['section'] }}</td><td>{{ $section['complete'] }}</td><td>{{ $section['incomplete'] }}</td><td>{{ $format($section['average']) }}</td><td>{{ $format($section['percentage']) }}</td></tr>@endforeach
                </tbody></table>
            @endif
            @if($kind === 'scores')
                <div class="table-wrap">
                    <table class="scores" aria-label="Scores by question">
                        <thead>
                            <tr class="context"><th colspan="5">{{ $contextLabel }} · {{ $offering->semester->name }} / {{ $offering->semester->schoolYear->name }}</th></tr>
                            <tr><th style="width:10mm">No.</th><th class="left">Question / Criterion</th><th style="width:24mm">Responses</th><th style="width:27mm">Total Score</th><th style="width:27mm">Average / {{ $tally['scale_maximum'] + 0 }}</th></tr>
                        </thead>
                        <tbody>
                            @forelse($score['fields']->groupBy('section') as $section => $fields)
                                <tr class="section"><th colspan="5">{{ $section }}</th></tr>
                                @foreach($fields as $field)
                                    @php
                                        $number = $score['fields']->search(fn ($item) => $item['name'] === $field['name']) + 1;
                                    @endphp
                                    <tr><td>Q{{ $number }}</td><td class="left">{{ $field['label'] }}</td><td>{{ $field['count'] }}</td><td class="numeric">{{ $format($summary['question_totals'][$number - 1]) }}</td><td class="numeric">{{ $format($field['native_avg']) }}</td></tr>
                                @endforeach
                            @empty
                                <tr><td colspan="5" class="empty">No rating questions in this instrument.</td></tr>
                            @endforelse
                            <tr class="totals"><th colspan="2" class="left">TOTAL / AVERAGE PER QUESTION</th><td>{{ $score['count'] }} complete</td><td class="numeric">{{ $format($summary['grand_total']) }}</td><td class="numeric">{{ $format($summary['mean_average']) }}</td></tr>
                        </tbody>
                    </table>
                </div>
            @else
                @forelse(array_chunk($tally['questions'], 15, true) as $questions)
                    @php
                        $lastPanel = $loop->last;
                        $showSections = $tally['show_sections'] ?? false;
                        $columnCount = 1 + ($showSections ? 1 : 0) + count($questions) + ($lastPanel ? 2 : 0);
                    @endphp
                    <div class="panel">
                        @if(count($tally['questions']) > 15)<p class="panel-label">{{ $contextLabel }} · Questions {{ reset($questions)['number'] }}–{{ end($questions)['number'] }}. Full-response total and average follow the last question panel.</p>@endif
                        <div class="table-wrap">
                            <table class="tally" aria-label="Anonymous evaluator tally">
                                <colgroup><col class="evaluator">@if($showSections)<col>@endif @foreach($questions as $question)<col>@endforeach @if($lastPanel)<col class="total"><col class="average">@endif</colgroup>
                                <thead>
                                    <tr class="context"><th colspan="{{ $columnCount }}">{{ $contextLabel }} · {{ $offering->semester->name }} / {{ $offering->semester->schoolYear->name }}</th></tr>
                                    <tr><th class="left">Evaluator</th>@if($showSections)<th>Section</th>@endif @foreach($questions as $question)<th title="{{ $question['label'] }}">Q{{ $question['number'] }}</th>@endforeach @if($lastPanel)<th>Total Score<br>/ {{ $tally['maximum'] + 0 }}</th><th>Average<br>/ {{ $tally['scale_maximum'] + 0 }}</th>@endif</tr>
                                </thead>
                                <tbody>
                                    @forelse($tally['rows'] as $row)
                                        <tr><th class="left">{{ $row['label'] }}</th>@if($showSections)<td>{{ $row['section'] }}</td>@endif @foreach($questions as $index => $question)<td class="numeric">{{ $row['values'][$index] === null ? '—' : $row['values'][$index] + 0 }}</td>@endforeach @if($lastPanel)<td class="numeric"><b>{{ $row['total'] === null ? 'Incomplete' : $format($row['total']) }}</b></td><td class="numeric"><b>{{ $format($row['average']) }}</b></td>@endif</tr>
                                    @empty
                                        <tr><td colspan="{{ $columnCount }}" class="empty">No submitted responses.</td></tr>
                                    @endforelse
                                    <tr class="totals"><th class="left">Question totals</th>@if($showSections)<td>—</td>@endif @foreach($questions as $index => $question)<td class="numeric">{{ $format($summary['question_totals'][$index]) }}</td>@endforeach @if($lastPanel)<td class="numeric">{{ $format($summary['grand_total']) }}</td><td>—</td>@endif</tr>
                                    <tr class="totals"><th class="left">Question averages</th>@if($showSections)<td>—</td>@endif @foreach($questions as $index => $question)<td class="numeric">{{ $format($summary['question_averages'][$index]) }}</td>@endforeach @if($lastPanel)<td class="numeric">{{ $format($summary['mean_total']) }}</td><td class="numeric">{{ $format($summary['mean_average']) }}</td>@endif</tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="question-guide" aria-label="Question guide">@foreach($questions as $question)<div><b>Q{{ $question['number'] }}.</b> {{ $question['label'] }}</div>@endforeach</div>
                    </div>
                @empty
                    <p class="empty">No rating questions in this instrument.</p>
                @endforelse
            @endif
            <p class="muted note">@if($kind === 'scores')Question total = sum of complete respondents' ratings. Question average = question total ÷ complete responses. Footer average = grand total ÷ (complete responses × questions).@else Total Score = sum of question ratings. Average = total score ÷ number of questions on the instrument's original scale.@endif Only complete, valid responses enter totals and averages. Missing answers are not zero ratings. Mean / 5 and Rating / 100 follow the instrument's scoring rules. Student names, IDs and submission times are omitted.</p>
        </section>
    @empty
        <p class="empty">No evaluation assignments match the selected filters.</p>
    @endforelse
</main>
@include('reports.live-preview')
</body>
</html>
