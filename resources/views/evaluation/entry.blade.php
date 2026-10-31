<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Faculty Evaluation</title>
    <x-bisu-favicon />
<style>
body{margin:0;background:#f5f7fb;color:#172033;font:16px/1.6 system-ui,sans-serif}main{max-width:880px;margin:48px auto;padding:0 20px}section,article{background:white;border:1px solid #dfe5ef;border-radius:14px;padding:24px;margin:16px 0}h1,h2{line-height:1.3}h2{font-size:20px}p{color:#526078}.button,button{display:inline-block;background:#4f46e5;color:white;border:0;border-radius:8px;padding:10px 18px;text-decoration:none;font:inherit;cursor:pointer}label{display:block;font-weight:600}input{box-sizing:border-box;width:100%;padding:12px;border:1px solid #94a3b8;border-radius:8px;margin:8px 0 16px;font:inherit}.error{color:#b91c1c}.badge{color:#166534;font-weight:600}.secondary{background:#e8ecf5;color:#24314b}footer{margin-top:32px;color:#526078}.progress{padding:16px 24px}.bar{height:10px;background:#e8ecf5;border-radius:99px;margin-top:8px;overflow:hidden}.bar span{display:block;height:100%;background:#16a34a}section.reminder{display:flex;gap:14px;background:#fff8e1;border-color:#f6d77a}.reminder-icon{font-size:26px;line-height:1}.reminder p{margin:6px 0 0;color:#5b4a1a}.reminder strong{color:#92400e}.reminder .reminder-from{font-weight:600;color:#78350f}.reminder .reminder-time{font-size:13px;color:#a16207}@media (max-width:640px){main{margin:24px auto;padding:0 14px}section,article{padding:16px}}
</style></head><body><main>
<h1>Faculty Evaluation</h1><p>Enter your student ID to view and complete your assigned faculty evaluations.</p>
@if (!$student)
<section><form method="POST" action="{{ route('evaluation.identify') }}">@csrf
<label for="student_id">Student ID number</label><input id="student_id" name="student_id" value="{{ old('student_id') }}" required autofocus autocomplete="username">
@error('student_id')<p class="error" role="alert">{{ $message }}</p>@enderror
<button type="submit">Continue to evaluations</button></form></section>
@else
<section><strong>Student ID: {{ $student->student_id }}</strong><form method="POST" action="{{ route('evaluation.leave') }}">@csrf<button class="secondary" type="submit">Use another student ID</button></form></section>
@if ($reminder)
<section class="reminder" role="status"><div class="reminder-icon" aria-hidden="true">🔔</div><div><strong>{{ $reminder->subject }}</strong>
@if ($reminderLine)<p class="reminder-from">{{ $reminderLine }}</p>@endif
@if (filled($reminder->message))<p>{{ $reminder->message }}</p>@endif
<p class="reminder-time">Sent {{ $reminder->created_at->diffForHumans() }}</p></div></section>
@endif
<div id="evaluation-assignments"><h2>Your assigned evaluations</h2>
@if ($assignments->isNotEmpty())
@php($doneCount = $assignments->where('completed', true)->count())
<section class="progress"><strong>{{ $doneCount }} of {{ $assignments->count() }} done</strong>@if ($doneCount === $assignments->count()) · Thank you, you have finished every evaluation.@else · {{ $assignments->count() - $doneCount }} left to evaluate, listed first.@endif
<div class="bar" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $assignments->count() }}" aria-valuenow="{{ $doneCount }}"><span style="width:{{ round($doneCount / $assignments->count() * 100) }}%"></span></div></section>
@endif
@forelse ($assignments as $assignment)
@php($offering = $assignment['offering'])
<article><h2>{{ $offering->evaluatee?->name }}</h2><p><strong>{{ $offering->evaluation_type_label }}</strong><br>{{ $offering->course?->name ?? 'Faculty evaluation' }} · {{ $offering->section }}<br>{{ $offering->evaluationForm->name }}</p>
@if ($assignment['completed'])<span class="badge">Completed</span>
@elseif ($assignment['available'])<a class="button" href="{{ route('evaluate.course-offering', $offering) }}">Evaluate</a>
@else<p>Not currently available</p>@endif
</article>
@empty<section>No faculty evaluations are assigned to this student ID yet. Please contact your school.</section>@endforelse</div>
@vite('resources/js/evaluation-entry-live.js')
@endif
<footer><a href="{{ route('filament.admin.auth.login') }}">Faculty &amp; staff sign-in</a></footer>
</main></body></html>
