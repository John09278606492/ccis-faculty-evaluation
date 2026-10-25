@php
$submission = $copy['submission']; $form = $submission->form; $assignment = $submission->courseOffering;
$fields = $form->fields->whereIn('type', ['likert_scale','rating']);
$scale = collect($form->rating_scale ?? [])->sortByDesc('value');
$values = $fields->map(fn ($field) => $submission->data[$field->name] ?? null);
$complete = $fields->isNotEmpty() && !$values->contains(fn ($value) => !is_numeric($value));
$maximum = $fields->count() * ($scale->max('value') ?? 5); $total = $complete ? $values->sum() : null; $number = 0;
$supervisor = $form->isSupervisorInstrument();
@endphp
<article class="copy" data-response-copy="{{ $submission->id }}"><div class="copy-content">
<div class="copy-id">Annex {{ $form->settings['annex'] }} · {{ $copy['label'] }}</div>
<h1>EVALUATION INSTRUMENT<br>{{ $supervisor ? 'SUPERVISOR’S EVALUATION OF FACULTY' : 'STUDENT EVALUATION OF TEACHERS (SET)' }}</h1>
<h2>A. Faculty Information <span style="font-weight:normal">(to be accomplished by the Designated Office)</span></h2>
<div class="meta"><p><b>Name of Faculty being Evaluated:</b> <span class="value">{{ $assignment->evaluatee?->name ?? '—' }}</span></p>
@if($supervisor)<p><b>Academic Rank:</b> {{ $form->settings['academic_rank'] ?? '—' }}</p>@endif
<p><b>College/Department:</b> {{ $assignment->teachingDepartment?->name ?? $assignment->course?->department?->name ?? $assignment->evaluatee?->instructorDepartment?->name ?? '—' }}</p>
<p><b>Course Code/Title:</b> {{ $assignment->course?->code ?? 'Faculty evaluation' }} / {{ $assignment->course?->name ?? '—' }}</p>
<p><b>Program Level / Section:</b> {{ $form->settings['program_level'] ?? '' }} {{ $assignment->section }}</p>
<p><b>Semester or Term/Academic Year:</b> {{ $assignment->semester->name }} / {{ $assignment->semester->schoolYear->name }}</p></div>
<h2>B. Rating Scale</h2><table class="scale"><colgroup><col class="scale-number"><col class="scale-label"><col></colgroup><thead><tr><th>Scale</th><th>Qualitative Description</th><th>Operational Definition{{ $supervisor ? ' and Scale' : '' }}</th></tr></thead><tbody>@foreach($scale as $option)<tr><td>{{ $option['value'] }}</td><td>{{ $option['label'] }}</td><td>{{ $option['description'] ?? '' }}</td></tr>@endforeach</tbody></table>
<p class="instruction"><b>C. Instruction:</b> Read the benchmark statements carefully. Rate the faculty using the above-listed rating scale. The circled values below reproduce the submitted ratings.@if($supervisor) The Suggested Means of Verification guide the supervisor's assessment.@endif</p>
<table class="ratings {{ $supervisor ? 'sef' : '' }}"><colgroup><col class="benchmark">@if($supervisor)<col class="verification">@endif @foreach($scale as $option)<col>@endforeach</colgroup><thead><tr><th>Benchmark Statements{{ $supervisor ? '' : ' for Faculty Teaching Effectiveness' }}</th>@if($supervisor)<th>Suggested Means of Verification</th>@endif<th colspan="{{ $scale->count() }}">Rating</th></tr></thead><tbody>
@foreach($fields->groupBy('section') as $section => $questions)
<tr class="section-heading"><th colspan="{{ 1+$scale->count()+($supervisor?1:0) }}">{{ $section ?: 'Criteria' }}</th></tr>
@if(!empty($form->settings['section_descriptions'][$section]))<tr class="section-description"><td colspan="{{ 1+$scale->count()+($supervisor?1:0) }}">{{ $form->settings['section_descriptions'][$section] }}</td></tr>@endif
@foreach($questions as $field)<tr><td>{{ ++$number }}. {{ $field->question_text }}@if(!empty($submission->data['_field_comments'][$field->name]))<p class="muted answer">Comment: {{ $submission->data['_field_comments'][$field->name] }}</p>@endif</td>@if($supervisor)<td><ul class="verification-list">@foreach(explode(';', $field->help_text ?? '') as $verification)@if(trim($verification))<li>{{ trim($verification) }}</li>@endif @endforeach</ul></td>@endif
@foreach($scale as $option)<td class="rating">@if(is_numeric($submission->data[$field->name] ?? null) && (float)$submission->data[$field->name] === (float)$option['value'])<span class="marked">{{ $option['value'] }}</span>@else{{ $option['value'] }}@endif</td>@endforeach</tr>@endforeach
@endforeach
<tr><th @if($supervisor) colspan="2" @endif style="text-align:right">TOTAL SCORE</th><td colspan="{{ $scale->count() }}" style="text-align:center"><b>{{ $total === null ? 'Incomplete' : $total }}</b></td></tr>
</tbody></table>
@foreach($form->fields->whereNotIn('type', ['likert_scale','rating','section_header']) as $field)<h2>{{ $field->question_text }}</h2><p class="answer">{{ is_array($submission->data[$field->name] ?? null) ? implode(', ', $submission->data[$field->name]) : ($submission->data[$field->name] ?? 'No answer provided.') }}</p>@endforeach
<h2>Other comments and suggestions{{ $supervisor ? '' : ' (Optional)' }}:</h2><p class="answer comments">{{ $submission->data['_overall_comment'] ?? 'No comments provided.' }}</p>
<div class="identity"><p><b>Signature of {{ $supervisor ? 'Supervisor' : 'Evaluator' }}:</b> ____________________</p><p class="muted">Electronically submitted; no handwritten signature collected.</p><p><b>{{ $supervisor ? 'Name of Supervisor' : 'Name of Evaluator/ID number' }}:</b> @if($reveal){{ $copy['identity']['name'] ?? 'Identity unavailable for this anonymous record' }} {{ $copy['identity']['student_id'] ?? '' }}@else{{ $copy['label'] }} — Identity hidden @endif</p><p><b>Date:</b> {{ $submission->submitted_at?->format('F j, Y') ?? $submission->created_at?->format('F j, Y') }}</p></div>
<p class="muted"><i>Note: Your feedback is important in enhancing the educational programs of State Universities and Colleges (SUCs). Information provided in this instrument will be treated as strictly confidential.</i></p>
<div class="computation"><h2><u>{{ $supervisor ? 'SEF' : 'SET' }} RATING Computation</u></h2><p><b>Total Score:</b> {{ $total === null ? 'Incomplete' : $total }} / {{ $maximum }}</p><p><b>Formula:</b><br>Rating = (Total Score / {{ $maximum }}) × 100</p><p><b>COMPUTED RATING:</b> {{ $total !== null && $maximum > 0 ? number_format($total/$maximum*100,2).'%' : 'Unavailable — incomplete response' }}</p></div>
</div></article>
