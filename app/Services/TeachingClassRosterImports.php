<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\StudentSectionMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

class TeachingClassRosterImports
{
    public function offering(User $actor, Campus $campus, int $id): CourseOffering
    {
        $offering = app(EvaluatorFileImport::class)->offering($actor, $campus, $id);
        abort_if($offering->evaluationForm?->isSupervisorInstrument(), 403);

        return $offering;
    }

    public static function section(string $value): string
    {
        $value = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $value)));
        $value = preg_replace('/\s*[-–—]\s*/u', ' ', $value);

        return preg_replace('/(\d)\s+([A-Z])\b/u', '$1$2', $value);
    }

    private function name(?string $value): string
    {
        $value = str_replace(['’', '‘'], "'", (string) $value);
        $value = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $value)));

        return in_array($value, ['-', 'N/A', 'NONE'], true) ? '' : $value;
    }

    private function sex(?string $value): string
    {
        return match (mb_strtoupper(trim((string) $value))) {
            'M', 'MALE' => 'M', 'F', 'FEMALE' => 'F', default => mb_strtoupper(trim((string) $value))
        };
    }

    private function header(array $row): array
    {
        $aliases = ['student_id' => ['studentid', 'idno', 'idnumber', 'studid'],
            'last_name' => ['lastname', 'surname', 'familyname'], 'first_name' => ['firstname', 'givenname'],
            'middle_name' => ['middlename'], 'sex' => ['sex', 'gender'], 'section' => ['section', 'yearsection', 'classsection']];
        $header = [];
        foreach ($row as $column => $value) {
            $key = preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $value));
            foreach ($aliases as $field => $names) {
                if (in_array($key, $names, true)) {
                    if (isset($header[$field])) {
                        throw ValidationException::withMessages(['file' => 'The roster has duplicate '.$field.' columns. Keep one column for each field.']);
                    }
                    $header[$field] = $column;
                }
            }
        }

        return $header;
    }

    /** Section uploads may list ID No. only; the masterlist supplies the names shown for review. */
    public function read(string $source, string $defaultSection = '', bool $allowIdOnly = false): array
    {
        abort_unless((bool) preg_match('#^background-tasks/[a-f0-9-]{36}/source\.(csv|xlsx|xls)$#i', $source), 403);
        $path = Storage::disk('local')->path($source);
        $rows = [];
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
            $sample = file_get_contents($path, false, null, 0, 8192);
            $delimiter = ',';
            $scores = [];
            foreach ([',', ';', "\t"] as $candidate) {
                $scores[$candidate] = max(array_map(fn ($line) => count(str_getcsv($line, $candidate, '"', '')), preg_split('/\r\n|\n|\r/', $sample)));
            }
            arsort($scores);
            $delimiter = array_key_first($scores);
            $handle = fopen($path, 'rb');
            try {
                while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                    $rows[] = array_map(function ($value) {
                        $value = (string) $value;

                        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
                    }, $row);
                    if (count($rows) > 5011) {
                        throw ValidationException::withMessages(['file' => 'Upload up to 5,000 student rows per file.']);
                    }
                }
            } finally {
                fclose($handle);
            }
        } else {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
            try {
                $sheet = $book->getActiveSheet();
                if ($sheet->getHighestDataRow() > 5011 || Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > 26) {
                    throw ValidationException::withMessages(['file' => 'Upload up to 5,000 student rows and use the supplied roster columns.']);
                }
                $rows = $sheet->toArray(null, false, true, false);
            } finally {
                $book->disconnectWorksheets();
            }
        }
        $header = null;
        $result = [];
        $section = self::section($defaultSection);
        foreach ($rows as $index => $row) {
            $row = array_map(fn ($value) => trim(str_replace("\xEF\xBB\xBF", '', (string) $value)), $row);
            if (collect($row)->every(fn ($value) => $value === '')) {
                continue;
            }
            if ($header === null) {
                $candidate = $this->header($row);
                if (isset($candidate['student_id'])) {
                    $missingNames = array_diff(['first_name', 'last_name', 'middle_name'], array_keys($candidate));
                    if ($missingNames && ! ($allowIdOnly && count($missingNames) === 3)) {
                        throw ValidationException::withMessages(['file' => 'Use ID No., Lastname, Firstname and Middlename columns. Download the Section Roster CSV example.']);
                    }
                    $header = $candidate;
                } elseif ($index < 10 && count(array_filter($row, fn ($value) => $value !== '')) === 1) {
                    $section = self::section(collect($row)->first(fn ($value) => $value !== ''));
                } else {
                    throw ValidationException::withMessages(['file' => 'The header row could not be identified. Use the example CSV, optionally preceded by a single section title such as BSIT - 3A.']);
                }

                continue;
            }
            $record = ['row' => $index + 1];
            foreach (['student_id', 'first_name', 'last_name', 'middle_name', 'sex', 'section'] as $field) {
                $record[$field] = isset($header[$field]) ? ($row[$header[$field]] ?? '') : '';
            }
            $record['student_id'] = mb_strtoupper($record['student_id']);
            $record['check_names'] = isset($header['last_name']);
            $record['section'] = self::section($record['section'] ?: $section);
            $result[] = $record;
        }
        if (! $header || $result === [] || count($result) > 5000) {
            throw ValidationException::withMessages(['file' => 'The roster must contain its header and between 1 and 5,000 students.']);
        }

        return $result;
    }

    private function classState(CourseOffering $offering): string
    {
        return hash('sha256', json_encode($offering->only(['id', 'semester_id', 'course_id', 'user_id', 'teaching_department_id', 'section'])));
    }

    public function review(User $actor, Campus $campus, CourseOffering $offering, array $rows): array
    {
        $offering = $this->offering($actor, $campus, $offering->id);
        $analysis = $this->analyzeRows($actor, $campus, $offering->semester_id, $rows);
        $analysis['review'] = [...$analysis['review'], 'target' => 'class', 'class_label' => $offering->section,
            'course' => $offering->course?->code, 'faculty' => $offering->evaluatee?->name];

        return [...$analysis, 'class_state' => $this->classState($offering)];
    }

    public function analyzeRows(User $actor, Campus $campus, int $semesterId, array $rows, ?int $departmentId = null, ?string $sectionName = null): array
    {
        AcademicPeriod::validate($campus, $semesterId);
        $students = collect();
        foreach (collect($rows)->pluck('student_id')->filter()->unique()->chunk(400) as $ids) {
            $students = $students->merge(app(AcademicStudents::class)->students($actor, $campus)->whereIn('student_id', $ids)->with([
                'roles.permissions', 'permissions', 'academicRecords' => fn ($q) => $q->where('semester_id', $semesterId),
                'studentSectionMemberships' => fn ($q) => $q->where('semester_id', $semesterId),
            ])->get());
        }
        $students = $students->keyBy(fn ($student) => mb_strtoupper($student->student_id));
        $details = $matches = $state = $sections = [];
        $duplicateRows = $errorCount = 0;
        $seen = [];
        foreach ($rows as $row) {
            $messages = $warnings = [];
            $id = $row['student_id'];
            $student = $students->get($id);
            if ($id === '' || mb_strlen($id) > 50) {
                $messages[] = 'ID No. is required and must contain at most 50 characters.';
            }
            if ($row['section'] === '' || mb_strlen($row['section']) > 50 || str_contains($row['section'], '|')) {
                $messages[] = 'Provide one section per row, for example BSIT 3A.';
            }
            if ($sectionName !== null && self::section($row['section']) !== self::section($sectionName)) {
                $messages[] = 'This row belongs to another section. Upload only students for the selected section.';
            }
            if (! $student || ! $student->student_department_id || ! app(AcademicStudents::class)->isSafeStudentTarget($student)) {
                $messages[] = 'Student was not found in the masterlist within your authorized campus and departments.';
            } else {
                if ($departmentId !== null && $student->student_department_id !== $departmentId) {
                    $messages[] = 'This student belongs to a different department than the selected section.';
                }
                foreach (($row['check_names'] ?? true) ? ['last_name' => 'Lastname', 'first_name' => 'Firstname', 'middle_name' => 'Middlename'] : [] as $field => $label) {
                    if ($this->name($row[$field]) !== $this->name($student->$field) || ($field !== 'middle_name' && $this->name($row[$field]) === '')) {
                        $messages[] = $label.' does not match the masterlist for this ID. Names are not changed by this upload.';
                    }
                }
                $academic = $student->academicRecords->first();
                if (! $academic && $student->studentSectionMemberships->isEmpty()) {
                    $messages[] = 'This student is not in the masterlist for this class academic period.';
                }
                if ($row['sex'] !== '' && ! in_array($this->sex($row['sex']), ['M', 'F'], true)) {
                    $messages[] = 'Use M/Male or F/Female for Sex, or leave it blank.';
                } elseif ($row['sex'] !== '' && filled($academic?->reported_sex) && $this->sex($row['sex']) !== $this->sex($academic->reported_sex)) {
                    $messages[] = 'Sex does not match the masterlist for this academic period.';
                } elseif ($row['sex'] !== '' && blank($academic?->reported_sex)) {
                    $warnings[] = 'Sex is not recorded in this period masterlist; it will not be changed.';
                }
                $state[$id] = [$student->id, $student->first_name, $student->last_name, $student->middle_name, $student->student_department_id,
                    $academic?->id, $academic?->reported_sex, (bool) ($academic || $student->studentSectionMemberships->isNotEmpty())];
            }
            $key = $id.'|'.$row['section'];
            if (isset($seen[$key])) {
                $duplicateRows++;
                $warnings[] = 'Repeated ID and section; the student will be assigned once.';
            }
            $seen[$key] = true;
            if ($messages === []) {
                $matches[$student->id]['sections'][$row['section']] = true;
                $sections[$row['section']][$student->id] = true;
            } else {
                $errorCount++;
            }
            if (! ($row['check_names'] ?? true) && $student && $messages === []) {
                $row = [...$row, 'last_name' => $student->last_name, 'first_name' => $student->first_name, 'middle_name' => (string) $student->middle_name];
            }
            $details[] = [...$row, 'messages' => $messages, 'warnings' => $warnings];
        }
        ksort($state);
        $review = ['total_rows' => count($rows), 'matched_students' => count($matches), 'error_count' => $errorCount,
            'duplicate_rows' => $duplicateRows, 'sections' => array_map('count', $sections), 'details' => $details,
            'academic_period' => AcademicPeriod::options($campus)[$semesterId] ?? ''];

        return ['review' => $review, 'matches' => $matches, 'state_hash' => hash('sha256', json_encode($state))];
    }

    public function prepare(User $actor, Campus $campus, int $id, string $source): array
    {
        $offering = $this->offering($actor, $campus, $id);
        $rows = $this->read($source, $offering->section ?? '');
        $analysis = $this->review($actor, $campus, $offering, $rows);

        return ['review' => $analysis['review'], 'plan' => ['actor_id' => $actor->id, 'campus_id' => $campus->id, 'offering_id' => $id,
            'rows' => $rows, 'state_hash' => $analysis['state_hash'], 'class_state' => $analysis['class_state']]];
    }

    public function commit(User $actor, Campus $campus, array $plan): array
    {
        abort_unless($plan['actor_id'] === $actor->id && $plan['campus_id'] === $campus->id, 403);

        return DB::transaction(function () use ($actor, $campus, $plan) {
            $offering = $this->offering($actor, $campus, $plan['offering_id']);
            if (DB::getDriverName() === 'sqlite') {
                DB::table('course_offerings')->where('id', $offering->id)->update(['id' => $offering->id]);
            } else {
                CourseOffering::whereKey($offering)->lockForUpdate()->firstOrFail();
            }
            $offering->refresh();
            $analysis = $this->review($actor, $campus, $offering, $plan['rows']);
            if ($analysis['review']['error_count'] || $analysis['state_hash'] !== $plan['state_hash'] || $analysis['class_state'] !== $plan['class_state']) {
                throw ValidationException::withMessages(['file' => 'The masterlist or teaching class changed after review. Upload and review the roster again. No students or assignments were changed.']);
            }
            $ids = array_keys($analysis['matches']);
            $existing = $offering->students()->pluck('users.id');
            $added = collect($ids)->diff($existing);
            $memberships = 0;
            foreach (array_chunk($ids, 400) as $chunk) {
                foreach (app(AcademicStudents::class)->students($actor, $campus)->whereKey($chunk)->get() as $student) {
                    foreach (array_keys($analysis['matches'][$student->id]['sections']) as $section) {
                        $membership = StudentSectionMembership::firstOrCreate(['user_id' => $student->id, 'semester_id' => $offering->semester_id, 'section' => $section], ['department_id' => $student->student_department_id]);
                        $memberships += (int) $membership->wasRecentlyCreated;
                    }
                }
            }
            $offering->students()->syncWithoutDetaching($added->mapWithKeys(fn ($id) => [$id => ['enrolled_at' => now()]]));
            $offering->update(['evaluator_selection' => [...($offering->evaluator_selection ?? []), 'last_roster_import' => [
                'sections' => array_keys($analysis['review']['sections']), 'students' => count($ids), 'saved_at' => now()->toIso8601String()]]]);

            return ['row_count' => count($ids), 'added' => $added->count(), 'section_memberships_added' => $memberships,
                'message' => $added->count().' evaluators added; '.(count($ids) - $added->count()).' already assigned. '.$memberships.' section memberships added for this academic period. Existing assignments, other sections and submitted evaluations were preserved.'];
        });
    }
}
