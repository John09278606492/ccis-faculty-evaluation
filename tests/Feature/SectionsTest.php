<?php

use App\Filament\Pages\BackgroundProcessing;
use App\Filament\Resources\CourseOfferingResource\Pages\EditCourseOffering;
use App\Filament\Resources\SectionResource;
use App\Filament\Resources\SectionResource\Pages\CreateSection;
use App\Filament\Resources\SectionResource\Pages\EditSection;
use App\Filament\Resources\SectionResource\Pages\ListSections;
use App\Filament\Resources\SectionResource\RelationManagers\StudentsRelationManager;
use App\Filament\Resources\TeachingClassResource\Pages\EditTeachingClass;
use App\Models\AcademicSection;
use App\Models\BackgroundTask;
use App\Models\Campus;
use App\Models\Department;
use App\Models\FormSubmission;
use App\Models\Semester;
use App\Models\StudentAcademicRecord;
use App\Models\StudentSectionMembership;
use App\Services\AcademicSections;
use App\Services\AcademicStudents;
use App\Services\BackgroundTasks;
use Filament\Facades\Filament;
use Filament\Tables\Actions\Action;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    ['student' => $this->student, 'offering' => $this->offering] = evaluationFixture(false);
    $this->actor = $this->offering->instructor;
    $this->campus = $this->offering->campus;
    $this->department = $this->offering->course->department;
    $this->actor->campuses()->attach($this->campus);
    $this->role = Role::findOrCreate('section_coordinator', 'web');
    foreach (['view_academic_students', 'manage_academic_students', 'import_academic_students', 'manage_evaluators_course::offering', 'update_course::offering', 'view_course::offering'] as $permission) {
        $this->role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actor->assignRole($this->role);
    $this->student->update(['student_id' => '000123', 'first_name' => 'Juan', 'last_name' => 'Dador', 'middle_name' => 'Santos', 'student_department_id' => $this->department->id]);
    StudentAcademicRecord::create(['user_id' => $this->student->id, 'semester_id' => $this->offering->semester_id, 'program' => 'BSIT', 'reported_sex' => 'M']);
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    Storage::fake('local');
    Queue::fake();
    $this->sections = app(AcademicSections::class);
    $this->tasks = app(BackgroundTasks::class);
    $this->section = $this->sections->save($this->actor, $this->campus, ['name' => 'BSIT - 3A', 'department_id' => $this->department->id, 'semester_id' => $this->offering->semester_id, 'is_active' => true]);
    $this->csv = "ID No.,Lastname,Firstname,Middlename\n000123,Dador,Juan,Santos\n";
    $this->review = function (?string $csv = null) {
        $task = $this->tasks->upload($this->actor, $this->campus, UploadedFile::fake()->createWithContent('students.csv', $csv ?? $this->csv), 'section-review', 'Review section students', ['section_id' => $this->section->id]);
        $this->tasks->run($task->id);
        $task->refresh();
        expect($task->status)->toBe('completed', $task->error ?? '');

        return $task;
    };
});

it('has a standalone section resource with empty sections, visible period filters and a scoped example download', function () {
    expect($this->section->name)->toBe('BSIT 3A');
    Livewire::test(ListSections::class)->assertSuccessful()->assertCanSeeTableRecords([$this->section])->assertSee('Academic Period');
    $download = $this->get(route('sections.template', $this->section))->assertOk()->assertDownload('bsit-3a-student-list.csv');
    $lines = preg_split('/\r\n|\n|\r/', $download->streamedContent());
    expect(str_getcsv($lines[1]))->toBe(['ID No.']);
    Livewire::test(CreateSection::class)->fillForm(['name' => 'BSIT 3B', 'semester_id' => $this->offering->semester_id, 'department_id' => $this->department->id, 'is_active' => true])->call('create')->assertHasNoFormErrors();
    $created = AcademicSection::where('name', 'BSIT 3B')->sole();
    expect($created->students()->count())->toBe(0);
    Livewire::test(EditSection::class, ['record' => $created->id])->assertSuccessful();
});

