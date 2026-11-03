<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\TeachingClassSourceLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Import-approved matching policy. Unknown teacher choices never become assignments. */
class AutomaticEvaluatorAssignments
{
    public function allowed(User $actor, Campus $campus): bool
    {
        return ! $actor->isStudent() && ! $actor->trashed() && $actor->canAccessTenant($campus)
            && $actor->can('manage_evaluators_course::offering') && $actor->can('update_course::offering')
            && (! filament()->getTenant() || filament()->getTenant()->id === $campus->id);
    }

    private function subject(string $code, string $name): string
    {
        return ImportedCourseCatalogue::codeKey($code).'|'.StudentAcademicRecords::code($name);
    }

    private function program(string $code): string
    {
        $code = ImportedDepartments::code($code);

        return preg_match('/^BISU[A-Z0-9]*-(.+)-20\d{2}$/', $code, $m) ? $m[1] : $code;
    }

    private function canonical($classes): ?CourseOffering
    {
        return $classes->sort(fn ($a, $b) => strnatcasecmp($a->section ?? '', $b->section ?? '') ?: $a->id <=> $b->id)->first();
    }

    private function sectionMatches(CourseOffering $class, User $student): bool
    {
        return $student->studentSectionMemberships->contains(function ($m) use ($class) {
            return in_array($m->department_id.'~'.$m->semester_id.'~'.rawurlencode($m->section), $class->evaluator_selection['sections'] ?? [], true)
                || ((int) $m->department_id === (int) ($class->teaching_department_id ?? $class->course->department_id)
                    && filled($class->section) && TeachingClassRosterImports::section($m->section) === TeachingClassRosterImports::section($class->section));
        });
    }

