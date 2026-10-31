<?php

use App\Filament\Pages\BackgroundProcessing;
use App\Filament\Resources\AcademicStudentResource;
use App\Filament\Resources\AcademicStudentResource\Pages\ListAcademicStudents;
use App\Filament\Resources\CourseOfferingResource\Pages\EditCourseOffering;
use App\Filament\Resources\CourseOfferingResource\RelationManagers\StudentsRelationManager;
use App\Models\Campus;
use App\Models\Department;
use App\Models\SchoolYear;
use App\Models\Semester;
use App\Models\StudentAcademicRecord;
use App\Models\StudentSubjectEnrolment;
use App\Models\User;
use App\Services\AcademicStudentImports;
use App\Services\StudentAcademicRecords;
use App\Services\StudentPins;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    ['offering' => $this->offering] = evaluationFixture();
    $this->campus = $this->offering->campus;
    $this->actor = $this->offering->evaluatee;
    $this->actor->campuses()->attach($this->campus);
    foreach (['view_academic_students', 'manage_academic_students', 'import_academic_students', 'manage_evaluators_course::offering', 'update_course::offering'] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus);
    $this->imports = app(AcademicStudentImports::class);
    $this->files = [];
    $this->row = [1, 123, 'DELA CRUZ', 'MARIA', 'SANTOS', 'F', 'CCIS', 'BSIT', 2, 6,
        'CS101', 'Introduction to Computing', 2, 1, 3, 'GE US', 'Understanding the Self', 3, 0, 3];
    $this->report = function (array $rows, string $year = '2026-2027', string $campus = ''): UploadedFile {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', 'Bohol Island State University');
        $sheet->setCellValue('A2', $campus ?: $this->campus->name);
        $sheet->setCellValue('A5', 'List of Students');
        $sheet->setCellValue('A6', 'Semester: 1 School Year: '.$year);
        $sheet->fromArray([['#', 'ID No.', 'Lastname', 'Firstname', 'Middlename', 'Sex', 'College', 'Course', 'Year Level', 'Total Units',
            'Subject Code', 'Description', 'Lec', 'Lab', 'Units', 'Subject Code', 'Description', 'Lec', 'Lab', 'Units']], null, 'A8');
        $sheet->fromArray($rows, null, 'A9', true);
        $sheet->getStyle('B9:B'.(8 + count($rows)))->getNumberFormat()->setFormatCode('000000');
        $path = tempnam(sys_get_temp_dir(), 'campus-report-');
        $this->files[] = $path;
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'student-list_report.xlsx', test: true);
    };
});

