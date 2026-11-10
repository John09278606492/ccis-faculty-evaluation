<?php

namespace App\Services;

use App\Models\AcknowledgementForm;
use App\Models\AcknowledgementRecipient;
use App\Models\CourseOffering;
use App\Models\User;
use App\Notifications\BellNotification;
use App\Support\SignatureStrokes;
use Filament\Notifications\Actions\Action as NotificationAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rosters and signatures for acknowledgement receipts. The class enrollment is the source
 * of truth: a student signs for themselves, and only a form's instructor can sign for or
 * clear a student.
 */
class AcknowledgementForms
{
    /** Same layout budget as the paper form: 40 rows on an A4 page, at most 32 of them students. */
    private const PAGE_UNITS = 40;

    private const MAX_STUDENTS_PER_FRAGMENT = 32;

    private const HEADER_UNITS = 4;

    private const INSTRUCTOR_UNITS = 4;

    /** Starting values for a new form, taken from the class. */
    public function defaultsFor(CourseOffering $offering): array
    {
        $offering->loadMissing(['semester.schoolYear', 'instructor']);

        return [
            'year_section' => filled($offering->section) ? (string) $offering->section : $this->commonSection($offering),
            'term_label' => $this->termLabel($offering),
            'instructor_name' => mb_strtoupper((string) $offering->instructor?->name),
        ];
    }

    /** A class saved without a section: use the section most of its students belong to that semester. */
    protected function commonSection(CourseOffering $offering): string
    {
        return (string) DB::table('course_offering_student as enrolled')
            ->join('student_section_memberships as membership', 'membership.user_id', '=', 'enrolled.user_id')
            ->where('enrolled.course_offering_id', $offering->id)
            ->where('membership.semester_id', $offering->semester_id)
            ->groupBy('membership.section')
            ->orderByRaw('count(*) desc')
            ->value('membership.section');
    }

    public function termLabel(CourseOffering $offering): string
    {
        $semester = str_ireplace(['1st', '2nd', '3rd'], ['First', 'Second', 'Third'], (string) $offering->semester?->name);
        $year = str_replace('-', ' - ', (string) $offering->semester?->schoolYear?->name);

        return trim("{$semester}, A.Y. {$year}", ' ,');
    }