it('uploads and confirms section students first and assigns that saved section to a teaching class separately', function () {
    $other = $this->sections->save($this->actor, $this->campus, ['name' => 'BSIT 2B', 'department_id' => $this->department->id, 'semester_id' => $this->offering->semester_id, 'is_active' => true]);
    $this->sections->add($this->actor, $this->campus, $other, [$this->student->id]);
    Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $this->section, 'pageClass' => EditSection::class])
        ->assertTableActionVisible('uploadStudents')->callTableAction('uploadStudents', data: ['file' => UploadedFile::fake()->createWithContent('students.csv', $this->csv)])->assertHasNoTableActionErrors();
    $review = BackgroundTask::where('kind', 'section-review')->sole();
    expect($this->section->students()->count())->toBe(0);
    $this->tasks->run($review->id);
    $review->refresh();
    expect($review->status)->toBe('completed', $review->error ?? '')->and($review->result['review']['error_count'])->toBe(0);
    Livewire::test(BackgroundProcessing::class)->mountTableAction('view', $review)->assertSee('Select the saved section separately in Teaching Classes.');
    $import = $this->tasks->confirm($review, $this->actor, $this->campus);
    $this->tasks->run($import->id);
    expect($import->fresh()->status)->toBe('completed', $import->fresh()->error ?? '')
        ->and($this->section->students()->pluck('users.id')->all())->toBe([$this->student->id])
        ->and($other->students()->count())->toBe(1)->and($this->offering->students()->count())->toBe(0);
    $group = $this->section->department_id.'~'.$this->section->semester_id.'~'.rawurlencode($this->section->name);
    app(AcademicStudents::class)->assign($this->actor, $this->offering, [$group]);
    expect($this->offering->students()->pluck('users.id')->all())->toBe([$this->student->id]);
});

it('accepts an ID-only section list and shows masterlist names for review', function () {
    $review = ($this->review)("BSIT - 3A\nID No.\n000123\nMISSING\n");
    $details = $review->result['review']['details'];
    expect($review->result['review']['error_count'])->toBe(1)
        ->and([$details[0]['last_name'], $details[0]['first_name'], $details[0]['middle_name']])->toBe(['Dador', 'Juan', 'Santos'])
        ->and($details[0]['messages'])->toBe([])
        ->and(implode(' ', $details[1]['messages']))->toContain('not found in the masterlist');

    $review = ($this->review)("ID No.\n000123\n");
    $import = $this->tasks->confirm($review, $this->actor, $this->campus);
    $this->tasks->run($import->id);
    expect($import->fresh()->status)->toBe('completed', $import->fresh()->error ?? '')
        ->and($this->section->students()->pluck('users.id')->all())->toBe([$this->student->id]);
});

it('blocks wrong sections, unknown IDs and mismatched names without adding even the valid rows', function () {
    $review = ($this->review)("Section,ID No.,Lastname,Firstname,Middlename,Sex\nBSIT 3A,000123,Dador,Juan,Santos,M\nBSIT 3B,000123,Dador,Juan,Santos,M\nBSIT 3A,MISSING,Unknown,Unknown,,\nBSIT 3A,000123,Wrong,Juan,Santos,M\n");
    expect($review->result['review']['error_count'])->toBe(3);
    expect(fn () => $this->tasks->confirm($review, $this->actor, $this->campus))->toThrow(HttpException::class);
    expect($this->section->students()->count())->toBe(0)->and($this->offering->students()->count())->toBe(0);
});