    public function plan(User $actor, Campus $campus, int $semesterId): array
    {
        abort_unless($this->allowed($actor, $campus), 403);
        AcademicPeriod::validate($campus, $semesterId);
        $classes = CourseOffering::withoutGlobalScope('assigned_data')->where('semester_id', $semesterId)->whereNotNull('course_id')->where('is_active', true)
            ->whereHas('course', fn ($q) => $q->withoutGlobalScope('assigned_data')->whereHas('department', fn ($d) => $d->withoutGlobalScope('assigned_data')->where('campus_id', $campus->id)))
            ->whereHas('evaluatee', fn ($q) => $q->withoutGlobalScope('assigned_data')->where('is_instructor', true)->where('is_student', false))
            ->with(['course', 'evaluationForm'])->withExists([
                'formSubmissions' => fn ($q) => $q->withoutGlobalScope('assigned_data'),
                'ratings' => fn ($q) => $q->withoutGlobalScope('assigned_data'),
                'quizzes' => fn ($q) => $q->withoutGlobalScope('assigned_data'),
            ])->get()->reject(fn ($c) => $c->evaluationForm?->isSupervisorInstrument())->keyBy('id');
        $links = TeachingClassSourceLink::where('campus_id', $campus->id)->whereIn('course_offering_id', $classes->modelKeys())->get()->groupBy('course_offering_id');
        $visible = app(AssignedDataAccess::class)->apply(CourseOffering::query(), $actor, $campus)->where('semester_id', $semesterId)->pluck('id')->flip();
        $managed = $classes->filter(function ($class) use ($links, $visible) {
            $s = $class->evaluator_selection ?? [];
            $enabled = array_key_exists('auto_enrolment', $s) ? $s['auto_enrolment'] : (! empty($s['auto_section_roster']) && ($s['source'] ?? '') === 'sections_v1');

            return $visible->has($class->id) && ($links->has($class->id) || ! empty($s['sectioning_policy'])) && $enabled && ! $class->form_id && ! $class->evaluation_cycle_id
                && ! $class->form_submissions_exists && ! $class->ratings_exists && ! $class->quizzes_exists
                && empty($s['include']) && empty($s['exclude']) && in_array($s['source'] ?? '', ['sections_v1', 'auto_enrolment_v1'], true);
        });
        if ($managed->isEmpty()) {
            return ['classes' => [], 'unresolved' => 0, 'checked_students' => 0];
        }
        $pivots = collect();
        foreach ($classes->keys()->chunk(500) as $ids) {
            $pivots = $pivots->merge(DB::table('course_offering_student')->whereIn('course_offering_id', $ids)->get(['course_offering_id', 'user_id']));
        }
        $byClass = $pivots->groupBy('course_offering_id');
        $groups = $classes->groupBy(fn ($c) => $this->subject($c->course->code, $c->course->name).'|'.(int) $c->evaluation_cycle_id);
        $multiTeacherGroups = $groups->map(fn ($g) => $g->pluck('user_id')->unique()->count() > 1);
        // A student already in any class of the subject, including classes prepared
        // in an evaluation cycle, has a confirmed teacher; never add a second class.
        $existing = [];
        foreach ($classes as $class) {
            $key = $this->subject($class->course->code, $class->course->name).'|0';
            foreach ($byClass->get($class->id, collect()) as $pivot) {
                $existing[$key][$pivot->user_id][] = $class->id;
            }
        }
        $programs = [];
        foreach ($links as $classId => $sources) {
            $programs[$classId] = $sources->flatMap(fn ($link) => $link->metadata['rows'] ?? [])->map(fn ($r) => $this->program($r['code'] ?? ''))->filter()->unique()->all();
        }
        $items = [];
        foreach ($managed as $class) {
            $key = $this->subject($class->course->code, $class->course->name).'|0';
            $teacherClasses = $groups->get($key, collect())->where('user_id', $class->user_id)->filter(fn ($c) => $managed->has($c->id));
            $canonical = $this->canonical($teacherClasses);
            $items[$class->id] = ['class' => $class, 'add' => [], 'remove' => [], 'labels' => $class->evaluator_selection['report_sections'] ?? [],
                'current' => $byClass->get($class->id, collect())->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
                'stats' => ['candidates' => 0, 'ambiguous' => 0, 'section_required' => 0, 'elsewhere' => 0, 'grouped_class_id' => $canonical?->id !== $class->id ? $canonical?->id : null]];
        }
        $students = app(AcademicStudents::class)->students($actor, $campus)
            ->with(['roles.permissions', 'permissions', 'subjectEnrolments' => fn ($q) => $q->where('semester_id', $semesterId),
                'academicRecords' => fn ($q) => $q->where('semester_id', $semesterId),
                'studentSectionMemberships' => fn ($q) => $q->where('semester_id', $semesterId)])->get()
            ->filter(fn ($s) => app(AcademicStudents::class)->isSafeStudentTarget($s))->keyBy('id');
        foreach ($items as $id => &$item) {
            foreach ($item['current'] as $studentId) {
                $student = $students->get($studentId);
                $groupKey = $this->subject($item['class']->course->code, $item['class']->course->name).'|0';
                if ($student && empty($item['labels'][$studentId])) {
                    $item['labels'][$studentId] = app(EvaluationSections::class)->forStudent($item['class'], $student, $multiTeacherGroups->get($groupKey, false));
                }
            }
        }
        unset($item);
        $unresolved = [];
        foreach ($students as $student) {
            $program = ImportedDepartments::code($student->academicRecords->first()?->program ?? '');
            $enrolments = $student->subjectEnrolments->unique('subject_key')->values();
            if ($enrolments->isEmpty()) {
                // A confirmed saved section is still authoritative when a campus
                // student list contains no subject blocks at all.
                foreach ($groups as $group) {
                    if ($group->contains(fn ($c) => $this->sectionMatches($c, $student))) {
                        $enrolments->push((object) ['subject_code' => $group->first()->course->code, 'description' => $group->first()->course->name]);
                    }
                }
            }
            foreach ($enrolments as $enrolment) {
                $key = $this->subject($enrolment->subject_code, $enrolment->description).'|0';
                $candidates = $groups->get($key, collect());
                if ($candidates->isEmpty()) {
                    continue;
                }
                $multiTeacher = $candidates->pluck('user_id')->unique()->count() > 1;
                $byProgram = $program === '' ? collect() : $candidates->filter(fn ($c) => in_array($program, $programs[$c->id] ?? [], true));
                if ($byProgram->isNotEmpty()) {
                    // Unknown/manual class programs remain possible; do not hide a
                    // competing teacher merely because their source metadata is absent.
                    $candidates = $candidates->filter(fn ($c) => empty($programs[$c->id]) || in_array($program, $programs[$c->id], true));
                }
                if (! $candidates->contains(fn ($c) => isset($items[$c->id]))) {
                    continue;
                }
                $sectionMatches = $candidates->filter(fn ($class) => $this->sectionMatches($class, $student));
                $pool = $sectionMatches->isNotEmpty() ? $sectionMatches : $candidates;
                foreach ($candidates as $c) {
                    if (isset($items[$c->id])) {
                        $items[$c->id]['stats']['candidates']++;
                    }
                }
                $prior = $existing[$key][$student->id] ?? [];
                $target = (! $multiTeacher || $sectionMatches->isNotEmpty()) && $pool->pluck('user_id')->unique()->count() === 1 && $pool->every(fn ($c) => $managed->has($c->id))
                    ? $this->canonical($pool->filter(fn ($c) => $managed->has($c->id))) : null;
                if ($prior !== []) {
                    // A confirmed teacher assignment is authoritative. Move only a
                    // record this policy created, still draft, when section evidence
                    // now identifies the same faculty's actual class.
                    $old = count($prior) === 1 ? $classes->get($prior[0]) : null;
                    $tracked = $old && in_array($student->id, $old->evaluator_selection['auto_assigned_ids'] ?? [], true);
                    if ($old && $target && $old->id !== $target->id && $old->user_id === $target->user_id && $managed->has($old->id) && $tracked && $sectionMatches->count() === 1) {
                        $items[$old->id]['remove'][] = $student->id;
                    } else {
                        $sharedPending = $old && $tracked && $managed->has($old->id) && empty($items[$old->id]['labels'][$student->id]);
                        foreach ($candidates as $c) {
                            if (isset($items[$c->id]) && ! in_array($c->id, $prior, true)
                                && ! ($sharedPending && $old->user_id === $c->user_id)) {
                                $items[$c->id]['stats']['elsewhere']++;
                            }
                        }
                        foreach ($prior as $id) {
                            if (isset($items[$id]) && empty($items[$id]['labels'][$student->id])) {
                                $items[$id]['labels'][$student->id] = app(EvaluationSections::class)->forStudent($classes[$id], $student, $multiTeacher);
                            }
                        }
                        foreach ($prior as $id) {
                            if (isset($items[$id]) && $multiTeacher && empty($items[$id]['labels'][$student->id])) {
                                $items[$id]['stats']['section_required']++;
                                $unresolved[$key.'|'.$student->id] = true;
                            }
                        }

                        continue;
                    }
                }
                if (! $target) {
                    foreach ($candidates as $c) {
                        if (isset($items[$c->id])) {
                            $items[$c->id]['stats']['ambiguous']++;
                        }
                    }
                    $unresolved[$key.'|'.$student->id] = true;

                    continue;
                }
                $items[$target->id]['add'][$student->id] = $student->id;
                $items[$target->id]['labels'][$student->id] = app(EvaluationSections::class)->forStudent($target, $student, $multiTeacher);
                $existing[$key][$student->id] = [$target->id];
            }
        }

        return ['classes' => $items, 'unresolved' => count($unresolved), 'checked_students' => $students->count()];
    }

