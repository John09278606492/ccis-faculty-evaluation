<?php

use App\Filament\Pages\EmailReminderSettings;
use App\Filament\Pages\StudentEvaluationProgress;
use App\Jobs\SendEvaluationReminderEmail;
use App\Mail\EvaluationReminderMail;
use App\Models\AcademicSection;
use App\Models\EvaluationReminder;
use App\Models\FormSubmission;
use App\Models\StudentSectionMembership;
use App\Models\User;
use App\Services\EvaluationSubmissionIdentity;
use App\Services\StudentEmailAddress;
use App\Settings\EmailReminderSetting;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    ['student' => $this->student, 'offering' => $this->offering, 'form' => $this->form] = evaluationFixture();
    $this->campus = $this->offering->campus;
    $this->department = $this->offering->course->department;
    $this->student->update(['student_department_id' => $this->department->id]);
    $this->head = User::factory()->create(['is_student' => false]);
    $this->head->campuses()->attach($this->campus);
    $this->head->assignRole(Role::findOrCreate('chairperson', 'web'));
    $this->department->update(['chairperson_id' => $this->head->id]);
    $this->head->givePermissionTo(Permission::findOrCreate('page_StudentEvaluationProgress', 'web'));
    $this->section = AcademicSection::create(['department_id' => $this->department->id, 'semester_id' => $this->offering->semester_id, 'name' => 'BSIT 3A', 'is_active' => true]);
    $this->otherSection = AcademicSection::create(['department_id' => $this->department->id, 'semester_id' => $this->offering->semester_id, 'name' => 'BSIT 3B', 'is_active' => true]);
    $this->member = function (User $student, ?AcademicSection $section = null) {
        $section ??= $this->section;
        StudentSectionMembership::create(['user_id' => $student->id, 'academic_section_id' => $section->id,
            'department_id' => $section->department_id, 'semester_id' => $section->semester_id, 'section' => $section->name]);
    };
    ($this->member)($this->student);
    ($this->member)($this->student, $this->otherSection);
    $this->pending = User::factory()->create(['is_student' => true, 'student_department_id' => $this->department->id]);
    $this->pending->assignRole('student');
    $this->pending->campuses()->attach($this->campus);
    $this->offering->students()->attach($this->pending);
    ($this->member)($this->pending);
    $this->unassigned = User::factory()->create(['is_student' => true, 'student_department_id' => $this->department->id]);
    $this->unassigned->assignRole('student');
    $this->unassigned->campuses()->attach($this->campus);
    ($this->member)($this->unassigned);
    FormSubmission::create(['course_offering_id' => $this->offering->id, 'form_id' => $this->form->id,
        'submission_fingerprint' => app(EvaluationSubmissionIdentity::class)->fingerprint($this->offering, request(), $this->student),
        'data' => ['teaching_quality' => 1, 'comments' => 'PRIVATE-RESPONSE-CONTENT']]);
    $this->student->update(['first_name' => 'Niño Carlo', 'last_name' => 'Dela Cruz']);
    $this->pending->update(['first_name' => 'Romer', 'last_name' => 'Cadeliña']);
    $this->actingAs($this->head);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
});

function configureReminderEmail(): void
{
    $settings = app(EmailReminderSetting::class);
    $settings->sender_email = 'admin.sender@bisu.edu.ph';
    $settings->smtp_password = 'abcdabcdabcdabcd';
    $settings->save();
}

it('builds school emails from first and last names without accents or symbols', function () {
    $builder = app(StudentEmailAddress::class);
    expect($builder->build('Niño Carlo', 'Villapaña', 'bisu.edu.ph'))->toBe('ninocarlo.villapana@bisu.edu.ph')
        ->and($builder->build('Karl Jacquin', 'Ag-Ag', 'bisu.edu.ph', 'firstlast'))->toBe('karljacquinagag@bisu.edu.ph')
        ->and($builder->build('Ma. Trixia', 'Madulin', 'bisu.edu.ph'))->toBe('matrixia.madulin@bisu.edu.ph')
        ->and($builder->build('', 'Madulin', 'bisu.edu.ph'))->toBeNull()
        ->and($builder->for($this->pending))->toBe('romer.cadelina@bisu.edu.ph');
    $this->pending->update(['email' => 'Real.Address@BISU.edu.ph']);
    expect($builder->for($this->pending->fresh()))->toBe('real.address@bisu.edu.ph');
});

