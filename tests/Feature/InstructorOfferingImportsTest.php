<?php

use App\Filament\Resources\CourseOfferingResource\RelationManagers\StudentsRelationManager;
use App\Filament\Resources\TeachingClassResource\Pages\EditTeachingClass;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\AcademicSection;
use App\Models\Campus;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\FacultySourceLink;
use App\Models\StudentSectionMembership;
use App\Models\TeachingClassSourceLink;
use App\Models\User;
use App\Services\BackgroundTasks;
use App\Services\InstructorOfferingImports;
use App\Services\StudentAcademicRecords;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    ['offering' => $this->offering, 'student' => $this->student] = evaluationFixture(false);
    $this->campus = $this->offering->campus;
    $this->actor = User::factory()->create(['is_student' => false]);
    $this->actor->assignRole('super_admin');
    foreach (['create_course::offering', 'update_course::offering', 'manage_evaluators_course::offering', 'view_any_course::offering',
        'create_course', 'create_user', 'manage_academic_students', 'view_academic_students', 'create_department', 'view_user', 'update_user', 'view_any_user'] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actor->campuses()->attach($this->campus);
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    Storage::fake('local');
    $this->service = app(InstructorOfferingImports::class);
    $this->importOptions = ['semester_id' => $this->offering->semester_id, 'source_campus_id' => '6', 'create_faculty' => true];
});

function instructorOfferingRow(array $changes = []): array
{
    return [...['sy' => '2026-2027', 'sem' => '1', 'course_code' => 'IT 412', 'course_desc' => 'System Integration and Architecture 1',
        'year' => '4', 'sem-2' => '2', 'lec_units' => '2', 'lab_units' => '1', 'units' => '3', 'section' => 'BSIT 4A',
        'id' => 'REG-007', 'last_name' => 'Dela Cruz', 'first_name' => 'Maria', 'id-2' => '227', 'campus_id' => '6',
        'code' => 'BISUBAL-BSIT-2023', 'name' => 'Bachelor of Science in Information Technology (2023)', 'grade_system' => 'R',
        'is_active' => 'TRUE', 'created_at' => '2020-01-01', 'updated_at' => '2025-01-01', 'updated_by' => '42601'], ...$changes];
}

function instructorOfferingCsv(array $rows): string
{
    $handle = fopen('php://temp', 'w+');
    fputcsv($handle, array_keys($rows[0]));
    foreach ($rows as $row) {
        fputcsv($handle, array_values($row));
    }
    rewind($handle);
    $content = stream_get_contents($handle);
    fclose($handle);
    Storage::disk('local')->put('offerings.csv', $content);

    return $content;
}

function prepareInstructorOfferings(array $rows, array $options = []): array
{
    instructorOfferingCsv($rows);

    return test()->service->prepare(test()->actor, test()->campus, [...test()->importOptions, ...$options], 'offerings.csv');
}

it('reviews only the selected actual academic period without importing records', function () {
    $before = [User::count(), Course::count(), CourseOffering::count()];
    $prepared = prepareInstructorOfferings([instructorOfferingRow(), instructorOfferingRow(['sy' => '2025-2026']), instructorOfferingRow(['sem' => '2'])]);
    expect($prepared['review']['selected_rows'])->toBe(1)->and($prepared['review']['other_period_rows'])->toBe(2)
        ->and($prepared['review']['new_classes'])->toBe(1)->and($prepared['review']['new_faculty'])->toBe(1)
        ->and([User::count(), Course::count(), CourseOffering::count()])->toBe($before);
});

it('automatically connects exact subject enrolments after committing the original offering upload', function () {
    $this->student->update(['student_department_id' => $this->offering->course->department_id]);
    app(StudentAcademicRecords::class)->save($this->student, $this->offering->semester_id, ['program' => 'BSIT', 'subjects' => [
        ['subject_code' => 'IT 412', 'description' => 'System Integration and Architecture 1', 'units' => 3],
    ]]);
    $prepared = prepareInstructorOfferings([instructorOfferingRow(), instructorOfferingRow(['section' => 'BSIT 4B'])]);
    $result = $this->service->commit($this->actor, $this->campus, $prepared['plan']);
    $classes = TeachingClassSourceLink::pluck('course_offering_id');
    expect($result['automatic_evaluators'])->toBe(1)->and($result['unresolved_evaluators'])->toBe(0)
        ->and(DB::table('course_offering_student')->whereIn('course_offering_id', $classes)->where('user_id', $this->student->id)->count())->toBe(1);
});

