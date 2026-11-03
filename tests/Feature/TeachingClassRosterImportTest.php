<?php

use App\Filament\Pages\BackgroundProcessing;
use App\Filament\Resources\CourseOfferingResource\RelationManagers\StudentsRelationManager;
use App\Filament\Resources\TeachingClassResource\Pages\EditTeachingClass;
use App\Models\BackgroundTask;
use App\Models\Department;
use App\Models\FormSubmission;
use App\Models\StudentAcademicRecord;
use App\Models\StudentSectionMembership;
use App\Services\BackgroundTasks;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    ['student' => $this->student, 'offering' => $this->offering] = evaluationFixture(false);
    $this->actor = $this->offering->instructor;
    $this->campus = $this->offering->campus;
    $this->actor->campuses()->attach($this->campus);
    $this->role = Role::findOrCreate('evaluation_coordinator', 'web');
    foreach (['view_course::offering', 'update_course::offering', 'manage_evaluators_course::offering'] as $permission) {
        $this->role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actor->assignRole($this->role);
    $this->student->update(['student_id' => '000123', 'first_name' => 'Juan', 'last_name' => 'Dador', 'middle_name' => 'Santos', 'student_department_id' => $this->actor->instructor_department_id]);
    StudentAcademicRecord::create(['user_id' => $this->student->id, 'semester_id' => $this->offering->semester_id, 'program' => 'BSIT', 'reported_year_level' => 3, 'reported_sex' => 'Male']);
    StudentSectionMembership::create(['user_id' => $this->student->id, 'semester_id' => $this->offering->semester_id, 'department_id' => $this->student->student_department_id, 'section' => 'BSIT 2B']);
    $this->offering->update(['section' => 'BSIT 3A']);
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    Storage::fake('local');
    Queue::fake();
    $this->tasks = app(BackgroundTasks::class);
    $this->csv = "Section,ID No.,Lastname,Firstname,Middlename,Sex\r\nBSIT 3A,000123,DADOR,JUAN,SANTOS,M\r\n";
    $this->review = function (?string $csv = null) {
        $task = $this->tasks->upload($this->actor, $this->campus, UploadedFile::fake()->createWithContent('section.csv', $csv ?? $this->csv),
            'roster-review', 'Review section roster', ['offering_id' => $this->offering->id]);
        $this->tasks->run($task->id);
        $task->refresh();
        expect($task->status)->toBe('completed', $task->error ?? '');

        return $task;
    };
});

it('reviews identities without changing the class and confirms an additive period roster in the worker', function () {
    $review = ($this->review)();
    expect($review->result['review'])->toMatchArray(['matched_students' => 1, 'error_count' => 0]);
    expect($this->offering->students()->count())->toBe(0)->and(StudentSectionMembership::count())->toBe(1);
    $this->get(route('background.download', $review))->assertOk()->assertDownload('section-roster-validation.csv');
    Livewire::test(BackgroundProcessing::class)->mountTableAction('view', $review)->assertSee('000123')->assertSee('DADOR');
    $import = $this->tasks->confirm($review, $this->actor, $this->campus);
    expect($this->offering->students()->count())->toBe(0);
    $this->tasks->run($import->id);
    expect($import->fresh()->status)->toBe('completed', $import->fresh()->error ?? '');
    expect($this->offering->students()->pluck('users.id')->all())->toBe([$this->student->id])
        ->and(StudentSectionMembership::where('user_id', $this->student->id)->pluck('section')->all())->toBe(['BSIT 2B', 'BSIT 3A']);
    $again = $this->tasks->confirm($review->fresh(), $this->actor, $this->campus);
    expect($again->id)->toBe($import->id);
    $this->tasks->run($again->id);
    expect($this->offering->students()->count())->toBe(1)->and(StudentSectionMembership::count())->toBe(2);
});

