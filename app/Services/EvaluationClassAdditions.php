<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\EvaluationCycle;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Explicit, additive publication of late classes. Existing assignments are never resaved. */
class EvaluationClassAdditions
{
    private function cycle(User $actor, Campus $campus, int $id): EvaluationCycle
    {
        abort_unless(app(EvaluationCycles::class)->allowed($actor, $campus), 403);
        $cycle = app(EvaluationCycles::class)->query($actor, $campus)->with(['department', 'studentForm.fields', 'supervisorForm.fields'])->findOrFail($id);
        if ($cycle->status !== 'published' || $cycle->ends_at?->isPast()) {
            $this->changed('Only an open or scheduled evaluation can receive new classes.');
        }
        foreach (['studentForm' => 'A', 'supervisorForm' => 'B'] as $relation => $annex) {
            $form = $cycle->$relation;
            if (! $form || ! $form->is_active || $form->archived_at || ($form->settings['annex'] ?? null) !== $annex
                || ! $form->fields->contains(fn ($f) => in_array($f->type, ['rating', 'likert_scale'], true))) {
                $this->changed('The published Annex A and B instruments must remain available.');
            }
        }

        return $cycle;
    }

    public function plan(User $actor, Campus $campus, int $cycleId): array
    {
        $cycle = $this->cycle($actor, $campus, $cycleId);
        // Do not reuse a request-cached draft plan after a roster or assignment changes.
        $plan = (new EvaluationSetupAutomation)->plan($actor, $campus, $cycle->department_id, $cycle->semester_id, $cycle);
        $existing = $plan['classes']->whereNotNull('evaluation_cycle_id')->modelKeys();
        $evaluating = $this->evaluatingBySubject($cycle->semester_id);
        $excluded = collect($plan['excluded'])->reject(fn ($row) => in_array($row['id'], $existing, true))->map(function ($row) use ($plan, $evaluating) {
            $class = $plan['classes']->firstWhere('id', $row['id']);

            return [...$row, 'label' => ($class?->evaluatee?->name ?? 'Faculty needs correction').' — '.$row['label'],
                'covered' => $class ? $this->covered($class, $plan['classes'], $evaluating) : false];
        })->values()->all();
        $rows = [];
        foreach ($plan['prepared']->whereNull('evaluation_cycle_id')->sortBy('id') as $class) {
            $ids = DB::table('course_offering_supervisor')->where('course_offering_id', $class->id)->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            $usesDefault = ! $ids;
            if ($usesDefault && $cycle->department->supervisor_id) {
                $ids = [(int) $cycle->department->supervisor_id];
            }
            $heads = app(FacultySupervisorEvaluators::class)->candidates($class)->whereKey($ids)->get();
            if (! $ids || $heads->count() !== count($ids)) {
                $excluded[] = ['id' => $class->id, 'label' => $class->evaluatee->name.' — '.$class->display_name, 'reason' => 'Choose a valid supervisor other than this faculty in Teaching Classes.'];

                continue;
            }
            $students = $class->students->sortBy('id')->map(fn ($s) => [$s->id, app(EvaluationSections::class)->forStudent($class, $s, true)])->values()->all();
            $sections = collect($students)->pluck(1)->filter()->unique()->implode(', ') ?: EvaluationSections::PENDING;
            $state = [$class->getAttributes(), $students, $ids, $usesDefault, $class->course->only(['id', 'code', 'name', 'department_id']),
                $class->evaluatee->only(['id', 'name', 'is_instructor', 'is_student', 'deleted_at']), $heads->map->only(['id', 'name'])->all()];
            $rows[] = ['id' => $class->id, 'label' => $class->evaluatee->name.' — '.$class->course->code.' · '.$sections,
                'subject' => $class->course->name, 'students' => count($students),
                'already_evaluating' => count(array_intersect(array_column($students, 0), $evaluating[$this->subjectKey($class->course)] ?? [])),
                'supervisor_ids' => $ids, 'supervisors' => $heads->pluck('name')->implode(', '), 'uses_default' => $usesDefault,
                'fingerprint' => hash('sha256', json_encode($state))];
        }

        return ['cycle_id' => $cycle->id, 'cycle_fingerprint' => hash('sha256', json_encode([$cycle->getAttributes(),
            $cycle->studentForm->getAttributes(), $cycle->studentForm->fields->toArray(),
            $cycle->supervisorForm->getAttributes(), $cycle->supervisorForm->fields->toArray()])),
            'rows' => $rows, 'excluded' => $excluded];
    }

    private function subjectKey($course): string
    {
        return mb_strtoupper(trim($course->code)).'|'.mb_strtoupper(trim($course->name));
    }