it('downloads an example with the selected period that the real importer can review without saving records', function () {
    $before = [User::count(), Course::count(), CourseOffering::count()];
    $download = $this->get(route('instructor-offerings.template', [
        'campus' => $this->campus, 'semester_id' => $this->offering->semester_id, 'source_campus_id' => '6',
    ]))->assertOk()->assertDownload('instructor-offerings-example.csv');
    $csv = $download->streamedContent();
    Storage::disk('local')->put('example.csv', $csv);
    $rows = $this->service->read('example.csv');
    expect($rows)->toHaveCount(2)->and($rows[0]['sy'])->toBe('2026-2027')->and($rows[0]['sem'])->toBe('1')
        ->and($rows[0]['id'])->toBe($rows[1]['id'])->and($rows[0]['section'])->not->toBe($rows[1]['section']);
    $review = $this->service->prepare($this->actor, $this->campus, $this->importOptions, 'example.csv')['review'];
    expect($review['ready_classes'])->toBe(2)->and($review['new_faculty'])->toBe(1)
        ->and([User::count(), Course::count(), CourseOffering::count()])->toBe($before);
});

it('protects the template by import permission campus access and academic period', function () {
    $url = route('instructor-offerings.template', ['campus' => $this->campus, 'semester_id' => $this->offering->semester_id]);
    $this->getJson(route('instructor-offerings.template', ['campus' => $this->campus, 'semester_id' => 999999]))->assertUnprocessable();
    $this->actor->removeRole('super_admin');
    $otherCampus = Campus::create(['name' => 'Other Campus', 'slug' => 'other-campus', 'is_active' => true]);
    $this->actor->campuses()->detach($otherCampus);
    $this->get(route('instructor-offerings.template', ['campus' => $otherCampus, 'semester_id' => $this->offering->semester_id]))->assertForbidden();
    $this->actor->revokePermissionTo('create_course::offering');
    $this->get($url)->assertForbidden();
    $this->actingAs($this->student)->get($url)->assertForbidden();
});

it('creates faculty profiles subjects sections and teaching classes without inventing sign-in or home-department details', function () {
    $prepared = prepareInstructorOfferings([instructorOfferingRow(), instructorOfferingRow()]);
    $result = $this->service->commit($this->actor, $this->campus, $prepared['plan']);
    $link = FacultySourceLink::sole();
    $faculty = User::findOrFail($link->user_id);
    $class = CourseOffering::where('user_id', $faculty->id)->sole();
    expect($result['new_classes'])->toBe(1)->and($prepared['review']['duplicate_rows'])->toBe(1)
        ->and($faculty->email)->toBeNull()->and($faculty->employee_id)->toBeNull()->and($faculty->instructor_department_id)->toBeNull()
        ->and($faculty->is_instructor)->toBeTrue()->and($faculty->hasRole('instructor'))->toBeTrue()
        ->and($faculty->campuses()->whereKey($this->campus)->exists())->toBeTrue()
        ->and($class->teaching_department_id)->toBe($this->offering->course->department_id)
        ->and($class->form_id)->toBeNull()->and($class->supervisorEvaluators()->count())->toBe(0)
        ->and($class->evaluator_selection['source'])->toBe('auto_enrolment_v1')
        ->and(AcademicSection::count())->toBe(1)->and(TeachingClassSourceLink::sole()->metadata['rows'])->toHaveCount(2);
});

it('assigns only the exact saved section roster and preserves a visiting instructors home department', function () {
    $home = Department::create(['campus_id' => $this->campus->id, 'code' => 'CTAS', 'name' => 'CTAS', 'is_active' => true]);
    $faculty = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Dela Cruz', 'is_instructor' => true, 'instructor_department_id' => $home->id]);
    $faculty->campuses()->attach($this->campus);
    $section = AcademicSection::create(['department_id' => $this->offering->course->department_id, 'semester_id' => $this->offering->semester_id, 'name' => 'BSIT 4A', 'is_active' => true]);
    StudentSectionMembership::create(['academic_section_id' => $section->id, 'department_id' => $section->department_id, 'semester_id' => $section->semester_id, 'section' => $section->name, 'user_id' => $this->student->id]);
    $prepared = prepareInstructorOfferings([instructorOfferingRow(['section' => ' BSIT   4A '])]);
    $this->service->commit($this->actor, $this->campus, $prepared['plan']);
    $class = CourseOffering::where('user_id', $faculty->id)->sole();
    expect($class->students()->pluck('users.id')->all())->toBe([$this->student->id])
        ->and($faculty->fresh()->instructor_department_id)->toBe($home->id)->and($faculty->fresh()->email)->toBe($faculty->email);
});