it('keeps section assignment available alongside enrolled matching and supports individual exceptions without losing responses', function () {
    $this->sections->add($this->actor, $this->campus, $this->section, [$this->student->id]);
    $extra = app(AcademicStudents::class)->save($this->actor, $this->campus, [
        'student_id' => 'IRREGULAR-001', 'first_name' => 'Irregular', 'last_name' => 'Student',
        'student_department_id' => $this->department->id, 'semester_id' => $this->offering->semester_id, 'sections' => [],
    ]);
    $response = FormSubmission::create(['form_id' => $this->offering->form_id, 'course_offering_id' => $this->offering->id,
        'user_id' => $this->student->id, 'data' => ['teaching_quality' => 5]]);
    $group = $this->section->department_id.'~'.$this->section->semester_id.'~'.rawurlencode($this->section->name);
    $this->offering->update(['evaluator_selection' => ['sections' => [$group], 'include' => [$extra->id], 'exclude' => [], 'mode' => 'add']]);
    $component = Livewire::test(App\Filament\Resources\CourseOfferingResource\RelationManagers\StudentsRelationManager::class, [
        'ownerRecord' => $this->offering, 'pageClass' => EditCourseOffering::class,
    ]);
    $actions = $component->instance()->getTable()->getHeaderActions();
    expect(collect($actions)->filter(fn ($action) => $action instanceof Action)->map->getName()->all())->toContain('assignSections', 'matchEnrolledStudents');
    $component->mountTableAction('assignSections')->assertTableActionDataSet(['include' => [], 'use_exceptions' => false])->assertSee('Teaching class')
        ->setTableActionData(['sections' => [], 'use_exceptions' => true, 'include' => [$extra->id], 'exclude' => []])
        ->callMountedTableAction()->assertHasTableActionErrors(['sections' => 'required']);
    expect($this->offering->students()->count())->toBe(0);
    $component->setTableActionData(['sections' => [$group], 'use_exceptions' => true, 'include' => [$extra->id], 'exclude' => [$this->student->id]])
        ->callMountedTableAction()->assertHasNoTableActionErrors();
    expect($this->offering->students()->pluck('users.id')->all())->toBe([$extra->id])
        ->and($response->fresh()->data)->toBe(['teaching_quality' => 5]);
    $component->mountTableAction('assignSections')->assertTableActionDataSet(['use_exceptions' => true])
        ->setTableActionData(['sections' => [$group], 'use_exceptions' => false, 'include' => [], 'exclude' => []])
        ->callMountedTableAction()->assertHasNoTableActionErrors();
    expect($this->offering->students()->pluck('users.id')->all())->toBe([$this->student->id])
        ->and($response->fresh()->data)->toBe(['teaching_quality' => 5]);
});

it('blocks empty sections and never restores unverified bulk selections as individual exceptions', function () {
    $group = $this->section->department_id.'~'.$this->section->semester_id.'~'.rawurlencode($this->section->name);
    $saved = ['sections' => [$group], 'include' => range(10001, 10179), 'exclude' => [], 'mode' => 'add'];
    $this->offering->update(['evaluator_selection' => $saved]);
    $component = Livewire::test(App\Filament\Resources\CourseOfferingResource\RelationManagers\StudentsRelationManager::class, [
        'ownerRecord' => $this->offering, 'pageClass' => EditTeachingClass::class,
    ])->assertTableActionDoesNotExist('masterListSubjects')->assertTableActionDoesNotExist('attach')->assertTableActionDoesNotExist('bulkAttach')
        ->mountTableAction('assignSections')->assertTableActionDataSet(['sections' => [$group], 'include' => [], 'exclude' => [], 'use_exceptions' => false])
        ->assertSee('0 students')->assertSee('Manage Sections')
        ->callMountedTableAction()->assertHasTableActionErrors(['sections']);
    expect($this->offering->students()->count())->toBe(0)->and($this->offering->fresh()->evaluator_selection)->toBe($saved);
    $component->setTableActionData(['include' => $saved['include']])->set('mountedTableActionsData.0.use_exceptions', true)
        ->assertTableActionDataSet(['include' => [], 'exclude' => []])
        ->set('mountedTableActionsData.0.use_exceptions', false);
    expect(fn () => app(AcademicStudents::class)->assignSections($this->actor, $this->offering, [$group], [$this->student->id]))->toThrow(ValidationException::class);
    expect($this->offering->students()->count())->toBe(0);
    $this->sections->add($this->actor, $this->campus, $this->section, [$this->student->id]);
    $component->callMountedTableAction()->assertHasNoTableActionErrors();
    expect($this->offering->students()->pluck('users.id')->all())->toBe([$this->student->id])
        ->and($this->offering->fresh()->evaluator_selection['include'])->toBe([])
        ->and($this->offering->fresh()->evaluator_selection['source'])->toBe('sections_v1');
});

