<?php

namespace App\Services;

use App\Models\AcademicSection;
use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\FacultySourceLink;
use App\Models\Semester;
use App\Models\TeachingClassSourceLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/** Source faculty IDs are source identities, never local primary keys or assumed HR IDs. */
class InstructorOfferingImports
{
    public const REQUIRED_COLUMNS = ['sy', 'sem', 'course_code', 'course_desc', 'units', 'section', 'id', 'last_name', 'first_name', 'campus_id', 'code', 'name'];

    public function allowed(User $actor, Campus $campus): bool
    {
        return ! $actor->isStudent() && ! $actor->trashed() && $actor->canAccessTenant($campus)
            && $actor->can('create_course::offering') && $actor->can('update_course::offering')
            && $actor->can('manage_evaluators_course::offering');
    }

    public function authorize(User $actor, Campus $campus): void
    {
        abort_unless($this->allowed($actor, $campus), 403);
    }

    public static function key(?string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($value ?? '')));
    }

    private function nameKey(?string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(Str::ascii($value ?? '')));
    }

    private function term(string $name): ?string
    {
        $name = self::key($name);

        return match (true) {
            (bool) preg_match('/^(1|1ST|FIRST)(\b|\s)/', $name) => '1',
            (bool) preg_match('/^(2|2ND|SECOND)(\b|\s)/', $name) => '2',
            in_array($name, ['S', 'SUMMER', 'MIDYEAR', 'MID YEAR'], true) => 'S',
            default => null,
        };
    }

    public function read(string $source): array
    {
        return $this->readFile($source)['rows'];
    }

    private function readFile(string $source): array
    {
        try {
            return app(InstructorOfferingFileReader::class)->read(Storage::disk('local')->path($source));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['file' => 'Could not read this file. Upload the original CSV, XLSX or XLS workbook.']);
        }
    }

    private function departmentCode(array $row): ?string
    {
        $name = self::key($row['name']);

        return match (true) {
            str_contains($name, 'CRIMINOLOGY') => 'CCJ',
            str_contains($name, 'COMPUTER SCIENCE'), str_contains($name, 'INFORMATION TECHNOLOGY') => 'CCIS',
            str_contains($name, 'INDUSTRIAL TECHNOLOGY'), str_contains($name, 'ELECTRICAL TECHNOLOGY'), str_contains($name, 'ELECTRONICS TECHNOLOGY') => 'CTAS',
            default => null,
        };
    }

    private function sourcePeriod(Campus $campus, int $semesterId, ?string $sourceTerm = null): array
    {
        AcademicPeriod::validate($campus, $semesterId);
        $period = Semester::with('schoolYear')->findOrFail($semesterId);
        $year = preg_replace('/\s+/', '', $period->schoolYear->name);
        $term = $this->term($period->name) ?? $sourceTerm;
        if (! preg_match('/^20\d{2}-20\d{2}$/', $year) || ! in_array($term, ['1', '2', 'S'], true)) {
            throw ValidationException::withMessages(['semester_id' => 'Choose a school year such as 2026-2027 and a first, second or summer semester.']);
        }

        return [$period, $year, $term];
    }

    public function exampleRows(Campus $campus, int $semesterId, string $sourceCampusId): array
    {
        [, $year, $term] = $this->sourcePeriod($campus, $semesterId);

        return [
            self::REQUIRED_COLUMNS,
            [$year, $term, 'SAMPLE 101', 'Replace with actual subject title', '3', 'BSIT 3A', 'REPLACE-FACULTY-001', 'Dela Cruz', 'Sample', $sourceCampusId, 'REPLACE-PROGRAM-CODE', 'Bachelor of Science in Information Technology'],
            [$year, $term, 'SAMPLE 101', 'Replace with actual subject title', '3', 'BSIT 3B', 'REPLACE-FACULTY-001', 'Dela Cruz', 'Sample', $sourceCampusId, 'REPLACE-PROGRAM-CODE', 'Bachelor of Science in Information Technology'],
        ];
    }

    public function prepare(User $actor, Campus $campus, array $options, string $source): array
    {
        $this->authorize($actor, $campus);
        [$period, $year, $term] = $this->sourcePeriod($campus, (int) $options['semester_id'], $options['source_term'] ?? null);
        $file = $this->readFile($source);
        $all = $file['rows'];
        $rows = array_values(array_filter($all, fn ($r) => preg_replace('/\s+/', '', str_replace(['–', '—'], '-', $r['sy'])) === $year && $this->term($r['sem']) === $term));
        $unclassified = array_values(array_filter($all, fn ($r) => ! preg_match('/^20\d{2}-20\d{2}$/', preg_replace('/\s+/', '', str_replace(['–', '—'], '-', $r['sy'])))
            || ($this->term($r['sem']) === null && self::key($r['sem']) !== '3')));
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'No offerings match '.$period->name.' / '.$year.'. Choose the academic period that matches the file.']);
        }
        $options = [...$options, 'source_term' => $term, 'source_year' => $year];
        $analysis = $this->analyze($actor, $campus, $options, $rows);

        return ['plan' => ['actor_id' => $actor->id, 'campus_id' => $campus->id, 'options' => $options, 'rows' => $rows,
            'state_hash' => $analysis['state_hash']],
            'review' => [...$analysis['review'], 'other_period_rows' => count($all) - count($rows) - count($unclassified), 'unclassified_period_rows' => $unclassified,
                'sources' => $file['sources'], 'ignored_sheets' => $file['ignored_sheets'], 'repeated_headers' => $file['repeated_headers'], 'period' => $period->name.' / '.$year]];
    }

    private function overrides(array $items, string $source, string $target): array
    {
        $result = [];
        foreach ($items as $item) {
            $key = self::key($item[$source] ?? '');
            if ($key === '' || empty($item[$target]) || isset($result[$key])) {
                throw ValidationException::withMessages(['file' => 'Complete each optional match and use each source value only once.']);
            }
            $result[$key] = (int) $item[$target];
        }

        return $result;
    }

    public function analyze(User $actor, Campus $campus, array $options, array $rows): array
    {
        $this->authorize($actor, $campus);
        $semester = AcademicPeriod::validate($campus, (int) $options['semester_id']);
        $allowed = app(AssignedDataAccess::class)->departmentIds($actor, $campus);
        $selected = array_map('intval', $options['department_ids'] ?? []);
        abort_unless($allowed === null || array_diff($selected, $allowed) === [], 403);
        $allDepartments = Department::withoutGlobalScope('assigned_data')->where('campus_id', $campus->id)->get();
        abort_unless(array_diff($selected, $allDepartments->modelKeys()) === [], 403);
        $departments = $allDepartments->keyBy(fn ($d) => self::key($d->code));
        $facultyMatches = $this->overrides($options['faculty_matches'] ?? [], 'source_id', 'user_id');
        $programMatches = $this->overrides($options['program_matches'] ?? [], 'program_code', 'department_id');
        abort_unless(array_diff(array_values($programMatches), $allDepartments->where('is_active', true)->modelKeys()) === []
            && ($allowed === null || array_diff(array_values($programMatches), $allowed) === []), 403);
        $sectionMatches = $this->overrides($options['section_matches'] ?? [], 'source_section', 'section_id');
        $teachers = User::withoutGlobalScope('assigned_data')->withTrashed()->whereHas('campuses', fn ($q) => $q->whereKey($campus->id))->get()->keyBy('id');
        $names = collect();
        foreach ($teachers as $teacher) {
            // This export may include the middle name in first_name. Accept an
            // exact full given-name match as well, but never a partial/fuzzy match.
            $keys = array_unique([$this->nameKey($teacher->last_name).'|'.$this->nameKey($teacher->first_name),
                $this->nameKey($teacher->last_name).'|'.$this->nameKey($teacher->first_name.' '.$teacher->middle_name)]);
            foreach ($keys as $key) {
                $names->put($key, $names->get($key, collect())->push($teacher)->unique('id'));
            }
        }
        $identities = FacultySourceLink::where('campus_id', $campus->id)->where('source_campus_id', (string) ($options['source_campus_id'] ?? '6'))->get()->keyBy('external_id');
        $courses = Course::withoutGlobalScope('assigned_data')->whereIn('department_id', $allDepartments->modelKeys())->get();
        $sections = AcademicSection::withoutGlobalScope('assigned_data')->whereIn('department_id', $allDepartments->modelKeys())->where('semester_id', $semester)->get();
        $eligible = app(AcademicStudents::class)->students($actor, $campus)->select('users.id');
        $members = DB::table('student_section_memberships')->whereIn('academic_section_id', $sections->modelKeys())
            ->whereIn('user_id', $eligible)->get(['academic_section_id', 'user_id'])->groupBy('academic_section_id');
        $classes = CourseOffering::withoutGlobalScope('assigned_data')->where('semester_id', $semester)->whereHas('course', fn ($q) => $q->whereIn('department_id', $allDepartments->modelKeys()))->get();
        $links = TeachingClassSourceLink::where('campus_id', $campus->id)->get()->keyBy('source_key');
        $subjectOf = fn ($code, $name) => ImportedCourseCatalogue::codeKey($code).'|'.StudentAcademicRecords::code($name);
        $classSubjects = $classes->load(['course' => fn ($q) => $q->withoutGlobalScope('assigned_data')])->filter->course
            ->mapWithKeys(fn ($c) => [$c->id => $subjectOf($c->course->code, $c->course->name)]);
        $assigned = [];
        foreach ($classSubjects->keys()->chunk(500) as $chunk) {
            foreach (DB::table('course_offering_student')->whereIn('course_offering_id', $chunk)->get(['course_offering_id', 'user_id']) as $pivot) {
                $assigned[$classSubjects[$pivot->course_offering_id]][$pivot->user_id] = true;
            }
        }
        $faculty = $groups = $details = [];
        $skipped = $duplicates = 0;
        foreach ($rows as $row) {
            $issue = $row['_read_errors'] ?? [];
            $code = isset($programMatches[self::key($row['code'])])
                ? $allDepartments->firstWhere('id', $programMatches[self::key($row['code'])])?->code : $this->departmentCode($row);
            $department = $code ? $departments->get(self::key($code)) : null;
            if ($department && (($allowed !== null && ! in_array($department->id, $allowed, true)) || ($selected && ! in_array($department->id, $selected, true)))) {
                $skipped++;

                continue;
            }
            if (! $department && $selected && $code) {
                $skipped++;

                continue;
            }
            if (! $code) {
                $issue[] = 'Match this degree program to a department.';
            } elseif ($department && ! $department->is_active) {
                $issue[] = 'The teaching department is inactive.';
            } elseif (! $department && ! ($allowed === null && $actor->can('create_department'))) {
                $issue[] = 'Creating this department requires campus-wide access and create_department permission.';
            }
            if ($row['campus_id'] !== (string) ($options['source_campus_id'] ?? '6')) {
                $issue[] = 'The source campus ID does not match the selected export campus.';
            }
            foreach (['id' => 50, 'first_name' => 100, 'last_name' => 100, 'course_code' => 20, 'course_desc' => 255, 'section' => 20] as $field => $max) {
                if (blank($row[$field] ?? null) || mb_strlen($row[$field]) > $max || preg_match('/[\x00-\x1F]/', $row[$field])) {
                    $issue[] = $field.' is missing, too long, or contains control characters.';
                }
            }
            if (! is_numeric($row['units']) || ! is_finite((float) $row['units']) || (float) $row['units'] < 0 || (float) $row['units'] > 99.9
                || abs((float) $row['units'] - round((float) $row['units'], 1)) > .001) {
                $issue[] = 'Units must be from 0 to 99.9 with at most one decimal place.';
            }
            $id = $row['id'];
            $sourceName = $this->nameKey($row['last_name']).'|'.$this->nameKey($row['first_name']);
            if (! isset($faculty[$id])) {
                $identity = $identities->get($id);
                $matches = $names->get($sourceName, collect());
                $user = isset($facultyMatches[self::key($id)]) ? $teachers->get($facultyMatches[self::key($id)])
                    : ($identity ? $teachers->get($identity->user_id) : ($matches->count() === 1 ? $matches->first() : null));
                $errors = [];
                if (isset($facultyMatches[self::key($id)]) && ! $user) {
                    $errors[] = 'Match faculty to an instructor in this campus.';
                }
                if ($identity && (! $user || (int) $identity->user_id !== (int) $user->id)) {
                    $errors[] = 'The source faculty ID is already linked to a different account.';
                }
                if ($identity && $this->nameKey($identity->last_name).'|'.$this->nameKey($identity->first_name) !== $sourceName) {
                    $errors[] = 'The name for this source faculty ID changed. Verify the source identity first.';
                }
                if ($user && ($user->trashed() || $user->is_student || ! $user->is_instructor)) {
                    $errors[] = 'The matched account is deleted or is not teaching faculty.';
                }
                if (! $user && $matches->count() > 1) {
                    $errors[] = 'Several accounts match this name. Choose the correct faculty account.';
                }
                if (! $user && ! ($options['create_faculty'] ?? true)) {
                    $errors[] = 'Match the faculty account or enable creation of missing faculty profiles.';
                }
                if (! $user && ! $actor->can('create_user')) {
                    $errors[] = 'Creating missing faculty requires create_user permission.';
                }
                $faculty[$id] = ['id' => $id, 'user_id' => $user?->id, 'first_name' => $row['first_name'], 'last_name' => $row['last_name'],
                    'name_key' => $sourceName, 'errors' => $errors, 'identity_id' => $identity?->id];
            } elseif ($faculty[$id]['name_key'] !== $sourceName) {
                $faculty[$id]['errors'][] = 'The source faculty ID has conflicting faculty names in this file.';
            }
            $groupKey = implode('|', [$code ?? '?', ImportedCourseCatalogue::codeKey($row['course_code']), $id, self::key($row['section'])]);
            if (isset($groups[$groupKey])) {
                $duplicates++;
                $groups[$groupKey]['rows'][] = $row['_row'];
                $groups[$groupKey]['metadata'][] = $row;
                if (self::key($groups[$groupKey]['row']['course_desc']) !== self::key($row['course_desc']) || (float) $groups[$groupKey]['row']['units'] !== (float) $row['units']) {
                    $groups[$groupKey]['errors'][] = 'Repeated offerings disagree on subject title or units.';
                }
                $groups[$groupKey]['errors'] = array_merge($groups[$groupKey]['errors'], $issue);

                continue;
            }
            $groups[$groupKey] = ['row' => $row, 'rows' => [$row['_row']], 'metadata' => [$row], 'department_code' => $code,
                'department_id' => $department?->id, 'faculty_id' => $id, 'errors' => $issue];
        }
        $courseVariants = $classTeachers = [];
        foreach ($groups as $g) {
            // One code can name different subjects in different curricula, so the
            // title is part of the subject's identity; only units may not disagree.
            $courseKey = $g['department_code'].'|'.ImportedCourseCatalogue::codeKey($g['row']['course_code']).'|'.self::key($g['row']['course_desc']);
            $courseVariants[$courseKey][(string) (float) $g['row']['units']] = true;
            $classTeachers[$courseKey.'|'.self::key($g['row']['section'])][$g['faculty_id']] = true;
        }
        $ready = $newFaculty = $newCourses = $newSections = $newDepartments = [];
        foreach ($groups as $g) {
            $r = $g['row'];
            $f = $faculty[$g['faculty_id']];
            $errors = array_merge($g['errors'], $f['errors']);
            $courseKey = $g['department_code'].'|'.ImportedCourseCatalogue::codeKey($r['course_code']).'|'.self::key($r['course_desc']);
            if (count($courseVariants[$courseKey]) > 1) {
                $errors[] = 'This subject has different units in the selected period.';
            }
            if (count($classTeachers[$courseKey.'|'.self::key($r['section'])]) > 1) {
                $errors[] = 'Different instructors are listed for the same subject and section. Verify the assignment.';
            }
            $courseMatches = $courses->filter(fn ($c) => $g['department_id'] && (int) $c->department_id === (int) $g['department_id'] && ImportedCourseCatalogue::codeKey($c->code) === ImportedCourseCatalogue::codeKey($r['course_code']) && self::key($c->name) === self::key($r['course_desc']));
            $course = $courseMatches->count() === 1 ? $courseMatches->first() : null;
            if ($courseMatches->count() > 1) {
                $errors[] = 'Duplicate matching subjects exist. Resolve the catalogue duplicates.';
            }
            if ($course && (! $course->is_active || abs((float) $course->units - (float) $r['units']) > .001)) {
                $errors[] = 'The existing subject is inactive or has different units. It will remain unchanged.';
            }
            if (! $course && ! $actor->can('create_course')) {
                $errors[] = 'Creating missing subjects requires create_course permission.';
            }
            $sectionMatch = $sectionMatches[self::key($r['section'])] ?? null;
            $sectionOptions = $sections->filter(fn ($s) => $g['department_id'] && (int) $s->department_id === (int) $g['department_id']
                && ($sectionMatch ? (int) $s->id === $sectionMatch : self::key($s->name) === self::key($r['section'])));
            $section = $sectionOptions->count() === 1 ? $sectionOptions->first() : null;
            if ($sectionOptions->count() > 1) {
                $errors[] = 'Duplicate matching sections exist. Resolve them first.';
            }
            if ($sectionMatch && ! $section) {
                $errors[] = 'The matched section is outside this teaching department or academic period.';
            }
            if ($section && ! $section->is_active) {
                $errors[] = 'The section is inactive.';
            }
            if (! $section && ! $actor->can('manage_academic_students')) {
                $errors[] = 'Creating missing sections requires student-management permission.';
            }
            // Keyed without the title so links made before title variants still match.
            $sourceKey = hash('sha256', implode('|', [$campus->id, $options['source_campus_id'] ?? '6', $semester, $g['department_code'].'|'.ImportedCourseCatalogue::codeKey($r['course_code']), $g['faculty_id'], self::key($r['section'])]));
            $linked = $links->get($sourceKey);
            $candidates = $classes->filter(function ($c) use ($course, $f, $r, $section) {
                if (! $course || ! $f['user_id'] || (int) $c->course_id !== (int) $course->id || (int) $c->user_id !== (int) $f['user_id']) {
                    return false;
                }
                if (self::key($c->section) === self::key($r['section'])) {
                    return true;
                }

                return $section && collect($c->evaluator_selection['sections'] ?? [])->contains(fn ($key) => self::key(rawurldecode(explode('~', $key, 3)[2] ?? '')) === self::key($section->name));
            });
            $existing = $linked ? $classes->firstWhere('id', $linked->course_offering_id) : ($candidates->count() === 1 ? $candidates->first() : null);
            if ($linked && (! $existing || ! $course || ! $f['user_id'] || (int) $existing->course_id !== (int) $course->id || (int) $existing->user_id !== (int) $f['user_id']
                || (int) ($existing->teaching_department_id ?? $course->department_id) !== (int) $g['department_id'])) {
                $errors[] = 'This imported class changed after linking. Review the existing class first.';
            }
            if (! $linked && $candidates->count() > 1) {
                $errors[] = 'Multiple existing classes match this offering.';
            }
            $ids = $section ? ($members->get($section->id, collect())->pluck('user_id')->unique()->sort()->values()->all()) : [];
            $elsewhere = [];
            if (! $existing) {
                // Section members already evaluating this subject in another class keep
                // that class; adding them here would give them a second evaluation.
                $subject = $subjectOf($r['course_code'], $r['course_desc']);
                $taken = $assigned[$subject] ?? [];
                $elsewhere = array_values(array_filter($ids, fn ($id) => isset($taken[$id])));
                $ids = array_values(array_diff($ids, $elsewhere));
                // Students listed in two sections join only the first new class.
                $assigned[$subject] = $taken + array_fill_keys($ids, true);
            }
            $item = [...$g, 'faculty' => $f, 'course_id' => $course?->id, 'section_id' => $section?->id, 'section_name' => $section?->name ?? self::key($r['section']),
                'class_id' => $existing?->id, 'source_key' => $sourceKey, 'student_ids' => $ids, 'class_state' => $existing?->only(['id', 'course_id', 'user_id', 'section', 'evaluation_cycle_id', 'form_id', 'evaluator_selection', 'is_active', 'updated_at']),
                'errors' => array_values(array_unique($errors))];
            $details[] = ['rows' => implode(', ', array_map(fn ($source) => ($source['_sheet'] ?? 'Rows').':'.$source['_row'], $g['metadata'])), 'subject' => $r['course_code'].' - '.$r['course_desc'], 'faculty' => $r['last_name'].', '.$r['first_name'],
                'source_id' => $r['id'], 'department' => $g['department_code'] ?? '?', 'section' => $item['section_name'], 'students' => count($ids), 'already_in_another_class' => count($elsewhere),
                'status' => $errors ? 'Needs attention' : ($existing ? 'Already exists' : 'Ready'),
                'messages' => [...$item['errors'], ...($elsewhere ? [count($elsewhere).' section students already evaluate this subject in another class and stay there. Move them under Section students if that class is wrong.'] : [])]];
            if ($errors) {
                continue;
            }
            $ready[] = $item;
            if (! $f['user_id']) {
                $newFaculty[$g['faculty_id']] = $f;
            }
            if (! $course && ! $existing) {
                $newCourses[$courseKey] = true;
            }
            if (! $section && ! $existing) {
                $newSections[$g['department_code'].'|'.$item['section_name']] = true;
            }
            if (! $g['department_id']) {
                $newDepartments[$g['department_code']] = true;
            }
        }
        $review = ['selected_rows' => count($rows), 'skipped_rows' => $skipped, 'duplicate_rows' => $duplicates,
            'ready_classes' => count($ready), 'new_classes' => count(array_filter($ready, fn ($i) => ! $i['class_id'])),
            'existing_classes' => count(array_filter($ready, fn ($i) => (bool) $i['class_id'])), 'blocked_classes' => count($details) - count($ready),
            'new_faculty' => count($newFaculty), 'new_courses' => count($newCourses), 'new_sections' => count($newSections), 'new_departments' => count($newDepartments),
            'empty_classes' => count(array_filter($ready, fn ($i) => ! $i['class_id'] && ! $i['student_ids'])),
            'students_in_other_classes' => array_sum(array_column($details, 'already_in_another_class')),
            'faculty_profiles' => array_values($newFaculty), 'details' => $details, 'error_count' => $ready ? 0 : 1];

        return ['ready' => $ready, 'review' => $review, 'state_hash' => hash('sha256', json_encode([$allowed, $options, $ready, $details, $review]))];
    }

    public function commit(User $actor, Campus $campus, array $plan): array
    {
        abort_unless($plan['actor_id'] === $actor->id && $plan['campus_id'] === $campus->id, 403);

        return DB::transaction(function () use ($actor, $campus, $plan) {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('campuses')->where('id', $campus->id)->update(['id' => $campus->id]);
            } else {
                Campus::whereKey($campus->id)->lockForUpdate()->firstOrFail();
            }
            $actor = User::withoutGlobalScope('assigned_data')->findOrFail($actor->id);
            $analysis = $this->analyze($actor, $campus, $plan['options'], $plan['rows']);
            if (! hash_equals($plan['state_hash'], $analysis['state_hash']) || ! $analysis['ready']) {
                throw ValidationException::withMessages(['file' => 'Records or permissions changed after review. Upload and review the offering file again.']);
            }
            $faculty = $courses = $sections = $departments = [];
            $created = $profiles = $rosters = 0;
            foreach ($analysis['ready'] as $item) {
                $r = $item['row'];
                $code = $item['department_code'];
                $department = $departments[$code] ??= ($item['department_id'] ? Department::findOrFail($item['department_id'])
                    : Department::firstOrCreate(['campus_id' => $campus->id, 'code' => $code], ['name' => $code, 'is_active' => true]));
                $f = $item['faculty'];
                if (! isset($faculty[$f['id']])) {
                    $user = $f['user_id'] ? User::withoutGlobalScope('assigned_data')->findOrFail($f['user_id'])
                        : User::create(['first_name' => $f['first_name'], 'last_name' => $f['last_name'], 'email' => null,
                            'is_student' => false, 'is_instructor' => true, 'instructor_department_id' => null,
                            'password' => Str::random(40), 'must_change_password' => true]);
                    if (! $f['user_id']) {
                        $user->campuses()->attach($campus->id);
                        $user->assignRole(Role::findOrCreate('instructor', 'web'));
                        $profiles++;
                    }
                    FacultySourceLink::firstOrCreate(['campus_id' => $campus->id, 'source_campus_id' => (string) ($plan['options']['source_campus_id'] ?? '6'), 'external_id' => $f['id']],
                        ['user_id' => $user->id, 'first_name' => $f['first_name'], 'last_name' => $f['last_name']]);
                    $faculty[$f['id']] = $user;
                }
                if ($item['class_id']) {
                    $class = CourseOffering::withoutGlobalScope('assigned_data')->findOrFail($item['class_id']);
                } else {
                    $courseKey = $code.'|'.ImportedCourseCatalogue::codeKey($r['course_code']).'|'.self::key($r['course_desc']);
                    $course = $courses[$courseKey] ??= ($item['course_id'] ? Course::findOrFail($item['course_id'])
                        : Course::create(['department_id' => $department->id, 'code' => $r['course_code'], 'name' => $r['course_desc'], 'units' => $r['units'], 'is_active' => true]));
                    $sectionKey = $code.'|'.$item['section_name'];
                    $section = $sections[$sectionKey] ??= ($item['section_id'] ? AcademicSection::findOrFail($item['section_id'])
                        : AcademicSection::create(['department_id' => $department->id, 'semester_id' => $plan['options']['semester_id'], 'name' => $item['section_name'], 'is_active' => true]));
                    $class = CourseOffering::create(['semester_id' => $plan['options']['semester_id'], 'course_id' => $course->id,
                        'user_id' => $faculty[$f['id']]->id, 'teaching_department_id' => $department->id, 'section' => $item['section_name'], 'is_active' => true,
                        'evaluator_selection' => ['source' => 'sections_v1', 'sections' => [$department->id.'~'.$plan['options']['semester_id'].'~'.rawurlencode($section->name)],
                            'auto_section_roster' => (bool) ($plan['options']['sync_section_rosters'] ?? true),
                            'auto_enrolment' => (bool) ($plan['options']['auto_assign_enrolments'] ?? true),
                            'include' => [], 'exclude' => [], 'mode' => 'replace', 'count' => count($item['student_ids']), 'saved_at' => now()->toIso8601String()]]);
                    foreach (array_chunk($item['student_ids'], 400) as $ids) {
                        $class->students()->attach(collect($ids)->mapWithKeys(fn ($id) => [$id => ['enrolled_at' => now()]])->all());
                    }
                    $rosters += count($item['student_ids']);
                    $created++;
                }
                TeachingClassSourceLink::firstOrCreate(['source_key' => $item['source_key']], ['campus_id' => $campus->id, 'course_offering_id' => $class->id,
                    'metadata' => ['source' => 'bisu_subject_offerings', 'source_campus_id' => $plan['options']['source_campus_id'] ?? '6', 'rows' => $item['metadata']]]);
            }
            ClassroomUpdates::panel('setup');
            $matching = app(AutomaticEvaluatorAssignments::class)->synchronize($actor, $campus, (int) $plan['options']['semester_id']);

            return ['new_classes' => $created, 'new_faculty' => $profiles, 'roster_memberships' => $rosters,
                'automatic_evaluators' => $matching['assigned'], 'unresolved_evaluators' => $matching['unresolved'],
                'message' => $created.' teaching classes created; '.$profiles.' faculty profiles prepared; '.$rosters.' saved-section students assigned. '
                    .$matching['assigned'].' additional enrolled students assigned automatically; '.$matching['unresolved'].' enrolled subject matches need a teacher/section choice. Choose supervisors and open an evaluation when ready.'];
        });
    }

    /** Follow confirmed section uploads only while an imported class is still untouched setup data. */
    public function syncSection(User $actor, Campus $campus, AcademicSection $section): int
    {
        if (! $this->allowed($actor, $campus)) {
            return 0;
        }
        $expected = $section->department_id.'~'.$section->semester_id.'~'.rawurlencode($section->name);
        $classes = app(AssignedDataAccess::class)->apply(CourseOffering::query(), $actor, $campus)
            ->where('semester_id', $section->semester_id)->where('teaching_department_id', $section->department_id)
            ->whereNull('form_id')->whereNull('evaluation_cycle_id')->where('is_active', true)
            ->whereIn('id', TeachingClassSourceLink::where('campus_id', $campus->id)->select('course_offering_id'))
            ->whereDoesntHave('formSubmissions', fn ($q) => $q->withoutGlobalScope('assigned_data'))
            ->whereDoesntHave('ratings', fn ($q) => $q->withoutGlobalScope('assigned_data'))
            ->whereDoesntHave('quizzes', fn ($q) => $q->withoutGlobalScope('assigned_data'))->get();
        $updated = 0;
        foreach ($classes as $class) {
            $selection = $class->evaluator_selection ?? [];
            if (! empty($selection['auto_enrolment']) || ($selection['source'] ?? '') === 'auto_enrolment_v1') {
                continue;
            }
            if (empty($selection['auto_section_roster']) || ! empty($selection['include']) || ! empty($selection['exclude'])
                || array_values($selection['sections'] ?? []) !== [$expected]) {
                continue;
            }
            // Reuse the normal roster authorization/validation. Manual assignment
            // replaces this selection and intentionally drops the automatic flag.
            app(AcademicStudents::class)->assignSections($actor, $class, [$expected]);
            $class->refresh();
            $class->update(['evaluator_selection' => [...$class->evaluator_selection, 'auto_section_roster' => true]]);
            $updated++;
        }

        $matching = app(AutomaticEvaluatorAssignments::class)->synchronize($actor, $campus, (int) $section->semester_id);

        return $updated + $matching['classes'];
    }
}