it('is idempotent and never replaces an existing class roster or its evaluation settings', function () {
    $row = instructorOfferingRow();
    $this->service->commit($this->actor, $this->campus, prepareInstructorOfferings([$row])['plan']);
    $class = CourseOffering::findOrFail(TeachingClassSourceLink::sole()->course_offering_id);
    $class->students()->attach($this->student, ['enrolled_at' => now()]);
    $class->update(['form_id' => $this->offering->form_id]);
    $again = prepareInstructorOfferings([$row]);
    $result = $this->service->commit($this->actor, $this->campus, $again['plan']);
    expect($again['review']['existing_classes'])->toBe(1)->and($result['new_classes'])->toBe(0)
        ->and(FacultySourceLink::count())->toBe(1)->and(TeachingClassSourceLink::count())->toBe(1)
        ->and($class->fresh()->form_id)->toBe($this->offering->form_id)->and($class->students()->count())->toBe(1);
});

it('separates conflicting subjects from ready classes and does not overwrite the catalogue', function () {
    $row = instructorOfferingRow();
    $conflict = Course::create(['department_id' => $this->offering->course->department_id, 'code' => $row['course_code'], 'name' => 'Different approved subject', 'units' => 3, 'is_active' => true]);
    $prepared = prepareInstructorOfferings([$row, instructorOfferingRow(['course_code' => 'IT 499', 'course_desc' => 'Capstone', 'section' => 'BSIT 4B'])]);
    expect($prepared['review']['blocked_classes'])->toBe(1)->and($prepared['review']['ready_classes'])->toBe(1);
    $result = $this->service->commit($this->actor, $this->campus, $prepared['plan']);
    expect($result['new_classes'])->toBe(1)->and($conflict->fresh()->name)->toBe('Different Approved Subject');
});

it('rejects stale reviews before creating any profile or class', function () {
    $prepared = prepareInstructorOfferings([instructorOfferingRow()]);
    Course::create(['department_id' => $this->offering->course->department_id, 'code' => 'IT 412', 'name' => 'Changed after review', 'is_active' => true]);
    expect(fn () => $this->service->commit($this->actor, $this->campus, $prepared['plan']))->toThrow(ValidationException::class);
    expect(FacultySourceLink::count())->toBe(0)->and(TeachingClassSourceLink::count())->toBe(0);
});