it('rechecks a section emptied after preview without changing the assigned students or their responses', function () {
    $this->sections->add($this->actor, $this->campus, $this->section, [$this->student->id]);
    $this->offering->students()->attach($this->student);
    $response = FormSubmission::create(['form_id' => $this->offering->form_id, 'course_offering_id' => $this->offering->id,
        'user_id' => $this->student->id, 'data' => ['teaching_quality' => 5]]);
    $group = $this->section->department_id.'~'.$this->section->semester_id.'~'.rawurlencode($this->section->name);
    expect(app(AcademicStudents::class)->sectionAssignmentPreview($this->actor, $this->offering, [$group])['total'])->toBe(1);
    $this->sections->remove($this->actor, $this->campus, $this->section, [$this->student->id]);
    expect(fn () => app(AcademicStudents::class)->assignSections($this->actor, $this->offering, [$group]))->toThrow(ValidationException::class);
    expect($this->offering->students()->pluck('users.id')->all())->toBe([$this->student->id])->and($response->fresh()->data)->toBe(['teaching_quality' => 5]);
});

it('keeps sections and their URLs limited to assigned departments and campus', function () {
    $otherDepartment = Department::create(['campus_id' => $this->campus->id, 'name' => 'CTAS', 'code' => 'CTAS', 'is_active' => true]);
    $hidden = AcademicSection::create(['department_id' => $otherDepartment->id, 'semester_id' => $this->offering->semester_id, 'name' => 'CTAS 3A', 'is_active' => true]);
    Livewire::test(ListSections::class)->assertCanSeeTableRecords([$this->section])->assertCanNotSeeTableRecords([$hidden]);
    $this->get(route('sections.template', $hidden))->assertNotFound();
    expect(fn () => $this->sections->section($this->actor, $this->campus, $hidden->id, true))->toThrow(ModelNotFoundException::class);
    $foreignCampus = Campus::create(['name' => 'Other Campus', 'slug' => 'other-campus', 'is_active' => true]);
    Filament::setTenant($foreignCampus, isQuiet: true);
    expect(SectionResource::canViewAny())->toBeFalse();
});

it('rejects a student from a different section department even for a campus-wide administrator', function () {
    $other = Department::create(['campus_id' => $this->campus->id, 'name' => 'CTAS', 'code' => 'CTAS', 'is_active' => true]);
    $this->student->update(['student_department_id' => $other->id]);
    $this->actor->assignRole('super_admin');
    $review = ($this->review)();
    expect($review->result['review']['error_count'])->toBe(1)
        ->and(implode(' ', $review->result['review']['details'][0]['messages']))->toContain('different department');
});

it('rechecks revoked import permission and a changed section after review', function (string $change) {
    $review = ($this->review)();
    $import = $this->tasks->confirm($review, $this->actor, $this->campus);
    if ($change === 'permission') {
        $this->role->revokePermissionTo('import_academic_students');
    } else {
        $this->section->update(['name' => 'BSIT 3C']);
    }
    $this->tasks->run($import->id);
    expect($import->fresh()->status)->toBe('failed')->and(StudentSectionMembership::where('academic_section_id', $this->section->id)->count())->toBe(0);
})->with(['permission', 'section']);

it('preserves sections across a repeated masterlist import and never removes teaching-class evaluations when section membership is removed', function () {
    $this->sections->add($this->actor, $this->campus, $this->section, [$this->student->id]);
    app(AcademicStudents::class)->import($this->actor, $this->campus, [['student_id' => '000123', 'first_name' => 'Juan', 'last_name' => 'Dador', 'middle_name' => 'Santos', 'department_code' => 'CCIS']], $this->section->semester_id);
    expect($this->section->students()->count())->toBe(1);
    $this->offering->students()->attach($this->student);
    $response = FormSubmission::create(['form_id' => $this->offering->form_id, 'course_offering_id' => $this->offering->id, 'user_id' => $this->student->id, 'data' => ['teaching_quality' => 5]]);
    $this->sections->remove($this->actor, $this->campus, $this->section, [$this->student->id]);
    expect($this->section->students()->count())->toBe(0)->and($this->offering->students()->count())->toBe(1)->and($response->fresh()->data)->toBe(['teaching_quality' => 5]);
});