afterEach(function () {
    foreach ($this->files as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('reads the original report layout and preserves zero-padded IDs without inventing sections', function () {
    $file = ($this->report)([$this->row]);
    $hash = hash_file('sha256', $file->getRealPath());
    $review = $this->imports->reviewUpload($this->actor, $this->campus, $file, $this->offering->semester_id);
    expect($review['new_students'])->toBe(1)->and($review['error_count'])->toBe(0)->and($review['subject_enrolments'])->toBe(2)
        ->and($review['sources'][0]['header_row'])->toBe(8)->and(User::where('student_id', '000123')->exists())->toBeFalse();
    $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id);
    $student = User::where('student_id', '000123')->sole();
    expect($student->getRawOriginal('password'))->toBeNull()->and($student->pin_issued_at)->toBeNull()
        ->and($student->studentSectionMemberships()->count())->toBe(0)->and($student->subjectEnrolments()->count())->toBe(2)
        ->and($student->academicRecords()->sole()->program)->toBe('BSIT')
        ->and($student->academicRecords()->sole()->reported_year_level)->toBe(2)
        ->and($student->academicRecords()->sole()->total_units)->toBe('6.00')
        ->and(hash_file('sha256', $file->getRealPath()))->toBe($hash);
    expect(auth()->getProvider()->validateCredentials($student, ['password' => 'password123']))->toBeFalse();
    $pin = StudentPins::issue(collect([$student]))->sole()['pin'];
    expect(Hash::check($pin, $student->fresh()->password))->toBeTrue();
});

it('reads needed student columns and ignores unrelated worksheets and unused formula columns', function () {
    $file = ($this->report)([$this->row]);
    $book = IOFactory::load($file->getRealPath());
    try {
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('U8', 'Unused office notes');
        $sheet->setCellValue('U9', '=1/0');
        $notes = $book->createSheet()->setTitle('Unrelated office summary');
        for ($i = 1; $i <= 15; $i++) {
            $notes->setCellValue('A'.$i, 'Unrelated information');
        }
        (new Xlsx($book))->save($file->getRealPath());
    } finally {
        $book->disconnectWorksheets();
    }
    $review = $this->imports->reviewUpload($this->actor, $this->campus, $file, $this->offering->semester_id);
    expect($review['new_students'])->toBe(1)->and($review['error_count'])->toBe(0)
        ->and($review['subject_enrolments'])->toBe(2)->and($review['ignored_sheets'])->toBe(['Unrelated office summary']);
});

it('selects the configured period from a workbook containing multiple student reports', function () {
    $file = ($this->report)([$this->row]);
    $book = IOFactory::load($file->getRealPath());
    try {
        $second = clone $book->getActiveSheet();
        $second->setTitle('Second semester');
        $second->setCellValue('A6', 'Semester: 2 School Year: 2026-2027');
        $second->setCellValue('B9', 125);
        $book->addSheet($second);
        (new Xlsx($book))->save($file->getRealPath());
    } finally {
        $book->disconnectWorksheets();
    }
    $review = $this->imports->reviewUpload($this->actor, $this->campus, $file, $this->offering->semester_id);
    expect($review['new_students'])->toBe(1)->and($review['semester_id'])->toBe($this->offering->semester_id)
        ->and($review['skipped_period_rows'])->toBe(1)->and($review['ignored_sheets'])->toContain('Second semester');
    $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id);
    expect(User::where('student_id', '000123')->exists())->toBeTrue()->and(User::where('student_id', '000125')->exists())->toBeFalse();
});

it('automatically selects the report period and rejects foreign-campus or unavailable-period reports', function () {
    $other = Semester::create(['school_year_id' => $this->offering->semester->school_year_id, 'name' => 'Second Semester', 'start_date' => '2027-01-01', 'end_date' => '2027-05-31', 'is_active' => false]);
    $review = $this->imports->reviewUpload($this->actor, $this->campus, ($this->report)([$this->row]), $other->id);
    expect($review['semester_id'])->toBe($this->offering->semester_id);
    expect(fn () => $this->imports->reviewUpload($this->actor, $this->campus, ($this->report)([$this->row], campus: 'Other Campus'), $other->id))->toThrow(ValidationException::class);
    expect(fn () => $this->imports->reviewUpload($this->actor, $this->campus, ($this->report)([$this->row], year: '2030-2031'), $other->id))->toThrow(ValidationException::class);
});

it('reports formulas incomplete subjects and unit mismatches at their actual Excel rows and writes nothing', function () {
    $bad = $this->row;
    $bad[1] = 124;
    $bad[9] = 99;
    $bad[13] = '=1+2';
    $bad[16] = '';
    $review = $this->imports->reviewUpload($this->actor, $this->campus, ($this->report)([$this->row, $bad]), $this->offering->semester_id);
    expect($review['error_count'])->toBe(1)->and($review['errors'][0]['row'])->toBe(10)
        ->and(implode(' ', $review['errors'][0]['messages']))->toContain('formula');
    expect(fn () => $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id))->toThrow(ValidationException::class);
    expect(StudentAcademicRecord::count())->toBe(0)->and(StudentSubjectEnrolment::count())->toBe(0);
    $bad = $this->row;
    $bad[9] = 99;
    $review = $this->imports->reviewUpload($this->actor, $this->campus, ($this->report)([$bad]), $this->offering->semester_id);
    expect(implode(' ', $review['errors'][0]['messages']))->toContain('Total Units');
});

it('imports selected known departments only and keeps unknown college codes as errors', function () {
    Department::create(['campus_id' => $this->campus->id, 'name' => 'CCJ', 'code' => 'CCJ', 'is_active' => true]);
    $outside = $this->row;
    $outside[1] = 125;
    $outside[6] = 'CCJ';
    $review = $this->imports->reviewUpload($this->actor, $this->campus, ($this->report)([$this->row, $outside]), $this->offering->semester_id, departmentCodes: ['CCIS']);
    expect($review['total_rows'])->toBe(1)->and($review['skipped_department_rows'])->toBe(1)->and($review['error_count'])->toBe(0);
    expect(fn () => $this->imports->reviewUpload($this->actor, $this->campus, ($this->report)([$outside]), $this->offering->semester_id, departmentCodes: ['CCJ']))->toThrow(HttpException::class);
    $outside[6] = 'MISSPELLED';
    $review = $this->imports->reviewUpload($this->actor, $this->campus, ($this->report)([$this->row, $outside]), $this->offering->semester_id, departmentCodes: ['CCIS']);
    expect($review['error_count'])->toBe(1)->and($review['skipped_department_rows'])->toBe(0);
});

