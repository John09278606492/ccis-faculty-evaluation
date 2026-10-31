<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AcademicStudentImports
{
    public function authorize(User $actor, Campus $campus): User
    {
        $actor = User::withoutGlobalScope('assigned_data')->findOrFail($actor->id);
        $actor->loadMissing('roles.permissions', 'permissions');
        abort_unless(! $actor->isStudent() && $actor->canAccessTenant($campus)
            && $actor->can('view_academic_students') && $actor->can('manage_academic_students') && $actor->can('import_academic_students'), 403);

        return $actor;
    }

    public function reviewUpload(User $actor, Campus $campus, mixed $file, int $semesterId, ?string $previousToken = null, array $departmentCodes = []): array
    {
        $actor = $this->authorize($actor, $campus);
        AcademicPeriod::validate($campus, $semesterId);
        if (is_array($file)) {
            $file = reset($file) ?: null;
        }
        if (! $file && $previousToken) {
            $previous = $this->plan($actor, $campus, $previousToken);

            $sources = $previous['context']['sources'] ?? [];
            $semesterId = app(CampusMasterListReader::class)->period($campus, $sources, $semesterId);

            return $this->review($actor, $campus, $previous['rows'], $semesterId, [...$previous['context'], 'sources' => $sources, 'department_codes' => $departmentCodes]);
        }
        if ($file instanceof UploadedFile) {
            $path = $file->getRealPath();
            abort_unless($file->getSize() <= 5 * 1024 * 1024, 422);
        } elseif (is_string($file)) {
            abort_unless((str_starts_with($file, 'academic-imports/') || preg_match('#^background-tasks/[a-f0-9-]{36}/source\.(csv|xlsx|xls)$#i', $file)) && ! str_contains($file, '..') && ! str_contains($file, '\\'), 403);
            $path = Storage::disk('local')->path($file);
        } else {
            throw ValidationException::withMessages(['file' => 'Upload a CSV or Excel file before reviewing.']);
        }
        try {
            $parsed = app(CampusMasterListReader::class)->read($path);
            $parsed = app(CampusMasterListReader::class)->selectPeriod($campus, $parsed, $semesterId);
            $semesterId = app(CampusMasterListReader::class)->period($campus, $parsed['sources'], $semesterId);

            return $this->review($actor, $campus, $parsed['rows'], $semesterId, ['sources' => $parsed['sources'], 'ignored_sheets' => $parsed['ignored_sheets'] ?? [],
                'skipped_period_rows' => $parsed['skipped_period_rows'] ?? 0, 'department_codes' => $departmentCodes]);
        } catch (ValidationException|HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['file' => 'Could not read this file. Use the example CSV or a valid Excel workbook.']);
        } finally {
            if ($file instanceof TemporaryUploadedFile) {
                $file->delete();
            } elseif (is_string($file)) {
                Storage::disk('local')->delete($file);
            }
        }
    }

    public function review(User $actor, Campus $campus, array $rows, int $semesterId, array $context = []): array
    {
        $actor = $this->authorize($actor, $campus);
        $semesterId = AcademicPeriod::validate($campus, $semesterId);
        $selected = $this->selectedRows($actor, $campus, $rows, $context);
        $analysis = [...$this->analyze($actor, $campus, $selected, $semesterId), 'sources' => $context['sources'] ?? [],
            'semester_id' => $semesterId, 'ignored_sheets' => $context['ignored_sheets'] ?? [], 'skipped_period_rows' => $context['skipped_period_rows'] ?? 0,
            'skipped_department_rows' => count($rows) - count($selected)];
        $token = (string) Str::uuid();
        Cache::put('academic-student-import:'.$token, [
            'actor_id' => $actor->id, 'campus_id' => $campus->id, 'semester_id' => $semesterId,
            'rows' => $rows, 'analysis' => $analysis, 'context' => $context,
        ], now()->addMinutes(30));

        return ['token' => $token, ...$this->summary($analysis)];
    }

    private function plan(User $actor, Campus $campus, string $token): array
    {
        $plan = Cache::get('academic-student-import:'.$token);
        abort_unless($plan && (int) $plan['actor_id'] === $actor->id && (int) $plan['campus_id'] === $campus->id, 403, 'This import review expired or belongs to another user. Upload and review again.');

        return $plan;
    }

    public function freezeReview(User $actor, Campus $campus, string $token, int $semesterId): array
    {
        $this->getReview($actor, $campus, $token, $semesterId);

        return $this->plan($actor, $campus, $token);
    }

    public function commitFrozen(User $actor, Campus $campus, array $plan): int
    {
        abort_unless($plan['actor_id'] === $actor->id && $plan['campus_id'] === $campus->id, 403);
        $token = (string) Str::uuid();
        Cache::put('academic-student-import:'.$token, $plan, now()->addHour());
        try {
            return $this->commit($actor, $campus, $token, $plan['semester_id']);
        } finally {
            Cache::forget('academic-student-import:'.$token);
        }
    }

    public function getReview(User $actor, Campus $campus, string $token, int $semesterId): array
    {
        $actor = $this->authorize($actor, $campus);
        AcademicPeriod::validate($campus, $semesterId);
        $plan = $this->plan($actor, $campus, $token);
        abort_unless((int) $plan['semester_id'] === $semesterId, 403, 'The academic period changed. Review the file again.');

        return $this->summary($plan['analysis']);
    }

    private function summary(array $analysis): array
    {
        unset($analysis['state_hash'], $analysis['catalogue_state'], $analysis['department_state']);

        return $analysis;
    }

    private function selectedRows(User $actor, Campus $campus, array $rows, array $context): array
    {
        $selected = array_map(fn ($code) => ImportedDepartments::code((string) $code), $context['department_codes'] ?? []);
        if ($selected === []) {
            return $rows;
        }
        $allowed = app(AcademicStudents::class)->departments($actor, $campus)->pluck('code')->map(fn ($code) => ImportedDepartments::code($code))->all();
        abort_unless(array_diff($selected, $allowed) === [], 403);
        $known = Department::withoutGlobalScope('assigned_data')->where('campus_id', $campus->id)->pluck('code')->map(fn ($code) => ImportedDepartments::code($code))->all();

        // New codes remain in the review; only explicitly unselected existing departments are skipped.
        return array_values(array_filter($rows, fn ($row) => in_array(ImportedDepartments::code((string) ($row['department_code'] ?? '')), $selected, true)
            || ! in_array(ImportedDepartments::code((string) ($row['department_code'] ?? '')), $known, true)));
    }

    public function commit(User $actor, Campus $campus, string $token, int $semesterId): int
    {
        $actor = $this->authorize($actor, $campus);
        AcademicPeriod::validate($campus, $semesterId);

        return Cache::lock('academic-student-import-lock:'.$token, 300)->block(5, function () use ($actor, $campus, $token, $semesterId) {
            $actor = $this->authorize($actor, $campus);
            $plan = $this->plan($actor, $campus, $token);
            abort_unless((int) $plan['semester_id'] === $semesterId, 403, 'The academic period changed. Review the file again.');
            if ($plan['analysis']['error_count'] > 0) {
                throw ValidationException::withMessages(['review_token' => 'Fix the listed row errors and upload a corrected file. No students were changed.']);
            }
            abort_unless(app(CampusMasterListReader::class)->period($campus, $plan['context']['sources'] ?? [], $semesterId) === $semesterId, 403);
            $count = DB::transaction(function () use ($actor, $campus, $plan, $semesterId) {
                if (DB::getDriverName() === 'sqlite') {
                    DB::table('campuses')->where('id', $campus->id)->update(['id' => $campus->id]);
                } else {
                    Campus::whereKey($campus->id)->lockForUpdate()->firstOrFail();
                }
                $rows = $this->selectedRows($actor, $campus, $plan['rows'], $plan['context'] ?? []);
                $ids = collect($rows)->pluck('student_id')->map(fn ($id) => trim((string) $id))->filter()->unique();
                foreach ($ids->chunk(500) as $chunk) {
                    User::withoutGlobalScope('assigned_data')->whereIn('student_id', $chunk)->lockForUpdate()->get();
                }
                $current = $this->analyze($actor, $campus, $rows, $semesterId);
                if ($current['error_count'] > 0 || $current['state_hash'] !== $plan['analysis']['state_hash']) {
                    throw ValidationException::withMessages(['review_token' => 'Records or access changed after review. Review the file again before importing. No students were changed.']);
                }

                return app(AcademicStudents::class)->import($actor, $campus, $rows, $semesterId);
            });
            Cache::forget('academic-student-import:'.$token);

            return $count;
        });
    }

    private function analyze(User $actor, Campus $campus, array $rows, int $semesterId): array
    {
        if (count($rows) > 5000) {
            throw ValidationException::withMessages(['file' => 'Import up to 5,000 rows per file.']);
        }
        $service = app(AcademicStudents::class);
        $departmentPlan = app(ImportedDepartments::class)->review($actor, $campus, $rows);
        $departments = app(ImportedDepartments::class)->available($actor, $campus, $departmentPlan);
        $records = collect();
        $ids = collect($rows)->pluck('student_id')->map(fn ($id) => trim((string) $id))->filter()->unique();
        foreach ($ids->chunk(500) as $chunk) {
            $records = $records->merge(User::withoutGlobalScope('assigned_data')->withTrashed()->whereIn('student_id', $chunk)->with([
                'roles.permissions', 'permissions',
                'studentSectionMemberships' => fn ($q) => $q->where('semester_id', $semesterId)->orderBy('id'),
                'academicRecords' => fn ($q) => $q->where('semester_id', $semesterId),
                'subjectEnrolments' => fn ($q) => $q->where('semester_id', $semesterId)->orderBy('subject_key'),
            ])->get());
        }
        $allowedIds = [];
        foreach ($records->pluck('id')->chunk(500) as $chunk) {
            foreach ($service->students($actor, $campus)->whereKey($chunk)->pluck('users.id') as $id) {
                $allowedIds[$id] = true;
            }
        }
        $records = $records->keyBy('student_id');
        $errors = $seen = $emails = $state = $new = $updated = $cohorts = $subjects = $variants = [];
        $withoutSubjects = 0;
        $count = $duplicates = 0;
        foreach ($rows as $index => $row) {
            if (collect($row)->every(fn ($value) => blank($value))) {
                continue;
            }
            $count++;
            $id = trim((string) ($row['student_id'] ?? ''));
            $existing = $records->get($id);
            $departmentCode = ImportedDepartments::code((string) ($row['department_code'] ?? ''));
            $department = $departments->get($departmentCode);
            $messages = $row['_read_errors'] ?? [];
            if (! $department) {
                $messages[] = $departmentPlan['department_errors'][$departmentCode] ?? 'Department code is missing or outside your assigned departments.';
            }
            if ($existing && ! isset($allowedIds[$existing->id])) {
                $messages[] = 'This ID belongs to a deleted account or an account outside your access. Contact an authorized student manager; imports cannot reactivate deleted accounts.';
            } elseif ($existing && ! $service->isSafeStudentTarget($existing)) {
                $messages[] = 'This account has a staff or privileged profile. Correct its profile and roles in User Management first.';
            }
            $sections = $row['sections'] ?? ($row['section'] ?? '');
            $sections = is_array($sections) ? $sections : explode('|', (string) $sections);
            $sections = array_values(array_filter(array_map(fn ($section) => is_string($section) ? strtoupper(preg_replace('/\s+/', ' ', trim($section))) : $section, $sections), fn ($section) => $section !== ''));
            $data = [...$row, 'student_id' => $id, 'sections' => $sections];
            $validator = Validator::make($data, [
                // All existing IDs were fetched above, including inaccessible and staff accounts.
                'student_id' => ['required', 'string', 'max:50'],
                'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
                'middle_name' => ['nullable', 'string', 'max:100'],
                'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($existing?->id)],
                'sections' => ['array'], 'sections.*' => ['required', 'string', 'max:50'],
            ]);
            $messages = array_merge($messages, $validator->errors()->all());
            $messages = array_merge($messages, app(StudentAcademicRecords::class)->errors($row));
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email !== '' && isset($emails[$email]) && $emails[$email] !== $id) {
                $messages[] = 'This email is used by another student ID in the file.';
            }
            if ($email !== '') {
                $emails[$email] = $id;
            }
            $identity = [mb_strtolower(trim((string) ($row['first_name'] ?? ''))), mb_strtolower(trim((string) ($row['middle_name'] ?? ''))), mb_strtolower(trim((string) ($row['last_name'] ?? ''))), $email, $departmentCode,
                $row['program'] ?? null, $row['reported_year_level'] ?? null, $row['total_units'] ?? null, $row['reported_sex'] ?? null];
            if (isset($row['subjects'])) {
                $signature = [];
                foreach ($row['subjects'] as $subject) {
                    $key = StudentAcademicRecords::subjectKey((string) ($subject['subject_code'] ?? ''), (string) ($subject['description'] ?? ''));
                    $signature[$key] = [$subject['units'] ?? null, $subject['lecture'] ?? null, $subject['laboratory'] ?? null];
                    $subjects[$id.'|'.$key] = true;
                    $variants[$subject['subject_code'] ?? ''][$key] = true;
                }
                ksort($signature);
                $identity[] = $signature;
                if ($row['subjects'] === []) {
                    $withoutSubjects++;
                }
            }
            if (isset($seen[$id])) {
                $duplicates++;
                if ($seen[$id] !== $identity) {
                    $messages[] = 'Repeated student ID has conflicting name, email, department or academic details.';
                }
            }
            $seen[$id] = $identity;
            if ($messages !== []) {
                $errors[] = ['row' => $row['_source_row'] ?? $index + 2, 'sheet' => $row['_source_sheet'] ?? null, 'messages' => array_values(array_unique($messages))];
            } elseif ($existing) {
                $updated[$id] = true;
            } else {
                $new[$id] = true;
            }
            $cohort = trim(($row['department_code'] ?? '').' / '.($row['program'] ?? 'No program reported'));
            $cohorts[$cohort][$id] = true;
            $state[$id] = $existing ? [$existing->id, $existing->first_name, $existing->middle_name, $existing->last_name, $existing->email, $existing->student_department_id, $existing->updated_at?->toISOString(), $existing->studentSectionMemberships->pluck('section')->all(),
                $existing->academicRecords->toArray(), $existing->subjectEnrolments->toArray()] : null;
        }
        if ($count === 0) {
            $errors[] = ['row' => 2, 'messages' => ['The file contains no student rows.']];
        }

        $catalogue = app(ImportedCourseCatalogue::class)->review($actor, $campus, $rows, $departments);

        return [...$departmentPlan, ...$catalogue, 'total_rows' => $count, 'new_students' => count($new), 'updated_students' => count($updated), 'duplicate_rows' => $duplicates,
            'cohorts' => array_map('count', $cohorts), 'subject_enrolments' => count($subjects), 'subject_codes' => count($variants),
            'ambiguous_subject_codes' => count(array_filter($variants, fn ($titles) => count($titles) > 1)), 'without_subjects' => $withoutSubjects,
            'error_count' => count($errors), 'errors' => $errors, 'state_hash' => hash('sha256', json_encode([$state, $catalogue['catalogue_state'], $departmentPlan['department_state']]))];
    }
}