it('renames a populated section and its selection references without replacing class evaluators', function () {
    $this->sections->add($this->actor, $this->campus, $this->section, [$this->student->id]);
    $oldGroup = $this->section->department_id.'~'.$this->section->semester_id.'~'.rawurlencode($this->section->name);
    app(AcademicStudents::class)->assign($this->actor, $this->offering, [$oldGroup]);
    $this->sections->save($this->actor, $this->campus, [...$this->section->only(['department_id', 'semester_id', 'is_active']), 'name' => 'BSIT 3B'], $this->section);
    expect($this->offering->fresh()->evaluator_selection['sections'])->toBe([$this->section->department_id.'~'.$this->section->semester_id.'~BSIT%203B'])
        ->and($this->section->students()->count())->toBe(1)->and($this->offering->students()->count())->toBe(1)
        ->and(StudentSectionMembership::where('academic_section_id', $this->section->id)->sole()->section)->toBe('BSIT 3B');
});

it('archives a section without changing existing teaching-class lists and prevents new uploads', function () {
    $this->sections->add($this->actor, $this->campus, $this->section, [$this->student->id]);
    $this->offering->students()->attach($this->student);
    $this->section->update(['is_active' => false]);
    expect(app(AcademicStudents::class)->sectionOptions($this->actor, $this->campus, $this->section->semester_id))->toBe([]);
    Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $this->section->fresh(), 'pageClass' => EditSection::class])->assertTableActionHidden('uploadStudents');
    expect($this->section->students()->count())->toBe(1)->and($this->offering->students()->count())->toBe(1);
});

it('requires unique section names per department and period and keeps populated sections in their original period', function () {
    expect(fn () => $this->sections->save($this->actor, $this->campus, $this->section->only(['name', 'department_id', 'semester_id', 'is_active'])))->toThrow(ValidationException::class);
    $semester = Semester::create(['school_year_id' => $this->offering->semester->school_year_id, 'name' => 'Second Semester', 'start_date' => '2027-01-01', 'end_date' => '2027-05-31', 'is_active' => true]);
    $this->sections->add($this->actor, $this->campus, $this->section, [$this->student->id]);
    expect(fn () => $this->sections->save($this->actor, $this->campus, [...$this->section->only(['name', 'department_id', 'is_active']), 'semester_id' => $semester->id], $this->section))->toThrow(ValidationException::class);
    $other = $this->sections->save($this->actor, $this->campus, [...$this->section->only(['name', 'department_id', 'is_active']), 'semester_id' => $semester->id]);
    expect($other->students()->count())->toBe(0)->and($this->section->students()->count())->toBe(1);
});

it('lets a viewer read a section without exposing upload or roster-edit actions', function () {
    $this->role->revokePermissionTo(['manage_academic_students', 'import_academic_students']);
    $this->actor->unsetRelation('roles')->unsetRelation('permissions');
    Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $this->section, 'pageClass' => EditSection::class])
        ->assertTableActionHidden('uploadStudents')->assertTableActionHidden('addStudents');
    expect(fn () => $this->sections->add($this->actor, $this->campus, $this->section, [$this->student->id]))->toThrow(HttpException::class);
});