it('reminds only students in a section who still have open evaluations, with a bell and a queued email', function () {
    configureReminderEmail();
    Queue::fake();
    Livewire::test(StudentEvaluationProgress::class)->callAction('bySection')
        ->callTableAction('remind', $this->section, ['template' => 'grades', 'subject' => 'Finish it, {first_name}', 'message' => 'You have {remaining} left.',
            'bell' => true, 'email' => true, 'skip_hours' => 12])
        ->assertHasNoTableActionErrors()->assertNotified('Reminded 1 student');

    $reminder = EvaluationReminder::sole();
    expect($reminder->user_id)->toBe($this->pending->id)->and($reminder->academic_section_id)->toBe($this->section->id)
        ->and($reminder->email)->toBe('romer.cadelina@bisu.edu.ph')->and($reminder->subject)->toBe('Finish it, Romer')
        ->and($reminder->message)->toBe('You have 1 left.')->and($reminder->email_status)->toBe('queued');
    expect($this->pending->notifications()->count())->toBe(1)->and($this->student->notifications()->count())->toBe(0)
        ->and($this->unassigned->notifications()->count())->toBe(0);
    expect($this->pending->notifications()->first()->data['body'])->toContain('Your Chairperson, '.$this->head->name.', is asking you to complete your faculty evaluation.');
    Queue::assertPushed(SendEvaluationReminderEmail::class, 1);

    // A second click within the skip window does not spam the same student.
    Livewire::test(StudentEvaluationProgress::class)->callAction('bySection')
        ->callTableAction('remind', $this->section, ['subject' => 'Again', 'message' => '', 'bell' => true, 'email' => true, 'skip_hours' => 12]);
    expect(EvaluationReminder::count())->toBe(1);
    Livewire::test(StudentEvaluationProgress::class)->callAction('bySection')->assertSee('Reminded');
});

it('sends the branded email from the super admin mailbox with the evaluation link', function () {
    configureReminderEmail();
    Mail::fake();
    Queue::fake();
    Livewire::test(StudentEvaluationProgress::class)->callAction('bySection')
        ->callTableAction('remind', $this->section, ['subject' => 'Complete your faculty evaluation to see your grades', 'message' => 'Hello', 'bell' => true, 'email' => true, 'skip_hours' => 0]);
    (new SendEvaluationReminderEmail(EvaluationReminder::sole()->id))->handle();
    Mail::assertSent(EvaluationReminderMail::class, function (EvaluationReminderMail $mail) {
        $html = $mail->render();

        return $mail->hasTo('romer.cadelina@bisu.edu.ph') && $mail->hasFrom('admin.sender@bisu.edu.ph')
            && str_contains($html, 'Complete your faculty evaluation to see your grades') && str_contains($html, route('evaluation.entry'))
            && str_contains($html, 'BOHOL ISLAND STATE UNIVERSITY') && str_contains($html, 'Hi Romer');
    });
    expect(EvaluationReminder::sole()->email_status)->toBe('sent');
});

it('sends bell reminders only when email is not set up and from the student list too', function () {
    Queue::fake();
    Livewire::test(StudentEvaluationProgress::class)
        ->callTableBulkAction('remindSelected', [$this->student, $this->pending, $this->unassigned], ['subject' => 'Please finish', 'message' => '', 'bell' => true, 'email' => true, 'skip_hours' => 0])
        ->assertNotified('Reminded 1 student');
    expect(EvaluationReminder::sole()->email_status)->toBe('none')->and($this->pending->notifications()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('lets only a super admin manage the sending mailbox and keeps the saved app password', function () {
    $this->get(EmailReminderSettings::getUrl(tenant: $this->campus))->assertForbidden();
    $admin = User::factory()->create(['is_student' => false, 'email' => 'super.admin@bisu.edu.ph']);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $admin->campuses()->attach($this->campus);
    $this->actingAs($admin);
    Livewire::test(EmailReminderSettings::class)->fillForm(['sender_email' => 'Super.Admin@bisu.edu.ph', 'smtp_password' => 'abcd efgh ijkl mnop'])
        ->call('save')->assertHasNoFormErrors();
    expect(app(EmailReminderSetting::class)->smtp_password)->toBe('abcdefghijklmnop');
    Livewire::test(EmailReminderSettings::class)->assertFormSet(['smtp_password' => null])->fillForm(['sender_name' => 'CCIS'])->call('save');
    $settings = app(EmailReminderSetting::class);
    $settings->refresh();
    expect($settings->smtp_password)->toBe('abcdefghijklmnop')->and($settings->sender_email)->toBe('super.admin@bisu.edu.ph')->and($settings->sender_name)->toBe('CCIS');
});