it('honors department scope even when the original export includes every college', function () {
    $this->actor->removeRole('super_admin');
    foreach (['create_course::offering', 'update_course::offering', 'manage_evaluators_course::offering', 'create_course', 'create_user', 'manage_academic_students'] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actor->update(['instructor_department_id' => $this->offering->course->department_id]);
    Department::create(['campus_id' => $this->campus->id, 'code' => 'CCJ', 'name' => 'CCJ', 'is_active' => true]);
    $prepared = prepareInstructorOfferings([instructorOfferingRow(), instructorOfferingRow(['id' => 'REG-CCJ', 'code' => 'BISUBAL-BSCRIM-2023', 'name' => 'Bachelor of Science in Criminology', 'section' => 'BSCRIM 1A'])]);
    expect($prepared['review']['skipped_rows'])->toBe(1)->and($prepared['review']['ready_classes'])->toBe(1);
    $this->actor->revokePermissionTo('create_user');
    expect(fn () => $this->service->commit($this->actor, $this->campus, $prepared['plan']))->toThrow(ValidationException::class);
});

it('allows an explicit same-campus faculty match without changing that account', function () {
    $faculty = $this->offering->instructor;
    $faculty->campuses()->attach($this->campus);
    $email = $faculty->email;
    $prepared = prepareInstructorOfferings([instructorOfferingRow()], ['faculty_matches' => [['source_id' => 'REG-007', 'user_id' => $faculty->id]]]);
    expect($prepared['review']['new_faculty'])->toBe(0);
    $this->service->commit($this->actor, $this->campus, $prepared['plan']);
    expect(FacultySourceLink::sole()->user_id)->toBe($faculty->id)->and($faculty->fresh()->email)->toBe($email);
});

it('matches an exact full given name when the export includes a separately stored middle name', function () {
    $faculty = User::factory()->create(['first_name' => 'Maria', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz', 'is_instructor' => true]);
    $faculty->campuses()->attach($this->campus);
    $prepared = prepareInstructorOfferings([instructorOfferingRow(['first_name' => 'Maria Santos'])]);
    expect($prepared['review']['new_faculty'])->toBe(0)->and($prepared['review']['ready_classes'])->toBe(1);
    $this->service->commit($this->actor, $this->campus, $prepared['plan']);
    expect(FacultySourceLink::sole()->user_id)->toBe($faculty->id)->and($faculty->fresh()->first_name)->toBe('Maria');
});

it('blocks conflicting registrar identities and wrong-campus rows', function () {
    $prepared = prepareInstructorOfferings([instructorOfferingRow(), instructorOfferingRow(['first_name' => 'Different'])]);
    expect($prepared['review']['blocked_classes'])->toBe(1)->and($prepared['review']['ready_classes'])->toBe(0);
    $prepared = prepareInstructorOfferings([instructorOfferingRow(['campus_id' => '99'])]);
    expect($prepared['review']['ready_classes'])->toBe(0);
});

it('runs review and confirmation through background tasks with a downloadable review', function () {
    Queue::fake();
    $csv = instructorOfferingCsv([instructorOfferingRow()]);
    $tasks = app(BackgroundTasks::class);
    $review = $tasks->upload($this->actor, $this->campus, UploadedFile::fake()->createWithContent('offerings.csv', $csv), 'offering-review', 'Review offerings', $this->importOptions);
    $tasks->run($review->id);
    expect($review->fresh()->status)->toBe('completed')->and($review->fresh()->result['review']['new_classes'])->toBe(1);
    Storage::disk('local')->assertExists($review->fresh()->file_path);
    $import = $tasks->confirm($review->fresh(), $this->actor, $this->campus);
    $tasks->run($import->id);
    expect($import->fresh()->status)->toBe('completed')->and($import->fresh()->result['new_classes'])->toBe(1);
    expect($tasks->confirm($review->fresh(), $this->actor, $this->campus)->id)->toBe($import->id);
});

it('requires a new review if the saved section students change', function () {
    $section = AcademicSection::create(['department_id' => $this->offering->course->department_id, 'semester_id' => $this->offering->semester_id, 'name' => 'BSIT 4A', 'is_active' => true]);
    $prepared = prepareInstructorOfferings([instructorOfferingRow()]);
    StudentSectionMembership::create(['academic_section_id' => $section->id, 'department_id' => $section->department_id, 'semester_id' => $section->semester_id, 'section' => $section->name, 'user_id' => $this->student->id]);
    expect(fn () => $this->service->commit($this->actor, $this->campus, $prepared['plan']))->toThrow(ValidationException::class);
    expect(FacultySourceLink::count())->toBe(0);
});

it('lets an administrator complete a faculty profile without inventing an employee ID', function () {
    $this->service->commit($this->actor, $this->campus, prepareInstructorOfferings([instructorOfferingRow()])['plan']);
    $faculty = User::findOrFail(FacultySourceLink::sole()->user_id);
    $component = Livewire\Livewire::test(EditUser::class, ['record' => $faculty->id])
        ->fillForm(['position' => 'Visiting Faculty'])->call('save')->assertHasNoFormErrors();
    $component->fillForm(['email' => 'faculty@example.test'])->call('save')->assertHasFormErrors(['instructor_department_id']);
    $component->fillForm(['instructor_department_id' => $this->offering->course->department_id])->call('save')->assertHasNoFormErrors();
    expect($faculty->fresh()->email)->toBe('faculty@example.test')->and($faculty->fresh()->employee_id)->toBeNull();
});

it('follows confirmed section rosters until a class is customized or prepared for evaluation', function () {
    $this->service->commit($this->actor, $this->campus, prepareInstructorOfferings([instructorOfferingRow()])['plan']);
    $class = CourseOffering::findOrFail(TeachingClassSourceLink::sole()->course_offering_id);
    $section = AcademicSection::sole();
    StudentSectionMembership::create(['academic_section_id' => $section->id, 'department_id' => $section->department_id, 'semester_id' => $section->semester_id, 'section' => $section->name, 'user_id' => $this->student->id]);
    expect($this->service->syncSection($this->actor, $this->campus, $section))->toBe(1);
    expect($class->students()->pluck('users.id')->all())->toBe([$this->student->id]);
    $next = User::factory()->create(['is_student' => true]);
    $next->campuses()->attach($this->campus);
    StudentSectionMembership::create(['academic_section_id' => $section->id, 'department_id' => $section->department_id, 'semester_id' => $section->semester_id, 'section' => $section->name, 'user_id' => $next->id]);
    expect($this->service->syncSection($this->actor, $this->campus, $section))->toBe(1)->and($class->students()->count())->toBe(2);
    $class->update(['form_id' => $this->offering->form_id]);
    expect($this->service->syncSection($this->actor, $this->campus, $section))->toBe(0);
    $class->update(['form_id' => null, 'evaluator_selection' => [...$class->fresh()->evaluator_selection, 'auto_section_roster' => false]]);
    expect($this->service->syncSection($this->actor, $this->campus, $section))->toBe(0);
});

it('stops automatic roster changes when a staff member manually detaches an evaluator', function () {
    $this->service->commit($this->actor, $this->campus, prepareInstructorOfferings([instructorOfferingRow()])['plan']);
    $class = CourseOffering::findOrFail(TeachingClassSourceLink::sole()->course_offering_id);
    $class->students()->attach($this->student, ['enrolled_at' => now()]);
    Livewire\Livewire::test(StudentsRelationManager::class, [
        'ownerRecord' => $class, 'pageClass' => EditTeachingClass::class,
    ])->callTableAction('detach', $this->student)->assertHasNoTableActionErrors();
    expect($class->fresh()->evaluator_selection['auto_section_roster'])->toBeFalse()->and($class->students()->count())->toBe(0);
});

it('preserves automatic section rosters when only supervisor choices change', function () {
    $section = AcademicSection::create(['department_id' => $this->offering->course->department_id, 'semester_id' => $this->offering->semester_id, 'name' => 'BSIT 4A', 'is_active' => true]);
    StudentSectionMembership::create(['academic_section_id' => $section->id, 'department_id' => $section->department_id, 'semester_id' => $section->semester_id, 'section' => $section->name, 'user_id' => $this->student->id]);
    $this->service->commit($this->actor, $this->campus, prepareInstructorOfferings([instructorOfferingRow()])['plan']);
    $class = CourseOffering::findOrFail(TeachingClassSourceLink::sole()->course_offering_id);
    Livewire\Livewire::test(StudentsRelationManager::class, [
        'ownerRecord' => $class, 'pageClass' => EditTeachingClass::class,
    ])->callTableAction('assignSections', data: ['sections' => $class->evaluator_selection['sections'], 'use_exceptions' => false, 'include' => [], 'exclude' => [], 'supervisor_ids' => [$this->actor->id]])
        ->assertHasNoTableActionErrors();
    expect($class->fresh()->evaluator_selection['auto_section_roster'])->toBeTrue()
        ->and($class->supervisorEvaluators()->pluck('users.id')->all())->toBe([$this->actor->id]);
});

it('reads original Excel offering files with title rows extra columns repeated headings and unrelated sheets', function (string $format) {
    $book = new Spreadsheet;
    $cover = $book->getActiveSheet()->setTitle('Instructions');
    for ($i = 1; $i <= 12; $i++) {
        $cover->setCellValue('A'.$i, 'Unrelated notes');
    }
    $sheet = $book->createSheet()->setTitle('Teaching assignments');
    $sheet->setCellValue('A1', 'Original campus offering export');
    $row = instructorOfferingRow();
    $headers = [...array_keys($row), 'Internal notes', 'Internal notes', ''];
    $sheet->fromArray($headers, null, 'A4');
    $sheet->fromArray([...array_values($row), '=1/0', '=SUM(1,2)', 'unneeded'], null, 'A5');
    $sheet->fromArray($headers, null, 'A6');
    $history = instructorOfferingRow(['sy' => '2024-2025', 'first_name' => '=1/0']);
    $sheet->fromArray(array_values($history), null, 'A7');
    $path = tempnam(sys_get_temp_dir(), 'offering-workbook-');
    try {
        IOFactory::createWriter($book, $format)->save($path);
        $bytes = file_get_contents($path);
        Storage::disk('local')->put('original.'.strtolower($format), $bytes);
        $p = $this->service->prepare($this->actor, $this->campus, $this->importOptions, 'original.'.strtolower($format));
        expect($p['review']['new_classes'])->toBe(1)->and($p['review']['blocked_classes'])->toBe(0)
            ->and($p['review']['other_period_rows'])->toBe(1)->and($p['review']['repeated_headers'])->toBe(1)
            ->and($p['review']['ignored_sheets'])->toBe(['Instructions'])->and($p['review']['sources'][0]['header_row'])->toBe(4)
            ->and($p['plan']['rows'][0])->not->toHaveKey('Internal notes')->and($p['plan']['rows'][0]['sem-2'])->toBe('2')
            ->and(file_get_contents($path))->toBe($bytes);
        Queue::fake();
        $task = app(BackgroundTasks::class)->upload($this->actor, $this->campus,
            UploadedFile::fake()->createWithContent('original.'.strtolower($format), $bytes), 'offering-review', 'Review original workbook', $this->importOptions);
        app(BackgroundTasks::class)->run($task->id);
        expect($task->fresh()->status)->toBe('completed', $task->fresh()->error ?? '')
            ->and($task->fresh()->result['review']['new_classes'])->toBe(1)->and(TeachingClassSourceLink::count())->toBe(0);
    } finally {
        $book->disconnectWorksheets();
        if (is_file($path)) {
            unlink($path);
        }
    }
})->with(['Xlsx', 'Xls']);

it('detects reordered offering header aliases and preserves formatted source IDs', function () {
    $row = instructorOfferingRow(['id' => '123']);
    $aliases = ['sy' => 'Academic Year', 'sem' => 'Semester', 'course_code' => 'Subject Code', 'course_desc' => 'Subject Title',
        'id' => 'Source Faculty ID', 'code' => 'Program Code', 'name' => 'Program Name'];
    $keys = array_reverse(array_keys($row));
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->fromArray(array_map(fn ($key) => $aliases[$key] ?? $key, $keys), null, 'A3');
    $sheet->fromArray(array_map(fn ($key) => $row[$key], $keys), null, 'A4');
    $idCol = array_search('id', $keys, true) + 1;
    $sheet->setCellValue([$idCol, 4], 123)->getStyle([$idCol, 4])->getNumberFormat()->setFormatCode('000000');
    $path = tempnam(sys_get_temp_dir(), 'offering-aliases-');
    try {
        (new Xlsx($book))->save($path);
        Storage::disk('local')->put('aliases.xlsx', file_get_contents($path));
        $p = $this->service->prepare($this->actor, $this->campus, $this->importOptions, 'aliases.xlsx');
        expect($p['review']['new_classes'])->toBe(1)->and($p['plan']['rows'][0]['id'])->toBe('000123');
    } finally {
        $book->disconnectWorksheets();
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('ignores duplicate unused CSV columns but flags formulas in required offering cells', function () {
    $row = instructorOfferingRow();
    $handle = fopen('php://temp', 'w+');
    fputcsv($handle, [...array_keys($row), 'unused', 'unused', '']);
    fputcsv($handle, [...array_values($row), '=1/0', '=SUM(1,2)', 'ignored']);
    fputcsv($handle, [...array_values(instructorOfferingRow(['section' => 'BSIT 4B', 'first_name' => '=1/0'])), '', '', '']);
    rewind($handle);
    Storage::disk('local')->put('extra.csv', stream_get_contents($handle));
    fclose($handle);
    $p = $this->service->prepare($this->actor, $this->campus, $this->importOptions, 'extra.csv');
    // A conflicting name for the same source identity also protects the good row.
    expect($p['review']['blocked_classes'])->toBeGreaterThan(0)
        ->and(implode(' ', array_merge(...array_column($p['review']['details'], 'messages'))))->toContain('formula')
        ->and($p['plan']['rows'][0])->not->toHaveKey('unused');
});

it('excludes unreadable academic periods and lists those rows in the downloaded validation report', function () {
    $csv = instructorOfferingCsv([instructorOfferingRow(), instructorOfferingRow(['sy' => '=1/0', 'id' => 'OTHER-008'])]);
    Queue::fake();
    $task = app(BackgroundTasks::class)->upload($this->actor, $this->campus, UploadedFile::fake()->createWithContent('original.csv', $csv),
        'offering-review', 'Review original file', $this->importOptions);
    app(BackgroundTasks::class)->run($task->id);
    $task->refresh();
    expect($task->status)->toBe('completed', $task->error ?? '')->and($task->result['review']['ready_classes'])->toBe(1)
        ->and($task->result['review']['unclassified_period_count'])->toBe(1)->and($task->result['review']['other_period_rows'])->toBe(0);
    expect(Storage::disk('local')->get($task->file_path))->toContain('Excluded: period unreadable', 'Worksheet:3');
    expect(TeachingClassSourceLink::count())->toBe(0);
});