    public function synchronize(User $actor, Campus $campus, int $semesterId): array
    {
        if (! $this->allowed($actor, $campus)) {
            return ['assigned' => 0, 'moved' => 0, 'classes' => 0, 'unresolved' => 0, 'skipped' => true];
        }

        return DB::transaction(function () use ($actor, $campus, $semesterId) {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('campuses')->where('id', $campus->id)->update(['id' => $campus->id]);
            } else {
                Campus::whereKey($campus->id)->lockForUpdate()->firstOrFail();
            }
            $actor = User::withoutGlobalScope('assigned_data')->findOrFail($actor->id);
            $plan = $this->plan($actor, $campus, $semesterId);
            $counts = [];
            foreach ($plan['classes'] as $id => $item) {
                $counts[$id] = count(array_unique(array_diff(array_merge($item['current'], array_values($item['add'])), $item['remove'])));
            }
            $assigned = $moved = $changed = 0;
            foreach ($plan['classes'] as $id => $item) {
                $class = $item['class'];
                $remove = array_values(array_unique($item['remove']));
                $new = array_values(array_diff(array_values($item['add']), $item['current']));
                if ($remove) {
                    $class->students()->detach($remove);
                    $moved += count($remove);
                }
                foreach (array_chunk($new, 400) as $ids) {
                    $class->students()->attach(collect($ids)->mapWithKeys(fn ($studentId) => [$studentId => ['enrolled_at' => now()]])->all());
                }
                $final = array_values(array_unique(array_diff(array_merge($item['current'], $new), $remove)));
                $labels = array_intersect_key($item['labels'], array_flip($final));
                $s = $class->evaluator_selection ?? [];
                $tracked = array_values(array_intersect($final, array_unique(array_diff(array_merge($s['auto_assigned_ids'] ?? [], $new), $remove))));
                $class->update(['evaluator_selection' => [...$s, 'source' => 'auto_enrolment_v1', 'auto_enrolment' => true,
                    'auto_assigned_ids' => $tracked, 'report_sections' => $labels, 'count' => count($final),
                    'auto_match' => [...$item['stats'], 'grouped_count' => $counts[$item['stats']['grouped_class_id']] ?? 0,
                        'pending_section' => count(array_filter($final, fn ($studentId) => empty($labels[$studentId]))), 'checked_at' => now()->toIso8601String()]]]);
                $assigned += count($new);
                $changed += (int) (bool) ($new || $remove);
            }
            ClassroomUpdates::panel('setup');

            return ['assigned' => $assigned, 'moved' => $moved, 'classes' => $changed, 'unresolved' => $plan['unresolved'], 'skipped' => false];
        });
    }
}