it('keeps reused subject codes separate and suggests only the explicitly selected subject and cohort', function () {
    $other = $this->row;
    $other[1] = 126;
    $other[7] = 'BSCS';
    $other[11] = 'Another Subject With The Same Code';
    $review = $this->imports->reviewUpload($this->actor, $this->campus, ($this->report)([$this->row, $other]), $this->offering->semester_id);
    expect($review['ambiguous_subject_codes'])->toBe(1);
    $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id);
    $records = app(StudentAcademicRecords::class);
    $key = StudentAcademicRecords::subjectKey('CS101', 'Introduction to Computing');
    expect($records->subjectOptions($this->actor, $this->offering))->toHaveCount(2)
        ->and($records->candidates($this->actor, $this->offering, $key)->pluck('student_id')->all())->toBe(['000123'])
        ->and($records->candidates($this->actor, $this->offering, $key, ['BSCS'])->count())->toBe(0)
        ->and($records->cohortOptions($this->actor, $this->offering, 'program', $key))->toBe(['BSIT' => 'BSIT']);
    Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $this->offering, 'pageClass' => EditCourseOffering::class])
        ->mountTableAction('masterListSubjects')
        ->setTableActionData(['subject_key' => $key, 'programs' => ['BSIT'], 'years' => [2], 'exclude' => [], 'confirmed' => true])
        ->callMountedTableAction()->assertHasNoTableActionErrors();
    expect($this->offering->students()->where('student_id', '000123')->exists())->toBeTrue()
        ->and($this->offering->students()->where('student_id', '000126')->exists())->toBeFalse()
        ->and($this->offering->students()->count())->toBe(2);
    expect(fn () => $records->addEvaluators($this->actor, $this->offering, str_repeat('f', 64)))->toThrow(HttpException::class);
});

it('limits program and year options to subject enrolments and resets dependent selections when the subject changes', function () {
    $otherProgram = $this->row;
    $otherProgram[1] = 124;
    $otherProgram[7] = 'BSCS';
    $otherProgram[8] = 3;
    $unrelated = $this->row;
    $unrelated[1] = 125;
    $unrelated[7] = 'UNRELATED';
    $unrelated[8] = 4;
    $unrelated[11] = 'Another Subject With The Same Code';
    $review = $this->imports->reviewUpload($this->actor, $this->campus, ($this->report)([$this->row, $otherProgram, $unrelated]), $this->offering->semester_id);
    $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id);
    $records = app(StudentAcademicRecords::class);
    $key = StudentAcademicRecords::subjectKey('CS101', 'Introduction to Computing');
    $otherKey = StudentAcademicRecords::subjectKey('CS101', 'Another Subject With The Same Code');
    $foreignPeriod = Semester::create(['school_year_id' => $this->offering->semester->school_year_id, 'name' => 'Second Semester', 'start_date' => '2027-01-01', 'end_date' => '2027-05-31']);
    StudentAcademicRecord::create(['user_id' => User::where('student_id', '000123')->sole()->id, 'semester_id' => $foreignPeriod->id, 'program' => 'OLD-PROGRAM', 'reported_year_level' => 9]);
    expect($records->cohortOptions($this->actor, $this->offering, 'program'))->toBe([])
        ->and($records->cohortOptions($this->actor, $this->offering, 'program', $key))->toBe(['BSCS' => 'BSCS', 'BSIT' => 'BSIT'])
        ->and($records->cohortOptions($this->actor, $this->offering, 'reported_year_level', $key))->toBe([2 => 2, 3 => 3])
        ->and($records->cohortOptions($this->actor, $this->offering, 'reported_year_level', $key, ['BSIT']))->toBe([2 => 2])
        ->and($records->cohortOptions($this->actor, $this->offering, 'program', $otherKey))->toBe(['UNRELATED' => 'UNRELATED']);

    $component = Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $this->offering, 'pageClass' => EditCourseOffering::class])
        ->mountTableAction('masterListSubjects')
        ->setTableActionData(['subject_key' => $key, 'programs' => ['BSIT'], 'years' => [2], 'exclude' => [User::where('student_id', '000123')->sole()->id]])
        ->set('mountedTableActionsData.0.subject_key', $otherKey)
        ->assertTableActionDataSet(['programs' => [], 'years' => [], 'exclude' => []]);
    $component->setTableActionData(['years' => [4], 'exclude' => [User::where('student_id', '000125')->sole()->id]])
        ->set('mountedTableActionsData.0.programs', ['UNRELATED'])
        ->assertTableActionDataSet(['years' => [], 'exclude' => []]);
    expect(fn () => $records->cohortOptions($this->actor, $this->offering, 'program', str_repeat('f', 64)))->toThrow(HttpException::class);
});