    /**
     * Students already answering for each subject (code + title) in any evaluation of the period. A late class whose
     * students all appear here is usually a leftover duplicate; adding it would ask them to rate the subject twice.
     */
    private function evaluatingBySubject(int $semesterId): array
    {
        $result = [];
        DB::table('course_offering_student')->join('course_offerings', 'course_offerings.id', '=', 'course_offering_student.course_offering_id')
            ->join('courses', 'courses.id', '=', 'course_offerings.course_id')
            ->where('course_offerings.semester_id', $semesterId)->whereNotNull('course_offerings.evaluation_cycle_id')
            ->select('courses.code', 'courses.name', 'course_offering_student.user_id')->orderBy('course_offering_student.user_id')
            ->each(function ($row) use (&$result) {
                $result[$this->subjectKey($row)][] = (int) $row->user_id;
            });

        return $result;
    }

    /**
     * A left-behind copy needs no action: every student already rates this subject in an evaluation, or it is an
     * empty section copy of a subject whose only teacher already has a class in the evaluation (rosters are grouped).
     */
    private function covered(CourseOffering $class, $classes, array $evaluating): bool
    {
        if (! $class->course) {
            return false;
        }
        $key = $this->subjectKey($class->course);
        if ($class->students->isNotEmpty()) {
            return ! array_diff($class->students->modelKeys(), $evaluating[$key] ?? []);
        }
        $same = $classes->filter(fn ($other) => $other->course && $this->subjectKey($other->course) === $key);

        return $same->pluck('user_id')->unique()->count() === 1
            && $same->contains(fn ($other) => $other->evaluation_cycle_id && $other->students->isNotEmpty());
    }

    public function preview(User $actor, Campus $campus, int $cycleId): array
    {
        $plan = $this->plan($actor, $campus, $cycleId);
        $token = (string) Str::uuid();
        Cache::put($this->key($actor, $campus, $token), $plan, now()->addMinutes(30));

        return [...$plan, 'token' => $token];
    }

    public function enqueue(User $actor, Campus $campus, int $cycleId, string $token, array $ids)
    {
        $this->cycle($actor, $campus, $cycleId);
        $plan = Cache::get($this->key($actor, $campus, $token));
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if (! $plan || $plan['cycle_id'] !== $cycleId || ! $ids || array_diff($ids, array_column($plan['rows'], 'id'))) {
            $this->changed('Open the preview again and select the prepared classes to add.');
        }
        $plan['rows'] = array_values(array_filter($plan['rows'], fn ($row) => in_array($row['id'], $ids, true)));

        return app(BackgroundTasks::class)->enqueue($actor, $campus, 'evaluation-append', 'Add newly prepared evaluation classes', $plan,
            $token.'|'.implode(',', $ids));
    }

    public function apply(User $actor, Campus $campus, array $payload): array
    {
        return DB::transaction(function () use ($actor, $campus, $payload) {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('campuses')->where('id', $campus->id)->update(['id' => $campus->id]);
            } else {
                Campus::whereKey($campus->id)->lockForUpdate()->firstOrFail();
            }
            $actor = User::withoutGlobalScope('assigned_data')->findOrFail($actor->id);
            // Serialize with publication/closure on databases with row-level locks too.
            EvaluationCycle::whereKey($payload['cycle_id'])->lockForUpdate()->firstOrFail();
            CourseOffering::whereKey(array_column($payload['rows'], 'id'))->lockForUpdate()->get();
            $cycle = $this->cycle($actor, $campus, (int) $payload['cycle_id']);
            $fresh = $this->plan($actor, $campus, $cycle->id);
            if ($fresh['cycle_fingerprint'] !== $payload['cycle_fingerprint']) {
                $this->changed();
            }
            $already = $cycle->assignments()->whereNotNull('course_id')->pluck('id')->all();
            $pending = collect($payload['rows'])->reject(fn ($row) => in_array($row['id'], $already, true));
            foreach ($pending as $row) {
                $current = collect($fresh['rows'])->firstWhere('id', $row['id']);
                if (! $current || $current['fingerprint'] !== $row['fingerprint']) {
                    $this->changed();
                }
            }
            foreach ($pending as $row) {
                $class = (new EvaluationSetupAutomation)->classQuery($actor, $campus, $cycle->department_id, $cycle->semester_id)
                    ->whereNull('evaluation_cycle_id')->lockForUpdate()->findOrFail($row['id']);
                $class->update(['evaluation_cycle_id' => $cycle->id, 'form_id' => $cycle->student_form_id,
                    'evaluation_type' => 'SET', 'teaching_department_id' => $cycle->department_id]);
                if ($row['uses_default']) {
                    app(FacultySupervisorEvaluators::class)->save($actor, $class, $row['supervisor_ids']);
                } else {
                    app(SupervisorEvaluationAssignments::class)->prepareSource($class);
                }
            }
            ClassroomUpdates::panel('setup');

            return ['added_classes' => $pending->count(), 'preserved_classes' => count($already),
                'message' => $pending->count().' prepared classes added, including their supervisor tasks. Existing assignments and responses were preserved.'];
        });
    }

    private function key(User $actor, Campus $campus, string $token): string
    {
        return 'evaluation-additions:'.$actor->id.':'.$campus->id.':'.$token;
    }

    private function changed(string $message = 'The setup changed after this preview. Open Add Newly Prepared Classes again to review the latest classes.'): never
    {
        throw ValidationException::withMessages(['additions' => $message]);
    }
}
