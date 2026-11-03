<?php

use App\Filament\Resources\TeachingClassResource\Pages\ListTeachingClasses;
use App\Jobs\RunBackgroundTask;
use App\Models\BackgroundTask;
use App\Models\Course;
use App\Models\Department;
use App\Models\Form;
use App\Models\StudentSectionMembership;
use App\Models\User;
use App\Services\BackgroundTasks;
use App\Services\TeachingRosterExports;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    ['offering' => $this->class, 'student' => $this->student] = evaluationFixture(false);
    $this->campus = $this->class->campus;
    $this->teacher = $this->class->instructor;
    $this->teacher->campuses()->attach($this->campus);
    $this->department = $this->class->course->department;
    $this->actor = User::factory()->create(['is_student' => false, 'instructor_department_id' => $this->department->id]);
    $this->actor->campuses()->attach($this->campus);
    foreach (['view_any_course::offering', 'view_course::offering'] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    Queue::fake();
    Storage::fake('local');
    $this->export = function (array $ids) {
        $task = app(BackgroundTasks::class)->enqueue($this->actor, $this->campus, 'class-followup-export', 'Roster follow-up', ['ids' => $ids]);
        app(BackgroundTasks::class)->run($task->id);

        return $task->fresh();
    };
    $this->classCopy = function (array $changes = []) {
        $copy = $this->class->replicate();
        $copy->fill($changes);
        $copy->save();

        return $copy;
    };
});

it('exports missing student lists in a private queued CSV with instructor subject and follow-up details', function () {
    $before = $this->class->fresh()->getRawOriginal();
    $task = ($this->export)([$this->class->id]);
    expect($task->status)->toBe('completed')->and($task->result['row_count'])->toBe(1)->and($task->result['instructor_count'])->toBe(1);
    $csv = Storage::disk('local')->get($task->file_path);
    expect($csv)->toContain($this->teacher->name, 'CS101', 'No students assigned', 'No section assigned', 'Teaching Class ID')
        ->not->toContain($this->student->name, $this->teacher->password);
    expect($this->class->fresh()->getRawOriginal())->toBe($before);
    Queue::assertPushed(RunBackgroundTask::class);
    expect($this->actor->notifications()->count())->toBe(2);
    $this->get(route('background.download', $task))->assertOk()->assertDownload($task->filename);
    $this->actingAs($this->teacher)->get(route('background.download', $task))->assertForbidden();
});

it('does not mistake a saved section label for an uploaded student roster', function () {
    $this->class->update(['section' => 'BSIT 3A', 'evaluator_selection' => ['source' => 'sections_v1', 'sections' => [$this->department->id.'~'.$this->class->semester_id.'~BSIT%203A']]]);
    $task = ($this->export)([$this->class->id]);
    $csv = Storage::disk('local')->get($task->file_path);
    expect($csv)->toContain('BSIT 3A', 'No students assigned')->not->toContain('No section assigned');
});

it('identifies existing students who only need sectioning instead of asking for their IDs again', function () {
    $this->class->students()->attach($this->student);
    $task = ($this->export)([$this->class->id]);
    $csv = Storage::disk('local')->get($task->file_path);
    expect($csv)->toContain('No section assigned')->not->toContain('No students assigned', 'Upload the section student list');
    $rows = array_map('str_getcsv', explode("\n", trim($csv)));
    expect($rows[1][10])->toBe('1')->and($rows[1][11])->toBe('1');
});

it('excludes complete rosters and treats shared faculty rosters as assigned', function () {
    $this->class->students()->attach($this->student);
    StudentSectionMembership::create(['user_id' => $this->student->id, 'department_id' => $this->department->id, 'semester_id' => $this->class->semester_id, 'section' => 'A']);
    $copy = ($this->classCopy)(['section' => 'B', 'evaluator_selection' => ['source' => 'auto_enrolment_v1', 'auto_enrolment' => true,
        'auto_match' => ['grouped_class_id' => $this->class->id, 'grouped_count' => 1, 'ambiguous' => 0, 'elsewhere' => 0]]]);
    $task = ($this->export)([$this->class->id, $copy->id]);
    expect($task->status)->toBe('completed')->and($task->result['row_count'])->toBe(0)->and($task->result['instructor_count'])->toBe(0);
    expect(substr_count(trim(Storage::disk('local')->get($task->file_path)), "\n"))->toBe(0);
});