it('accepts a section heading above the campus headers and preserves leading zeros and real CSV quoting', function () {
    $this->student->update(['last_name' => 'Dador, Jr.']);
    $review = ($this->review)("\xEF\xBB\xBFBSIT - 3A\r\nID No.\tLastname\tFirstname\tMiddlename\tSex\r\n000123\tDador, Jr.\tJuan\tSantos\tMale\r\n");
    expect($review->result['review']['error_count'])->toBe(0)
        ->and($review->result['review']['details'][0]['student_id'])->toBe('000123')
        ->and($review->result['review']['sections'])->toBe(['BSIT 3A' => 1]);
});

it('reads a formatted Excel roster without dropping leading zeros', function () {
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->fromArray([['BSIT - 3A'], ['ID No.', 'Lastname', 'Firstname', 'Middlename', 'Sex'], ['000123', 'Dador', 'Juan', 'Santos', 'M']]);
    $sheet->setCellValueExplicit('A3', '000123', DataType::TYPE_STRING);
    $path = tempnam(sys_get_temp_dir(), 'roster-xlsx-');
    try {
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $file = UploadedFile::fake()->createWithContent('section.xlsx', file_get_contents($path));
    } finally {
        $book->disconnectWorksheets();
        unlink($path);
    }
    $review = $this->tasks->upload($this->actor, $this->campus, $file, 'roster-review', 'Review Excel roster', ['offering_id' => $this->offering->id]);
    $this->tasks->run($review->id);
    expect($review->fresh()->status)->toBe('completed', $review->fresh()->error ?? '')
        ->and($review->fresh()->result['review']['error_count'])->toBe(0)
        ->and($review->fresh()->result['review']['details'][0]['student_id'])->toBe('000123');
});

it('preserves existing evaluation submissions and roster timestamps when reassigning a listed student', function () {
    $enrolledAt = now()->subMonth()->startOfSecond();
    $this->offering->students()->attach($this->student, ['enrolled_at' => $enrolledAt]);
    $response = FormSubmission::create(['course_offering_id' => $this->offering->id, 'form_id' => $this->offering->form_id,
        'user_id' => $this->student->id, 'data' => ['teaching_quality' => 5, '_overall_comment' => 'Preserve this evaluation.']]);
    $review = ($this->review)();
    $import = $this->tasks->confirm($review, $this->actor, $this->campus);
    $this->tasks->run($import->id);
    expect($import->fresh()->status)->toBe('completed', $import->fresh()->error ?? '')
        ->and($import->fresh()->result['added'])->toBe(0)
        ->and($response->fresh()->data)->toBe(['teaching_quality' => 5, '_overall_comment' => 'Preserve this evaluation.'])
        ->and((string) $this->offering->students()->sole()->pivot->enrolled_at)->toBe($enrolledAt->toDateTimeString());
});

it('blocks the whole roster for unknown IDs or mismatched full names and sex', function () {
    $csv = $this->csv."BSIT 3A,NOT-FOUND,Dador,Juan,Santos,M\nBSIT 3A,000123,Wrong,Juan,Santos,F\n";
    $review = ($this->review)($csv);
    expect($review->result['review']['error_count'])->toBe(2);
    expect(implode(' ', $review->result['review']['details'][2]['messages']))->toContain('Lastname')->toContain('Sex');
    expect(fn () => $this->tasks->confirm($review, $this->actor, $this->campus))->toThrow(HttpException::class);
    expect($this->offering->students()->count())->toBe(0)->and(StudentSectionMembership::count())->toBe(1);
    Livewire::test(BackgroundProcessing::class)->assertTableActionHidden('confirmImport', $review);
});

it('rejects ID-only files instead of silently bypassing identity validation', function () {
    $task = $this->tasks->upload($this->actor, $this->campus, UploadedFile::fake()->createWithContent('ids.csv', "student_id\n000123\n"), 'roster-review', 'Review roster', ['offering_id' => $this->offering->id]);
    $this->tasks->run($task->id);
    expect($task->fresh()->status)->toBe('failed')->and($task->fresh()->error)->toContain('Lastname');
    expect($this->offering->students()->count())->toBe(0);
});

