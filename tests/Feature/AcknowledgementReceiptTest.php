<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MyAcknowledgements;
use App\Filament\Resources\AcknowledgementFormResource;
use App\Filament\Resources\AcknowledgementFormResource\Pages\CreateAcknowledgementForm;
use App\Filament\Resources\AcknowledgementFormResource\Pages\EditAcknowledgementForm;
use App\Filament\Resources\AcknowledgementFormResource\Pages\ListAcknowledgementForms;
use App\Filament\Resources\AcknowledgementFormResource\Pages\PreviewAcknowledgementForm;
use App\Filament\Resources\AcknowledgementFormResource\RelationManagers\RecipientsRelationManager;
use App\Models\AcknowledgementForm;
use App\Filament\Widgets\StudentAcknowledgementsWidget;
use App\Models\AcknowledgementRecipient;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Quiz;
use App\Models\StudentSectionMembership;
use App\Models\User;
use App\Services\AcknowledgementForms;
use App\Services\StudentTodo;
use App\Support\SignatureStrokes;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function acknowledgementFixture(): array
{
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $student->update(['is_student' => true, 'first_name' => 'Ana', 'middle_name' => 'Reyes', 'last_name' => 'Cruz']);
    $student->givePermissionTo(Permission::findOrCreate('page_MyAcknowledgements', 'web'));
    $instructor = $offering->instructor;
    foreach (['view_any', 'view', 'create', 'update', 'delete'] as $prefix) {
        $instructor->givePermissionTo(Permission::findOrCreate("{$prefix}_acknowledgement::form", 'web'));
    }
    $instructor->campuses()->syncWithoutDetaching([$offering->campus->id]);
    $student->campuses()->syncWithoutDetaching([$offering->campus->id]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($offering->campus, isQuiet: true);
    $form = AcknowledgementForm::create([
        'course_offering_id' => $offering->id, 'document_title' => "Learner's Acknowledgement Receipt", 'document_received' => 'Web Development Syllabus',
        'date_received' => '2026-08-17', 'status' => 'active', 'year_section' => 'BSIT 3A', 'college_name' => 'College', 'department_name' => 'Department',
        'term_label' => 'First Semester, A.Y. 2026 - 2027', 'instructor_name' => 'J. INSTRUCTOR', 'instructor_designation' => 'Instructor',
    ]);

    return compact('student', 'offering', 'instructor', 'form');
}

function signatureStrokes(): string
{
    return json_encode([[[10, 20], [60, 80], [120, 30], [200, 90]], [[220, 40], [300, 60]]]);
}

it('builds a safe compact path from pen strokes and rejects anything else', function () {
    $path = SignatureStrokes::pathFrom(signatureStrokes());

    expect($path)->toMatch('/^[MQL0-9 ]+$/')
        ->and((string) SignatureStrokes::svg($path, 'Ana <b>'))->toContain('viewBox="0 0 1700 480"')->toContain('Ana &lt;b&gt;')
        ->and((string) SignatureStrokes::svg('M0 0"/><script>alert(1)</script>'))->toBe('');

    foreach ([null, '', 'not json', '[]', '[[]]', '[[["<script>",1]]]', '[[[1,1],[2,2]]]', json_encode([[[0, 0]]])] as $bad) {
        expect(SignatureStrokes::pathFrom($bad))->toBeNull();
    }
    expect(SignatureStrokes::pathFrom(json_encode(array_fill(0, 201, [[0, 0], [50, 50]]))))->toBeNull();
});

it('lists the enrolled class and keeps signed rows when the class changes', function () {
    extract(acknowledgementFixture());
    $service = app(AcknowledgementForms::class);
    $classmate = User::factory()->create(['is_student' => true, 'name' => 'Ben Santos']);
    $offering->students()->attach($classmate);

    $service->syncRoster($form);
    $service->syncRoster($form);
    expect($form->recipients()->count())->toBe(2)
        ->and($form->recipients()->where('user_id', $student->id)->value('student_name'))->toBe('CRUZ, ANA, R.')
        ->and($form->recipients()->where('user_id', $classmate->id)->value('student_name'))->toBe('BEN SANTOS');

    $service->signAsStudent($form, $student, signatureStrokes(), '127.0.0.1');
    $offering->students()->detach([$student->id, $classmate->id]);
    $service->syncRoster($form);

    expect($form->recipients()->pluck('user_id')->all())->toBe([$student->id]);
});

it('lets a student sign only their own row, once, from My Acknowledgements', function () {
    extract(acknowledgementFixture());
    $other = User::factory()->create(['is_student' => true]);
    $offering->students()->attach($other);
    app(AcknowledgementForms::class)->syncRoster($form);
    $this->actingAs($student);

    Livewire::test(MyAcknowledgements::class)
        ->assertCanSeeTableRecords([$form])
        ->callTableAction('sign', $form, data: ['signature' => '[[[1,1],[2,2]]]'])
        ->assertHasTableActionErrors(['signature'])
        ->callTableAction('sign', $form, data: ['signature' => signatureStrokes()])
        ->assertHasNoTableActionErrors();

    $mine = AcknowledgementRecipient::where('user_id', $student->id)->sole();
    expect($mine->signed_at)->not->toBeNull()
        ->and($mine->signed_by)->toBe($student->id)
        ->and(AcknowledgementRecipient::where('user_id', $other->id)->value('signed_at'))->toBeNull();

    // A second device cannot replace it.
    $first = $mine->signature_path;
    expect(app(AcknowledgementForms::class)->signAsStudent($form, $student, json_encode([[[0, 0], [500, 500]]]), null))->toBeFalse()
        ->and($mine->refresh()->signature_path)->toBe($first);
});

it('hides other classes and archived forms from students and stops signing on closed forms', function () {
    extract(acknowledgementFixture());
    $outsider = User::factory()->create(['is_student' => true]);
    $outsider->givePermissionTo('page_MyAcknowledgements');
    $outsider->campuses()->syncWithoutDetaching([$offering->campus->id]);

    $this->actingAs($outsider);
    Livewire::test(MyAcknowledgements::class)->assertCanNotSeeTableRecords([$form]);
    expect(fn () => app(AcknowledgementForms::class)->signAsStudent($form, $outsider, signatureStrokes(), null))->toThrow(ValidationException::class);

    $this->actingAs($student);
    $form->update(['status' => 'closed']);
    Livewire::test(MyAcknowledgements::class)->assertCanSeeTableRecords([$form])->assertTableActionHidden('sign', $form);
    expect(fn () => app(AcknowledgementForms::class)->signAsStudent($form, $student, signatureStrokes(), null))->toThrow(ValidationException::class);

    $form->update(['status' => 'archived']);
    Livewire::test(MyAcknowledgements::class)->assertCanNotSeeTableRecords([$form]);
});

it('lets the instructor create a receipt with its roster, capture and clear signatures', function () {
    extract(acknowledgementFixture());
    $form->delete();
    $this->actingAs($instructor);

    Livewire::test(CreateAcknowledgementForm::class)
        ->fillForm([
            'course_offering_id' => $offering->id, 'document_received' => 'Midterm Exam', 'date_received' => '2026-09-20', 'status' => 'active',
            'year_section' => 'BSIT 3A', 'term_label' => 'First Semester, A.Y. 2026 - 2027', 'instructor_name' => 'J. INSTRUCTOR',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = AcknowledgementForm::sole();
    expect($created->created_by)->toBe($instructor->id)->and($created->recipients()->count())->toBe(1);
    $row = $created->recipients()->sole();

    Livewire::test(RecipientsRelationManager::class, ['ownerRecord' => $created, 'pageClass' => EditAcknowledgementForm::class])
        ->assertCanSeeTableRecords([$row])
        ->callTableAction('signFor', $row, data: ['signature' => signatureStrokes()])
        ->assertHasNoTableActionErrors();
    expect($row->refresh()->signed_by)->toBe($instructor->id);

    Livewire::test(ListAcknowledgementForms::class)->assertTableActionHidden('delete', $created);

    Livewire::test(RecipientsRelationManager::class, ['ownerRecord' => $created, 'pageClass' => EditAcknowledgementForm::class])
        ->callTableAction('clearSignature', $row);
    expect($row->refresh()->signed_at)->toBeNull()->and($row->signature_path)->toBeNull();
});

it('keeps instructors out of other instructors\' classes', function () {
    extract(acknowledgementFixture());
    $stranger = User::factory()->create(['is_instructor' => true]);
    foreach (['view_any', 'view', 'create', 'update', 'delete'] as $prefix) {
        $stranger->givePermissionTo("{$prefix}_acknowledgement::form");
    }
    $stranger->campuses()->syncWithoutDetaching([$offering->campus->id]);
    $this->actingAs($stranger);

    expect(AcknowledgementFormResource::getEloquentQuery()->count())->toBe(0)
        ->and(AcknowledgementFormResource::classOptions())->toBe([]);
    $this->get(route('acknowledgements.print', ['forms' => $form->id]))->assertForbidden();

    Livewire::test(CreateAcknowledgementForm::class)
        ->fillForm(['course_offering_id' => $offering->id, 'document_received' => 'X', 'date_received' => '2026-09-20', 'status' => 'active', 'year_section' => 'A', 'term_label' => 'T', 'instructor_name' => 'I'])
        ->call('create');
    expect(AcknowledgementForm::count())->toBe(1);

    $this->actingAs($student);
    $this->get(route('acknowledgements.print', ['forms' => $form->id]))->assertForbidden();
});

it('stores a long signature compactly', function () {
    $stroke = array_map(fn ($i) => [$i, 60 + 50 * sin($i / 12)], range(0, 800));

    expect(strlen(SignatureStrokes::pathFrom(json_encode([$stroke]))))->toBeLessThan(12000);
});

it('does not run more queries for a larger class', function () {
    extract(acknowledgementFixture());
    $service = app(AcknowledgementForms::class);
    $service->syncRoster($form);
    $this->actingAs($instructor);
    $count = function () use ($form) {
        Livewire::test(RecipientsRelationManager::class, ['ownerRecord' => $form->fresh(), 'pageClass' => EditAcknowledgementForm::class]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(RecipientsRelationManager::class, ['ownerRecord' => $form->fresh(), 'pageClass' => EditAcknowledgementForm::class]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $small = $count();
    $offering->students()->attach(User::factory()->count(30)->create(['is_student' => true]));
    $service->syncRoster($form);

    expect($count())->toBe($small);
});

it('leaves signature drawings out of the roster list', function () {
    extract(acknowledgementFixture());
    $service = app(AcknowledgementForms::class);
    $service->syncRoster($form);
    $service->signAsStudent($form, $student, signatureStrokes(), null);
    $this->actingAs($instructor);

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(RecipientsRelationManager::class, ['ownerRecord' => $form->fresh(), 'pageClass' => EditAcknowledgementForm::class]);
    $lists = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'from "acknowledgement_recipients"') && ! str_contains($sql, 'count('));
    DB::disableQueryLog();

    expect($lists)->not->toBeEmpty();
    $lists->each(fn ($sql) => expect($sql)->not->toContain('*')->not->toContain('signature_path'));
});

it('prints the paper format with the signature in place', function () {
    extract(acknowledgementFixture());
    $service = app(AcknowledgementForms::class);
    $service->syncRoster($form);
    $service->signAsStudent($form, $student, signatureStrokes(), null);
    $this->actingAs($instructor);

    $this->get(route('acknowledgements.print', ['forms' => $form->id]))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee("Learner's Acknowledgement Receipt", false)
        ->assertSee('BOHOL ISLAND STATE UNIVERSITY')
        ->assertSee('CRUZ, ANA, R.')
        ->assertSee('August 17, 2026')
        ->assertSee('class="signature-image"', false)
        ->assertSee('F-AQA-INS-003')
        ->assertSee('Page 1 of 1');
});

it('splits long classes so every student appears once and the instructor line ends the form', function (int $students) {
    $form = new AcknowledgementForm;
    $form->setRelation('recipients', collect(range(1, $students))->map(fn ($n) => new AcknowledgementRecipient(['student_name' => "S{$n}"])));

    $pages = app(AcknowledgementForms::class)->printPages(collect([$form]));
    $fragments = collect($pages)->flatten(1);

    expect($fragments->sum(fn ($fragment) => $fragment['students']->count()))->toBe($students)
        ->and($fragments->where('final', true))->toHaveCount(1)
        ->and($fragments->last()['final'])->toBeTrue()
        ->and($fragments->last()['students']->count())->toBeGreaterThan(0);
    foreach ($pages as $page) {
        expect($page)->not->toBeEmpty()
            ->and(collect($page)->sum(fn ($fragment) => 4 + $fragment['students']->count() + ($fragment['final'] ? 4 : 0)))->toBeLessThanOrEqual(40);
    }
})->with([1, 31, 32, 33, 46, 64, 65, 66, 97, 130]);

it('flows several forms across pages without losing the instructor line', function () {
    $make = fn (int $count) => tap(new AcknowledgementForm, fn ($form) => $form->setRelation('recipients', collect(range(1, $count))->map(fn ($n) => new AcknowledgementRecipient(['student_name' => "S{$n}"]))));

    $pages = app(AcknowledgementForms::class)->printPages(collect([$make(29), $make(3), $make(1), $make(46), $make(1)]));
    $fragments = collect($pages)->flatten(1);

    expect($fragments->where('final', true))->toHaveCount(5)
        ->and($fragments->sum(fn ($fragment) => $fragment['students']->count()))->toBe(80);
});

function secondClass($offering, string $section = 'BSIT 3B')
{
    $course = Course::create(['department_id' => $offering->course()->value('department_id'), 'code' => 'WEB201', 'name' => 'Web Enterprise', 'is_active' => true]);

    return CourseOffering::create(['semester_id' => $offering->semester_id, 'course_id' => $course->id, 'user_id' => $offering->user_id, 'section' => $section, 'is_active' => true]);
}

it('puts open assessments and receipts to sign at the top of the student dashboard', function () {
    extract(acknowledgementFixture());
    $student->assignRole(Role::findOrCreate('student', 'web'));
    // Publishing normally needs questions; these only need to exist for the list.
    $quiz = fn (array $data, bool $published = true) => tap(Quiz::create([...$data, 'course_offering_id' => $offering->id, 'assign_to_all' => true, 'auto_sync_class_record' => false]))
        ->forceFill(['is_published' => $published])->saveQuietly();
    $open = Quiz::find(tap(Quiz::create(['course_offering_id' => $offering->id, 'title' => 'Midterm Exam', 'type' => 'exam', 'assign_to_all' => true,
        'auto_sync_class_record' => false, 'available_until' => now()->addHours(5)]), fn ($q) => $q->forceFill(['is_published' => true])->saveQuietly())->id);
    $quiz(['title' => 'Draft quiz', 'type' => 'quiz'], false);
    $quiz(['title' => 'Closed quiz', 'type' => 'quiz', 'available_until' => now()->subDay()]);
    receiptFor($form, $offering, 'Midterm receipt');
    $this->actingAs($student);

    $todo = app(StudentTodo::class)->for($student, $offering->campus->id);
    expect($todo['assessments']->pluck('title')->all())->toBe(['Midterm Exam'])
        ->and($todo['assessments'][0]['action'])->toBe('Start')
        ->and($todo['receipts']->sole()['count'])->toBe(2)
        ->and($todo['total'])->toBe(3);

    $this->get(Dashboard::getUrl(tenant: $offering->campus))->assertOk()
        ->assertSeeInOrder(['To do now', 'Midterm Exam', 'Receipts to sign', 'Sign all', 'My tasks', 'My acknowledgements'])
        ->assertDontSee('Draft quiz')->assertDontSee('Closed quiz');

    // Once the exam is under way it shows Continue; once everything is done the card says so.
    $open->attempts()->create(['user_id' => $student->id, 'attempt_number' => 1, 'status' => 'in_progress', 'started_at' => now(), 'expires_at' => now()->addHour()]);
    expect(app(StudentTodo::class)->for($student, $offering->campus->id)['assessments'][0]['action'])->toBe('Continue');
    $open->attempts()->update(['status' => 'submitted']);
    app(AcknowledgementForms::class)->addToOpenForms($student, $offering->campus->id);
    app(AcknowledgementForms::class)->signManyAsStudent($student, $offering->campus->id, AcknowledgementForm::pluck('id')->all(), signatureStrokes(), null);
    $this->get(Dashboard::getUrl(tenant: $offering->campus))->assertOk()->assertSee("You're all caught up", false);
});

it('takes the section most students belong to when the class has none saved', function () {
    extract(acknowledgementFixture());
    $other = secondClass($offering, '');
    $classmates = User::factory()->count(3)->create(['is_student' => true]);
    $other->students()->attach([$student->id, ...$classmates->modelKeys()]);
    $place = ['department_id' => $offering->course->department_id, 'semester_id' => $other->semester_id];
    foreach ($classmates as $classmate) {
        StudentSectionMembership::create([...$place, 'user_id' => $classmate->id, 'section' => 'BSIT 4A']);
    }
    StudentSectionMembership::create([...$place, 'user_id' => $student->id, 'section' => 'BSIT 3C']);
    $service = app(AcknowledgementForms::class);

    expect($service->defaultsFor($other)['year_section'])->toBe('BSIT 4A')
        ->and($service->defaultsFor($offering)['year_section'])->toBe($offering->section);
});

function receiptFor($source, $offering, string $document, string $status = 'active'): AcknowledgementForm
{
    return AcknowledgementForm::create([...$source->only(['document_title', 'date_received', 'year_section', 'college_name', 'department_name', 'term_label', 'instructor_name', 'instructor_designation']), 'course_offering_id' => $offering->id, 'document_received' => $document, 'status' => $status]);
}

it('counts the forms a student must sign for each enrolled subject', function () {
    extract(acknowledgementFixture());
    $service = app(AcknowledgementForms::class);
    $other = secondClass($offering);
    $other->students()->attach($student);
    $empty = CourseOffering::create(['semester_id' => $offering->semester_id, 'course_id' => $other->course_id, 'user_id' => $offering->user_id, 'section' => 'BSIT 3C', 'is_active' => true]);
    $empty->students()->attach($student);

    // First subject: one signed, one open, one closed, one archived. Second subject: one open.
    receiptFor($form, $offering, 'Midterm');
    receiptFor($form, $offering, 'Closed paper', 'closed');
    receiptFor($form, $offering, 'Old paper', 'archived');
    receiptFor($form, $other, 'Syllabus');
    $service->signAsStudent($form, $student, signatureStrokes(), null);

    $summary = $service->studentSummary($student, $offering->campus->id)->keyBy('id');

    expect($summary[$offering->id])->toMatchArray(['total' => 3, 'signed' => 1, 'to_sign' => 1])
        ->and($summary[$other->id])->toMatchArray(['total' => 1, 'signed' => 0, 'to_sign' => 1, 'code' => 'WEB201', 'name' => 'Web Enterprise'])
        ->and($summary[$empty->id])->toMatchArray(['total' => 0, 'signed' => 0, 'to_sign' => 0])
        ->and($service->toSignCount($student, $offering->campus->id))->toBe(2);

    // Another campus and another student see none of it.
    expect($service->studentSummary($student, 999))->toBeEmpty()
        ->and($service->toSignCount(User::factory()->create(['is_student' => true]), $offering->campus->id))->toBe(0);
});

it('shows the student their count by subject, and filters the list by subject', function () {
    extract(acknowledgementFixture());
    $other = secondClass($offering);
    $other->students()->attach($student);
    $second = receiptFor($form, $other, 'Web Enterprise Syllabus');
    $this->actingAs($student);

    expect(MyAcknowledgements::getNavigationBadge())->toBe('2');

    Livewire::test(MyAcknowledgements::class)
        ->assertSee('You have 2 forms to sign')
        ->assertSee('Across 2 subjects')
        ->assertSee('Web Enterprise')
        ->assertSee('1 to sign')
        ->assertCanSeeTableRecords([$form, $second])
        ->call('toggleSubject', $other->id)
        ->assertCanSeeTableRecords([$second])
        ->assertCanNotSeeTableRecords([$form])
        ->call('toggleSubject', $other->id)
        ->assertCanSeeTableRecords([$form, $second])
        ->callTableAction('sign', $form, data: ['signature' => signatureStrokes()])
        ->assertNotified()
        // The page reloads so the sidebar badge shows the new count too.
        ->assertRedirect(MyAcknowledgements::getUrl());

    expect(MyAcknowledgements::getNavigationBadge())->toBe('1');
    Livewire::test(MyAcknowledgements::class)
        ->assertSee('You have 1 form to sign')
        ->set('subject', $other->id)
        ->callTableAction('sign', $second, data: ['signature' => signatureStrokes()])
        ->assertRedirect(MyAcknowledgements::getUrl(['subject' => $other->id]));
    Livewire::test(MyAcknowledgements::class)->assertSee("You're all caught up", false);

    expect(MyAcknowledgements::getNavigationBadge())->toBeNull();
});

it('lists forms to sign before signed ones', function () {
    extract(acknowledgementFixture());
    $older = receiptFor($form, $offering, 'Older form');
    $older->update(['date_received' => '2026-06-01']);
    app(AcknowledgementForms::class)->signAsStudent($form, $student, signatureStrokes(), null);
    $this->actingAs($student);

    $records = Livewire::test(MyAcknowledgements::class)->instance()->getTableRecords();

    expect($records->pluck('id')->all())->toBe([$older->id, $form->id]);
});

it('reminds the student on the dashboard only while something is waiting', function () {
    extract(acknowledgementFixture());
    $this->actingAs($student);

    expect(StudentAcknowledgementsWidget::canView())->toBeTrue();
    Livewire::test(StudentAcknowledgementsWidget::class)->assertSee('You have 1 form to sign');

    app(AcknowledgementForms::class)->signAsStudent($form, $student, signatureStrokes(), null);
    expect(StudentAcknowledgementsWidget::canView())->toBeFalse();

    $this->actingAs($instructor);
    expect(StudentAcknowledgementsWidget::canView())->toBeFalse();
});

it('notifies students of a new form and reminds only those who have not signed', function () {
    extract(acknowledgementFixture());
    $classmate = User::factory()->create(['is_student' => true]);
    $offering->students()->attach($classmate);
    $service = app(AcknowledgementForms::class);
    $service->syncRoster($form);
    $service->signAsStudent($form, $student, signatureStrokes(), null);
    $this->actingAs($instructor);

    Livewire::test(RecipientsRelationManager::class, ['ownerRecord' => $form, 'pageClass' => EditAcknowledgementForm::class])
        ->callTableAction('remindWaiting');

    expect($classmate->notifications()->count())->toBe(1)
        ->and($student->notifications()->count())->toBe(0)
        ->and($classmate->notifications()->first()->data['title'])->toBe('Reminder: please sign this form');

    $form->update(['status' => 'closed']);
    expect($service->notifyWaiting($form, 'x'))->toBe(0);
});

it('copies a document to the instructor\'s other classes and skips ones that have it', function () {
    extract(acknowledgementFixture());
    $other = secondClass($offering);
    $classmate = User::factory()->create(['is_student' => true]);
    $other->students()->attach($classmate);
    $this->actingAs($instructor);

    $copy = fn () => Livewire::test(ListAcknowledgementForms::class)
        ->callTableAction('copyToClasses', $form, data: ['document_received' => 'Web Enterprise Syllabus', 'classes' => [$other->id]])
        ->assertHasNoTableActionErrors();

    $copy();
    $created = AcknowledgementForm::where('course_offering_id', $other->id)->sole();
    expect($created->document_received)->toBe('Web Enterprise Syllabus')
        ->and($created->year_section)->toBe('BSIT 3B')
        ->and($created->created_by)->toBe($instructor->id)
        ->and($created->date_received->equalTo($form->date_received))->toBeTrue()
        ->and($created->recipients()->pluck('user_id')->all())->toBe([$classmate->id])
        ->and($classmate->notifications()->count())->toBe(1);

    $copy();
    expect(AcknowledgementForm::where('course_offering_id', $other->id)->count())->toBe(1);
});

it('will not copy a form into a class of another instructor', function () {
    extract(acknowledgementFixture());
    $foreign = secondClass($offering);
    $foreign->update(['user_id' => User::factory()->create(['is_instructor' => true])->id]);
    $foreign->students()->attach(User::factory()->create(['is_student' => true]));
    $this->actingAs($instructor);

    Livewire::test(ListAcknowledgementForms::class)
        ->mountTableAction('copyToClasses', $form)
        ->setTableActionData(['document_received' => 'X', 'classes' => [$foreign->id]])
        ->call('callMountedTableAction')
        ->assertForbidden();

    expect(AcknowledgementForm::where('course_offering_id', $foreign->id)->exists())->toBeFalse();
});

it('previews the document inside the panel with a print button, for its instructor only', function () {
    extract(acknowledgementFixture());
    $instructor->assignRole(Role::findOrCreate('instructor', 'web'));
    $this->actingAs($instructor);

    Livewire::test(PreviewAcknowledgementForm::class, ['record' => $form->id])
        ->assertSee('Preview: Web Development Syllabus')
        ->assertSeeHtml('<iframe')
        ->assertSeeHtml(e(route('acknowledgements.print', ['forms' => $form->id])));

    $this->get(AcknowledgementFormResource::getUrl('preview', ['record' => $form], tenant: $offering->campus))->assertOk();
    $this->get(route('acknowledgements.print', ['forms' => $form->id]))->assertOk()->assertSee('window.print()', false)
        ->assertSee('href="'.e(AcknowledgementFormResource::getUrl('edit', ['record' => $form], tenant: $offering->campus)).'"', false);

    $second = tap($form->replicate()->fill(['document_received' => 'Second']))->save();
    $this->get(route('acknowledgements.print', ['forms' => [$form->id, $second->id]]))->assertOk()
        ->assertSee('href="'.e(AcknowledgementFormResource::getUrl('index', tenant: $offering->campus)).'"', false);

    $stranger = User::factory()->create(['is_instructor' => true]);
    $stranger->assignRole('instructor');
    $stranger->givePermissionTo('view_any_acknowledgement::form', 'view_acknowledgement::form');
    $stranger->campuses()->syncWithoutDetaching([$offering->campus->id]);
    $this->actingAs($stranger);
    expect($this->get(AcknowledgementFormResource::getUrl('preview', ['record' => $form], tenant: $offering->campus))->getStatusCode())->toBeIn([403, 404]);
    $this->get(route('acknowledgements.print', ['forms' => $form->id]))->assertForbidden();

    $student->assignRole('student');
    $this->actingAs($student);
    $status = $this->get(AcknowledgementFormResource::getUrl('preview', ['record' => $form], tenant: $offering->campus))->getStatusCode();
    expect($status)->toBeIn([403, 404]);
});

it('tells the preview to reload only when a signature changed', function () {
    extract(acknowledgementFixture());
    $service = app(AcknowledgementForms::class);
    $service->syncRoster($form);
    $this->actingAs($instructor);

    $preview = Livewire::test(PreviewAcknowledgementForm::class, ['record' => $form->id])
        ->call('checkForNewSignatures')
        ->assertNotDispatched('acknowledgement-receipt-changed');

    $service->signAsStudent($form, $student, signatureStrokes(), null);
    $preview->call('checkForNewSignatures')->assertDispatched('acknowledgement-receipt-changed')->assertSet('signedCount', 1);
});

it('signs the checked forms with one drawing and leaves the rest alone', function () {
    extract(acknowledgementFixture());
    $service = app(AcknowledgementForms::class);
    $midterm = receiptFor($form, $offering, 'Midterm');
    $final = receiptFor($form, $offering, 'Final');
    $this->actingAs($student);

    Livewire::test(MyAcknowledgements::class)
        ->assertTableActionVisible('signAll')
        ->assertTableActionHasLabel('signAll', 'Sign all (3)')
        ->assertSee('Sign all (3)');

    $service->signManyAsStudent($student, $offering->campus->id, [$form->id, $midterm->id], signatureStrokes(), '127.0.0.1');

    $rows = AcknowledgementRecipient::where('user_id', $student->id)->get()->keyBy('acknowledgement_form_id');
    expect($rows[$form->id]->signed_at)->not->toBeNull()
        ->and($rows[$midterm->id]->signed_at)->not->toBeNull()
        ->and($rows[$form->id]->signature_path)->toBe($rows[$midterm->id]->signature_path)
        ->and($rows[$form->id]->signed_by)->toBe($student->id)
        ->and($rows[$final->id]->signed_at)->toBeNull();

    // The page's own action signs what stays checked, and the counts follow.
    $last = receiptFor($form, $offering, 'Last');
    Livewire::test(MyAcknowledgements::class)
        ->assertTableActionHasLabel('signAll', 'Sign all (2)')
        ->callTableAction('signAll', data: ['signature' => signatureStrokes()])
        ->assertHasNoTableActionErrors()
        ->assertRedirect(MyAcknowledgements::getUrl());
    Livewire::test(MyAcknowledgements::class)
        ->assertSee("You're all caught up", false)
        ->assertTableActionHidden('signAll');
    expect(AcknowledgementRecipient::where('user_id', $student->id)->whereNull('signed_at')->count())->toBe(0);

    // A later form needs a new drawing: nothing is reused automatically.
    $later = receiptFor($form, $offering, 'Later');
    $service->addToOpenForms($student, $offering->campus->id);
    expect(AcknowledgementRecipient::where('user_id', $student->id)->where('acknowledgement_form_id', $later->id)->value('signed_at'))->toBeNull();
});

it('only signs forms where the student has their own open record', function () {
    extract(acknowledgementFixture());
    $service = app(AcknowledgementForms::class);
    $campus = $offering->campus->id;
    $classmate = User::factory()->create(['is_student' => true]);
    $offering->students()->attach($classmate);

    $noRecord = receiptFor($form, $offering, 'No record yet');
    $closed = receiptFor($form, $offering, 'Closed');
    $signed = receiptFor($form, $offering, 'Already signed');
    $service->syncRoster($form);
    $service->syncRoster($closed);
    $service->syncRoster($signed);
    $closed->update(['status' => 'closed']);
    $service->signAsStudent($signed, $student, json_encode([[[0, 0], [500, 500]]]), null);
    $firstSignature = AcknowledgementRecipient::where('acknowledgement_form_id', $signed->id)->where('user_id', $student->id)->value('signature_path');

    $other = secondClass($offering);
    $notMine = receiptFor($form, $other, 'Another class');
    $outsider = User::factory()->create(['is_student' => true]);
    $other->students()->attach($outsider);
    $service->syncRoster($notMine);

    expect($service->signableForms($student, $campus)->pluck('id')->all())->toBe([$form->id]);

    $count = $service->signManyAsStudent($student, $campus, [$form->id, $noRecord->id, $closed->id, $signed->id, $notMine->id], signatureStrokes(), null);

    expect($count)->toBe(1)
        ->and(AcknowledgementRecipient::where('user_id', $student->id)->whereNotNull('signed_at')->pluck('acknowledgement_form_id')->sort()->values()->all())->toBe([$form->id, $signed->id])
        ->and(AcknowledgementRecipient::where('acknowledgement_form_id', $signed->id)->where('user_id', $student->id)->value('signature_path'))->toBe($firstSignature)
        ->and(AcknowledgementRecipient::where('user_id', $classmate->id)->whereNotNull('signed_at')->count())->toBe(0)
        ->and(AcknowledgementRecipient::where('user_id', $outsider->id)->whereNotNull('signed_at')->count())->toBe(0)
        ->and(AcknowledgementRecipient::where('user_id', $student->id)->where('acknowledgement_form_id', $notMine->id)->exists())->toBeFalse();

    // A record that exists but belongs to a student who has left the class cannot be signed either.
    $service->syncRoster($closed);
    $offering->students()->detach($classmate);
    expect($service->signManyAsStudent($classmate, $campus, [$form->id], signatureStrokes(), null))->toBe(0);

    // Nothing is saved without a real drawing.
    $fresh = receiptFor($form, $offering, 'Fresh');
    $service->syncRoster($fresh);
    expect(fn () => $service->signManyAsStudent($student, $campus, [$fresh->id], '[[[1,1],[2,2]]]', null))->toThrow(ValidationException::class);
    expect(AcknowledgementRecipient::where('acknowledgement_form_id', $fresh->id)->where('user_id', $student->id)->value('signed_at'))->toBeNull();
});

it('gives a late enrollee their record, offers Sign all only for two or more forms, and follows the subject filter', function () {
    extract(acknowledgementFixture());
    $other = secondClass($offering);
    $other->students()->attach($student);
    $this->actingAs($student);
    $form->update(['status' => 'closed']);

    $single = receiptFor($form, $other, 'One form');
    Livewire::test(MyAcknowledgements::class)->assertTableActionHidden('signAll');
    expect(AcknowledgementRecipient::where('user_id', $student->id)->where('acknowledgement_form_id', $single->id)->exists())->toBeTrue();

    $form->update(['status' => 'active']);
    receiptFor($form, $other, 'Second form');

    Livewire::test(MyAcknowledgements::class)
        ->assertTableActionHasLabel('signAll', 'Sign all (3)')
        ->call('toggleSubject', $other->id)
        ->assertTableActionHasLabel('signAll', 'Sign all (2)')
        ->call('toggleSubject', $offering->id)
        ->assertTableActionHidden('signAll');
});

it('requires at least one checked form and a drawing for Sign all', function () {
    extract(acknowledgementFixture());
    receiptFor($form, $offering, 'Midterm');
    $this->actingAs($student);

    Livewire::test(MyAcknowledgements::class)
        ->callTableAction('signAll', data: ['forms' => [], 'signature' => signatureStrokes()])
        ->assertHasTableActionErrors(['forms' => 'required'])
        ->callTableAction('signAll', data: ['forms' => [$form->id], 'signature' => null])
        ->assertHasTableActionErrors(['signature' => 'required']);

    expect(AcknowledgementRecipient::whereNotNull('signed_at')->count())->toBe(0);
});

it('stops a class with receipts from being deleted', function () {
    extract(acknowledgementFixture());

    expect(fn () => $offering->delete())->toThrow(ValidationException::class);
});