it('does not trust a shared-roster pointer to a different instructor', function () {
    $other = User::factory()->create(['is_student' => false, 'is_instructor' => true, 'instructor_department_id' => $this->department->id]);
    $other->campuses()->attach($this->campus);
    $this->class->students()->attach($this->student);
    StudentSectionMembership::create(['user_id' => $this->student->id, 'department_id' => $this->department->id, 'semester_id' => $this->class->semester_id, 'section' => 'A']);
    $copy = ($this->classCopy)(['user_id' => $other->id, 'section' => 'B', 'evaluator_selection' => ['source' => 'auto_enrolment_v1', 'auto_enrolment' => true,
        'auto_match' => ['grouped_class_id' => $this->class->id, 'grouped_count' => 1, 'ambiguous' => 0, 'elsewhere' => 0]]]);
    $task = ($this->export)([$copy->id]);
    expect($task->result['row_count'])->toBe(1);
    expect(Storage::disk('local')->get($task->file_path))->toContain($other->name, 'No students assigned');
});

it('reports unresolved multi-instructor section matching even when some students are assigned', function () {
    $this->class->students()->attach($this->student);
    $this->class->update(['evaluator_selection' => ['source' => 'auto_enrolment_v1', 'auto_match' => ['ambiguous' => 2, 'section_required' => 2], 'report_sections' => [$this->student->id => 'A']]]);
    $task = ($this->export)([$this->class->id]);
    expect(Storage::disk('local')->get($task->file_path))->toContain('Teacher/section matching needs review');
});

it('fails forged out-of-department selections and revoked permissions before exposing a file', function () {
    $other = Department::create(['campus_id' => $this->campus->id, 'code' => 'CCJ', 'name' => 'CCJ', 'is_active' => true]);
    $course = Course::create(['department_id' => $other->id, 'code' => 'CCJ101', 'name' => 'Other subject', 'is_active' => true]);
    $foreign = ($this->classCopy)(['course_id' => $course->id]);
    $task = ($this->export)([$this->class->id, $foreign->id]);
    expect($task->status)->toBe('failed')->and($task->file_path)->toBeNull();
    $task = app(BackgroundTasks::class)->enqueue($this->actor, $this->campus, 'class-followup-export', 'Queued', ['ids' => [$this->class->id]]);
    $this->actor->revokePermissionTo('view_any_course::offering');
    app(BackgroundTasks::class)->run($task->id);
    expect($task->fresh()->status)->toBe('failed')->and($task->fresh()->file_path)->toBeNull();
});

it('rechecks teaching-department scope before allowing a completed download', function () {
    $task = ($this->export)([$this->class->id]);
    $other = Department::create(['campus_id' => $this->campus->id, 'code' => 'CCJ', 'name' => 'CCJ', 'is_active' => true]);
    $this->class->update(['teaching_department_id' => $other->id]);
    $this->get(route('background.download', $task))->assertForbidden();
});

it('excludes inactive classes supervisor-only tasks and deleted instructors', function () {
    $inactive = ($this->classCopy)(['is_active' => false, 'section' => 'INACTIVE']);
    $supervisorForm = Form::create(['name' => 'Annex B', 'settings' => ['annex' => 'B']]);
    $supervisor = ($this->classCopy)(['form_id' => $supervisorForm->id, 'section' => 'SUPERVISOR']);
    $ids = app(TeachingRosterExports::class)->query($this->actor, $this->campus)->pluck('id')->all();
    expect($ids)->toContain($this->class->id)->not->toContain($inactive->id, $supervisor->id);
    $this->teacher->delete();
    expect(app(TeachingRosterExports::class)->query($this->actor, $this->campus)->count())->toBe(0);
});

it('queues only the academic period and subject search currently displayed in Teaching Classes', function () {
    $otherCourse = Course::create(['department_id' => $this->department->id, 'code' => 'OTHER', 'name' => 'Unrelated', 'is_active' => true]);
    ($this->classCopy)(['course_id' => $otherCourse->id]);
    Livewire::test(ListTeachingClasses::class)->assertStatus(200)->searchTable('CS101')->callAction('exportMissingRosters')->assertHasNoActionErrors();
    $task = BackgroundTask::sole();
    expect($task->kind)->toBe('class-followup-export')->and($task->payload['ids'])->toBe([$this->class->id])->and($task->file_path)->toBeNull();
});