it('deletes only sections without students', function () {
    $empty = $this->sections->save($this->actor, $this->campus, ['name' => 'BSIT 3Z', 'department_id' => $this->department->id, 'semester_id' => $this->offering->semester_id, 'is_active' => true]);
    $other = $this->sections->save($this->actor, $this->campus, ['name' => 'BSIT 3Y', 'department_id' => $this->department->id, 'semester_id' => $this->offering->semester_id, 'is_active' => true]);
    StudentSectionMembership::create(['academic_section_id' => $this->section->id, 'department_id' => $this->department->id, 'semester_id' => $this->section->semester_id, 'section' => $this->section->name, 'user_id' => $this->student->id]);

    Livewire::test(ListSections::class)->assertTableActionVisible('delete', $empty)->assertTableActionHidden('delete', $this->section)
        ->callTableAction('delete', $empty);
    expect(AcademicSection::find($empty->id))->toBeNull();
    Livewire::test(ListSections::class)->callTableBulkAction('deleteEmpty', [$other, $this->section]);
    expect(AcademicSection::find($other->id))->toBeNull()->and(AcademicSection::find($this->section->id))->not->toBeNull();
    expect(fn () => $this->sections->delete($this->actor, $this->campus, $this->section))->toThrow(ValidationException::class);
});

it('uploads many section lists at once, matching each file to the section named in its first row', function () {
    $crim = $this->sections->save($this->actor, $this->campus, ['name' => 'BSCRM 2B', 'department_id' => $this->department->id, 'semester_id' => $this->offering->semester_id, 'is_active' => true]);
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray([['BS CRIM 2B'], ['ID No.']]);
    $book->getActiveSheet()->setCellValueExplicit('A3', '000123', DataType::TYPE_STRING);
    $xlsx = tempnam(sys_get_temp_dir(), 'roster');
    (new Xlsx($book))->save($xlsx);

    Livewire::test(ListSections::class)->assertActionVisible('bulkUploadStudents')->callAction('bulkUploadStudents', data: [
        'semester_id' => $this->offering->semester_id,
        'files' => [
            UploadedFile::fake()->createWithContent('3a.csv', "BS IT - 3A\nID No.\n000123\n"),
            UploadedFile::fake()->createWithContent('crim.xlsx', file_get_contents($xlsx)),
            UploadedFile::fake()->createWithContent('unknown.csv', "BSIT 9Z\nID No.\n000123\n"),
            UploadedFile::fake()->createWithContent('untitled.csv', "ID No.\n000123\n"),
        ],
    ])->assertHasNoActionErrors()->assertNotified('2 of 4 lists queued for review');

    $reviews = BackgroundTask::where('kind', 'section-review')->get();
    expect($reviews->map(fn ($task) => $task->payload['section_id'])->sort()->values()->all())->toBe([$this->section->id, $crim->id]);
    foreach ($reviews as $review) {
        $this->tasks->run($review->id);
        expect($review->fresh()->status)->toBe('completed', $review->fresh()->error ?? '')
            ->and($review->fresh()->result['review']['error_count'])->toBe(0);
    }
    expect($this->section->students()->count())->toBe(0);
});

it('matches section titles despite spacing and dropped vowels and prefers the students department on a tie', function () {
    $other = Department::create(['campus_id' => $this->campus->id, 'name' => 'CCIS', 'code' => 'CCIS2', 'is_active' => true]);
    $this->actor->assignRole('super_admin');
    AcademicSection::create(['department_id' => $other->id, 'semester_id' => $this->offering->semester_id, 'name' => 'BSCRM 2C', 'is_active' => true]);
    $mine = $this->sections->save($this->actor, $this->campus, ['name' => 'BSCRM 2C', 'department_id' => $this->department->id, 'semester_id' => $this->offering->semester_id, 'is_active' => true]);
    expect(AcademicSections::sameName('BS CRIM 2B', 'BSCRM 2B'))->toBeTrue()
        ->and(AcademicSections::sameName('BSCRIM 2B', 'BSCRIM 2C'))->toBeFalse()
        ->and($this->sections->matchTitle($this->actor, $this->campus, $this->offering->semester_id, 'BS CRIM 2C', ['000123'])['section']?->id)->toBe($mine->id)
        ->and($this->sections->matchTitle($this->actor, $this->campus, $this->offering->semester_id, 'BSCRIM 4F', [])['problem'])->toContain('No active section');
});

it('accepts a section list whose title spells the section differently', function () {
    $review = ($this->review)("BS-IT 3A\nID No.\n000123\n");
    expect($review->result['review']['error_count'])->toBe(0);
});