    /** Adds newly enrolled students and removes unsigned rows of students who left. Signed rows always stay. */
    public function syncRoster(AcknowledgementForm $form): void
    {
        $missing = DB::table('course_offering_student as enrolled')
            ->join('users', 'users.id', '=', 'enrolled.user_id')
            ->leftJoin('acknowledgement_recipients as listed', function ($join) use ($form) {
                $join->on('listed.user_id', '=', 'enrolled.user_id')->where('listed.acknowledgement_form_id', $form->id);
            })
            ->where('enrolled.course_offering_id', $form->course_offering_id)
            ->whereNull('users.deleted_at')
            ->whereNull('listed.id')
            ->get(['users.id', 'users.name', 'users.first_name', 'users.middle_name', 'users.last_name']);

        $now = now();
        foreach ($missing->chunk(200) as $chunk) {
            AcknowledgementRecipient::insertOrIgnore($chunk->map(fn ($student) => [
                'acknowledgement_form_id' => $form->id,
                'user_id' => $student->id,
                'student_name' => $this->printedName($student),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }

        AcknowledgementRecipient::where('acknowledgement_form_id', $form->id)
            ->whereNull('signed_at')
            ->whereNotIn('user_id', DB::table('course_offering_student')->where('course_offering_id', $form->course_offering_id)->select('user_id'))
            ->delete();
    }

    /** SURNAME, FIRST, M. when the account has separate name fields, otherwise the name in capitals. */
    public function printedName(object $student): string
    {
        if (blank($student->last_name) || blank($student->first_name)) {
            return mb_strtoupper(trim((string) $student->name));
        }
        $initial = filled($student->middle_name) ? ', '.mb_substr(trim($student->middle_name), 0, 1).'.' : '';

        return mb_strtoupper(trim($student->last_name).', '.trim($student->first_name).$initial);
    }

    public function isEnrolled(AcknowledgementForm $form, User $student): bool
    {
        return DB::table('course_offering_student')
            ->where('course_offering_id', $form->course_offering_id)
            ->where('user_id', $student->id)
            ->exists();
    }

    /** @throws ValidationException when the form is closed or the student is not in the class */
    public function signAsStudent(AcknowledgementForm $form, User $student, ?string $strokes, ?string $ip): bool
    {
        if (! $form->isOpen() || ! $this->isEnrolled($form, $student)) {
            throw ValidationException::withMessages(['signature' => 'This form is no longer open for your signature.']);
        }

        return $this->write($form, $student, $strokes, $student, $ip);
    }

    public function signForStudent(AcknowledgementRecipient $recipient, ?string $strokes, User $actor, ?string $ip): bool
    {
        $student = User::withoutGlobalScope('assigned_data')->withTrashed()->findOrFail($recipient->user_id);

        return $this->write(AcknowledgementForm::findOrFail($recipient->acknowledgement_form_id), $student, $strokes, $actor, $ip);
    }

    public function clear(AcknowledgementRecipient $recipient): void
    {
        $recipient->update(['signature_path' => null, 'signed_at' => null, 'signed_by' => null, 'signed_ip' => null]);
    }

    /**
     * Lays forms out as printed pages: each page repeats the letterhead, a form continues on
     * the next page when it runs out of room, and the instructor line stays with a student row.
     *
     * @return array<int, array<int, array{form: AcknowledgementForm, students: Collection, final: bool}>>
     */
    public function printPages(Collection $forms): array
    {
        $pages = [['fragments' => [], 'units' => 0]];

        foreach ($forms as $form) {
            $students = $form->recipients->values();

            if ($students->isEmpty()) {
                $units = self::HEADER_UNITS + self::INSTRUCTOR_UNITS;
                $page = array_key_last($pages);
                if ($pages[$page]['fragments'] && $pages[$page]['units'] + $units > self::PAGE_UNITS) {
                    $pages[] = ['fragments' => [], 'units' => 0];
                    $page = array_key_last($pages);
                }
                $pages[$page]['fragments'][] = ['form' => $form, 'students' => $students, 'final' => true];
                $pages[$page]['units'] += $units;

                continue;
            }

            $start = 0;
            while ($start < $students->count()) {
                $remaining = $students->count() - $start;
                $finalUnits = self::HEADER_UNITS + $remaining + self::INSTRUCTOR_UNITS;
                $page = array_key_last($pages);

                if ($finalUnits <= self::PAGE_UNITS - $pages[$page]['units']) {
                    $pages[$page]['fragments'][] = ['form' => $form, 'students' => $students->slice($start)->values(), 'final' => true];
                    $pages[$page]['units'] += $finalUnits;

                    break;
                }

                $take = min(self::PAGE_UNITS - $pages[$page]['units'] - self::HEADER_UNITS, self::MAX_STUDENTS_PER_FRAGMENT, $remaining);
                // The instructor line never sits alone on a page: when the rest would fit but it would not, hold a student back to go with it.
                if ($take === $remaining) {
                    $take--;
                }
                if ($take < 1) {
                    $pages[] = ['fragments' => [], 'units' => 0];

                    continue;
                }

                $pages[$page]['fragments'][] = ['form' => $form, 'students' => $students->slice($start, $take)->values(), 'final' => false];
                $pages[$page]['units'] += self::HEADER_UNITS + $take;
                $start += $take;
            }
        }

        return array_column($pages, 'fragments');
    }

    /**
     * One row per subject the student is enrolled in, with how many forms it has and how many
     * still need their signature. A single query, however many subjects or forms there are.
     */
    public function studentSummary(User $student, ?int $campusId): Collection
    {
        return DB::table('course_offering_student as enrolled')
            ->join('course_offerings as class', 'class.id', '=', 'enrolled.course_offering_id')
            ->join('courses as course', 'course.id', '=', 'class.course_id')
            ->join('semesters as semester', 'semester.id', '=', 'class.semester_id')
            ->join('school_years as year', 'year.id', '=', 'semester.school_year_id')
            ->leftJoin('acknowledgement_forms as form', function ($join) {
                $join->on('form.course_offering_id', '=', 'class.id')->where('form.status', '!=', 'archived');
            })
            ->leftJoin('acknowledgement_recipients as row', function ($join) use ($student) {
                $join->on('row.acknowledgement_form_id', '=', 'form.id')->where('row.user_id', $student->id);
            })
            ->where('enrolled.user_id', $student->id)
            ->where('year.campus_id', $campusId)
            ->where(fn ($query) => $query->where('class.is_active', true)->orWhereNotNull('form.id'))
            ->groupBy('class.id', 'course.code', 'course.name', 'class.section')
            // A form the instructor removed this student from does not count for them.
            ->selectRaw("class.id, course.code, course.name, class.section,
                count(case when row.removed_at is null then form.id end) as total,
                count(case when row.removed_at is null then row.signed_at end) as signed,
                sum(case when form.status = 'active' and row.removed_at is null and row.signed_at is null then 1 else 0 end) as to_sign")
            ->orderByDesc('to_sign')
            ->orderBy('course.code')
            ->get()
            ->map(fn ($subject) => tap($subject, function ($subject) {
                $subject->total = (int) $subject->total;
                $subject->signed = (int) $subject->signed;
                $subject->to_sign = (int) $subject->to_sign;
            }));
    }

    /** Gives the student their record on open forms issued before they enrolled. */
    public function addToOpenForms(User $student, ?int $campusId): void
    {
        $missing = AcknowledgementForm::query()
            ->inCampus($campusId)
            ->where('status', 'active')
            ->whereIn('course_offering_id', DB::table('course_offering_student')->where('user_id', $student->id)->select('course_offering_id'))
            ->whereNotIn('id', AcknowledgementRecipient::where('user_id', $student->id)->select('acknowledgement_form_id'))
            ->pluck('id');
        if ($missing->isEmpty()) {
            return;
        }

        $now = now();
        $name = $this->printedName($student);
        AcknowledgementRecipient::insertOrIgnore($missing->map(fn ($formId) => [
            'acknowledgement_form_id' => $formId,
            'user_id' => $student->id,
            'student_name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    /** Open forms where the student, still enrolled, has an unsigned record of their own. */
    public function signableForms(User $student, ?int $campusId): Builder
    {
        return AcknowledgementForm::query()
            ->inCampus($campusId)
            ->where('status', 'active')
            ->whereIn('course_offering_id', DB::table('course_offering_student')->where('user_id', $student->id)->select('course_offering_id'))
            ->whereIn('id', AcknowledgementRecipient::where('user_id', $student->id)->whereNull('signed_at')->whereNull('removed_at')->select('acknowledgement_form_id'));
    }

    /**
     * One drawing applied to the forms the student ticked. A single UPDATE, so the batch saves
     * completely or not at all, and only ever touches the student's own unsigned records.
     *
     * @param  array<int, int|string>  $formIds
     * @return int how many forms were signed
     */
    public function signManyAsStudent(User $student, ?int $campusId, array $formIds, ?string $strokes, ?string $ip): int
    {
        $path = SignatureStrokes::pathFrom($strokes);
        if (! $path) {
            throw ValidationException::withMessages(['signature' => 'Please draw your signature before saving.']);
        }

        $now = now();

        return AcknowledgementRecipient::where('user_id', $student->id)
            ->whereNull('signed_at')
            ->whereNull('removed_at')
            ->whereIn('acknowledgement_form_id', $this->signableForms($student, $campusId)->whereKey($formIds)->select('acknowledgement_forms.id'))
            ->update(['signature_path' => $path, 'signed_at' => $now, 'signed_by' => $student->id, 'signed_ip' => $ip, 'updated_at' => $now]);
    }

    public function toSignCount(User $student, ?int $campusId): int
    {
        return AcknowledgementForm::query()
            ->inCampus($campusId)
            ->where('status', 'active')
            ->whereIn('course_offering_id', DB::table('course_offering_student')->where('user_id', $student->id)->select('course_offering_id'))
            ->whereNotIn('id', AcknowledgementRecipient::where('user_id', $student->id)
                ->where(fn ($query) => $query->whereNotNull('signed_at')->orWhereNotNull('removed_at'))
                ->select('acknowledgement_form_id'))
            ->count();
    }

    /** Tells every student who has not signed an open form, in their notification bell. */
    public function notifyWaiting(AcknowledgementForm $form, string $title): int
    {
        if (! $form->isOpen()) {
            return 0;
        }

        $students = User::withoutGlobalScope('assigned_data')
            ->whereIn('id', AcknowledgementRecipient::where('acknowledgement_form_id', $form->id)->whereNull('signed_at')->whereNull('removed_at')->select('user_id'))
            ->get();
        if ($students->isEmpty()) {
            return 0;
        }

        $campusId = DB::table('course_offerings as class')
            ->join('semesters as semester', 'semester.id', '=', 'class.semester_id')
            ->join('school_years as year', 'year.id', '=', 'semester.school_year_id')
            ->where('class.id', $form->course_offering_id)
            ->value('year.campus_id');

        // One transaction, so a whole class costs one disk commit on SQLite.
        DB::transaction(fn () => BellNotification::make()
            ->title($title)
            ->body("{$form->document_received} · {$form->year_section}")
            ->warning()
            ->actions([NotificationAction::make('sign')->label('Open My Acknowledgements')->url("/{$campusId}/my-acknowledgements")->markAsRead()])
            ->sendToDatabase($students));

        return $students->count();
    }

    /** The same document for another class, or null when that class already has it. */
    public function issueTo(AcknowledgementForm $source, CourseOffering $class, string $document, User $actor): ?AcknowledgementForm
    {
        $exists = AcknowledgementForm::where('course_offering_id', $class->id)->where('document_received', $document)->exists();
        if ($exists) {
            return null;
        }

        $form = AcknowledgementForm::create([
            ...$source->only(['document_title', 'date_received', 'college_name', 'department_name', 'instructor_designation']),
            ...$this->defaultsFor($class),
            'course_offering_id' => $class->id,
            'document_received' => $document,
            'status' => 'active',
            'created_by' => $actor->id,
        ]);
        $this->syncRoster($form);
        $this->notifyWaiting($form, 'New form to sign');

        return $form;
    }

    /** The first signature stands: a second device or a double click changes nothing. */
    private function write(AcknowledgementForm $form, User $student, ?string $strokes, User $actor, ?string $ip): bool
    {
        $path = SignatureStrokes::pathFrom($strokes);
        if (! $path) {
            throw ValidationException::withMessages(['signature' => 'Please draw your signature before saving.']);
        }

        $now = now();
        AcknowledgementRecipient::insertOrIgnore([
            'acknowledgement_form_id' => $form->id,
            'user_id' => $student->id,
            'student_name' => $this->printedName($student),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return AcknowledgementRecipient::where('acknowledgement_form_id', $form->id)
            ->where('user_id', $student->id)
            ->whereNull('signed_at')
            ->whereNull('removed_at')
            ->update(['signature_path' => $path, 'signed_at' => $now, 'signed_by' => $actor->id, 'signed_ip' => $ip, 'updated_at' => $now]) === 1;
    }

    /**
     * Takes students off a receipt, for example when they stopped attending. With $wholeClass, also off the
     * other receipts of the same class. The rows stay (with any signature), so they can be restored.
     *
     * @param  array<int, int>  $userIds
     * @return int how many receipt rows were removed
     */
    public function removeStudents(AcknowledgementForm $form, array $userIds, bool $wholeClass = false): int
    {
        $formIds = $wholeClass
            ? AcknowledgementForm::where('course_offering_id', $form->course_offering_id)->pluck('id')->all()
            : [$form->id];
        if ($wholeClass) {
            foreach (AcknowledgementForm::whereKey($formIds)->whereKeyNot($form->id)->get() as $other) {
                $this->syncRoster($other);
            }
        }

        return AcknowledgementRecipient::whereIn('acknowledgement_form_id', $formIds)
            ->whereIn('user_id', $userIds)
            ->whereNull('removed_at')
            ->update(['removed_at' => now(), 'updated_at' => now()]);
    }

    /** @param  array<int, int>  $userIds */
    public function restoreStudents(AcknowledgementForm $form, array $userIds): int
    {
        return AcknowledgementRecipient::where('acknowledgement_form_id', $form->id)
            ->whereIn('user_id', $userIds)
            ->whereNotNull('removed_at')
            ->update(['removed_at' => null, 'updated_at' => now()]);
    }
}
