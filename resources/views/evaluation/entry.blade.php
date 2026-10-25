<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Faculty Evaluation</title>
<style>
body{margin:0;background:#f5f7fb;color:#172033;font:16px/1.6 system-ui,sans-serif}main{max-width:880px;margin:48px auto;padding:0 20px}section,article{background:white;border:1px solid #dfe5ef;border-radius:14px;padding:24px;margin:16px 0}h1,h2{line-height:1.3}h2{font-size:20px}p{color:#526078}.button,button{display:inline-block;background:#4f46e5;color:white;border:0;border-radius:8px;padding:10px 18px;text-decoration:none;font:inherit;cursor:pointer}label{display:block;font-weight:600}input{box-sizing:border-box;width:100%;padding:12px;border:1px solid #94a3b8;border-radius:8px;margin:8px 0 16px;font:inherit}.error{color:#b91c1c}.badge{color:#166534;font-weight:600}.secondary{background:#e8ecf5;color:#24314b}footer{margin-top:32px;color:#526078}
</style></head><body><main>
<h1>Faculty Evaluation</h1><p>Enter your student ID to view and complete your assigned faculty evaluations.</p>
@if (!$student)
<section><form method="POST" action="{{ route('evaluation.identify') }}">@csrf
<label for="student_id">Student ID number</label><input id="student_id" name="student_id" value="{{ old('student_id') }}" required autofocus autocomplete="username">
@error('student_id')<p class="error" role="alert">{{ $message }}</p>@enderror
<button type="submit">Continue to evaluations</button></form></section>
@else
<section><strong>Student ID: {{ $student->student_id }}</strong><form method="POST" action="{{ route('evaluation.leave') }}">@csrf<button class="secondary" type="submit">Use another student ID</button></form></section>
<div id="evaluation-assignments"><h2>Your assigned evaluations</h2>
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
<footer><a href="{{ route('filament.admin.auth.login') }}">Instructor or assessment sign-in</a></footer>
</main></body></html>