it('does not suggest matching-subject students outside the assigned department', function () {
    $department = Department::create(['campus_id' => $this->campus->id, 'name' => 'Other College', 'code' => 'OTHER', 'is_active' => true]);
    $foreign = User::factory()->create(['is_student' => true, 'student_department_id' => $department->id]);
    $foreign->campuses()->attach($this->campus);
    $key = StudentAcademicRecords::subjectKey('CS101', 'Introduction to Computing');
    StudentSubjectEnrolment::create(['user_id' => $foreign->id, 'semester_id' => $this->offering->semester_id, 'subject_key' => $key, 'subject_code' => 'CS101', 'description' => 'Introduction to Computing', 'units' => 3]);
    StudentAcademicRecord::create(['user_id' => $foreign->id, 'semester_id' => $this->offering->semester_id, 'program' => 'PRIVATE-PROGRAM', 'reported_year_level' => 8]);
    expect(app(StudentAcademicRecords::class)->subjectOptions($this->actor, $this->offering))->toBe([]);
    expect(fn () => app(StudentAcademicRecords::class)->candidates($this->actor, $this->offering, $key))->toThrow(HttpException::class);
    expect(fn () => app(StudentAcademicRecords::class)->cohortOptions($this->actor, $this->offering, 'program', $key))->toThrow(HttpException::class);
});

it('keeps academic history in the selected campus and rejects a forged foreign-period filter', function () {
    $otherCampus = Campus::create(['name' => 'Foreign Campus', 'slug' => 'foreign-campus', 'is_active' => true]);
    $year = SchoolYear::create(['campus_id' => $otherCampus->id, 'name' => '2026-2027', 'start_date' => '2026-06-01', 'end_date' => '2027-05-31']);
    $period = Semester::create(['school_year_id' => $year->id, 'name' => 'First Semester', 'start_date' => '2026-06-01', 'end_date' => '2026-12-31']);
    $student = $this->offering->students()->first();
    $student->campuses()->attach($otherCampus);
    StudentAcademicRecord::create(['user_id' => $student->id, 'semester_id' => $period->id, 'program' => 'FOREIGN-PRIVATE-PROGRAM']);
    expect(AcademicStudentResource::getEloquentQuery()->findOrFail($student->id)->academicRecords)->toHaveCount(0);
    expect(fn () => AcademicStudentResource::selectedPeriod((object) ['tableFilters' => ['semester' => ['value' => $period->id]]]))->toThrow(ValidationException::class);
});

it('rechecks imported academic records after preview and rejects stale plans', function () {
    $file = ($this->report)([$this->row]);
    $review = $this->imports->reviewUpload($this->actor, $this->campus, $file, $this->offering->semester_id);
    $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id);
    $review = $this->imports->reviewUpload($this->actor, $this->campus, $file, $this->offering->semester_id);
    StudentAcademicRecord::query()->update(['program' => 'CHANGED']);
    expect(fn () => $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id))->toThrow(ValidationException::class);
    expect(StudentAcademicRecord::sole()->program)->toBe('CHANGED');
});

it('shows the report review and enrolled-subject modal through the upload UI', function () {
    Storage::fake('local');
    $file = ($this->report)([$this->row]);
    Storage::disk('local')->put('academic-imports/campus.xlsx', file_get_contents($file->getRealPath()));
    Livewire::test(ListAcademicStudents::class)->callAction('importStudents', data: ['semester_id' => $this->offering->semester_id, 'department_codes' => ['CCIS'], 'file' => UploadedFile::fake()->createWithContent('campus.xlsx', file_get_contents($file->getRealPath()))])->assertHasNoActionErrors();
    $review = finishBackgroundReview();
    Livewire::test(BackgroundProcessing::class)->mountTableAction('view', $review)
        ->assertSee('Campus student master list')->assertSee('headers on Excel row 8')->assertSee('CCIS / BSIT');
    confirmBackgroundReview($review);
    $component = Livewire::test(ListAcademicStudents::class);
    $component->mountTableAction('enrolledSubjects', User::where('student_id', '000123')->sole())
        ->assertSee('Introduction to Computing')->assertSee('Understanding the Self');
});