it('keeps the on-screen review compact while retaining validation errors after row one hundred in the full download', function () {
    $csv = "Section,ID No.,Lastname,Firstname,Middlename,Sex\n".str_repeat("BSIT 3A,000123,Dador,Juan,Santos,M\n", 101)."BSIT 3A,UNKNOWN-TAIL,Unknown,Unknown,,\n";
    $review = ($this->review)($csv);
    expect($review->result['review']['details'])->toHaveCount(100)
        ->and($review->result['review']['error_count'])->toBe(1)
        ->and(Storage::disk('local')->get($review->file_path))->toContain('UNKNOWN-TAIL');
    expect(fn () => $this->tasks->confirm($review, $this->actor, $this->campus))->toThrow(HttpException::class);
});

it('keeps irregular students in several sections and assigns repeated IDs once', function () {
    $review = ($this->review)($this->csv."BSIT 3B,000123,Dador,Juan,Santos,M\nBSIT 3A,000123,Dador,Juan,Santos,M\n");
    expect($review->result['review'])->toMatchArray(['matched_students' => 1, 'duplicate_rows' => 1, 'error_count' => 0]);
    $import = $this->tasks->confirm($review, $this->actor, $this->campus);
    $this->tasks->run($import->id);
    expect($import->fresh()->status)->toBe('completed', $import->fresh()->error ?? '')
        ->and($this->offering->students()->count())->toBe(1)->and(StudentSectionMembership::where('user_id', $this->student->id)->count())->toBe(3);
});

it('rejects students outside the actors assigned department without disclosing their masterlist identity', function () {
    $other = Department::create(['campus_id' => $this->campus->id, 'name' => 'CCJ', 'code' => 'CCJ', 'is_active' => true]);
    $this->student->update(['student_department_id' => $other->id]);
    $review = ($this->review)();
    expect($review->result['review']['error_count'])->toBe(1)
        ->and(implode(' ', $review->result['review']['details'][0]['messages']))->toContain('authorized campus and departments');
});

it('requires a student masterlist record for the teaching class academic period', function () {
    StudentAcademicRecord::query()->delete();
    StudentSectionMembership::query()->delete();
    $review = ($this->review)();
    expect(implode(' ', $review->result['review']['details'][0]['messages']))->toContain('academic period');
});

it('rechecks names and class details before writing an approved roster', function (string $changed) {
    $review = ($this->review)();
    $import = $this->tasks->confirm($review, $this->actor, $this->campus);
    $changed === 'student' ? $this->student->update(['last_name' => 'Changed']) : $this->offering->update(['section' => 'BSIT 4B']);
    $this->tasks->run($import->id);
    expect($import->fresh()->status)->toBe('failed')->and($this->offering->students()->count())->toBe(0)->and(StudentSectionMembership::count())->toBe(1);
})->with(['student', 'class']);

it('rechecks revoked permissions before saving section memberships or evaluator assignments', function () {
    $review = ($this->review)();
    $import = $this->tasks->confirm($review, $this->actor, $this->campus);
    $this->role->revokePermissionTo('manage_evaluators_course::offering');
    $this->tasks->run($import->id);
    expect($import->fresh()->status)->toBe('failed')->and($this->offering->students()->count())->toBe(0)->and(StudentSectionMembership::count())->toBe(1);
});

it('keeps section uploads out of the teaching class and offers saved section selection instead', function () {
    Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $this->offering, 'pageClass' => EditTeachingClass::class])
        ->assertTableActionDoesNotExist('bulkAttachCsv')->assertTableActionDoesNotExist('downloadSectionRoster')
        ->assertTableActionVisible('assignSections');
    expect(BackgroundTask::where('kind', 'roster-review')->count())->toBe(0)->and($this->offering->students()->count())->toBe(0);
});
