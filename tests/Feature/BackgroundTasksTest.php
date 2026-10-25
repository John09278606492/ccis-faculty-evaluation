<?php

use App\Filament\Pages\BackgroundProcessing;
use App\Filament\Pages\ClassRecords;
use App\Jobs\RunBackgroundTask;
use App\Models\BackgroundTask;
use App\Models\ClassRecordUpload;
use App\Models\Department;
use App\Models\User;
use App\Services\AssignedDataAccess;
use App\Services\BackgroundTasks;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    ['offering' => $this->offering] = evaluationFixture();
    $this->campus = $this->offering->campus;
    $this->actor = User::factory()->create(['is_student' => false, 'instructor_department_id' => $this->offering->course->department_id]);
    $this->actor->campuses()->attach($this->campus);
    foreach (['view_academic_students', 'manage_academic_students', 'import_academic_students', 'create_course', 'view_any_user'] as $name) {
        $this->actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    Storage::fake('local');
    Queue::fake();
    $this->tasks = app(BackgroundTasks::class);
    $this->upload = fn () => UploadedFile::fake()->createWithContent('students.csv', "student_id,first_name,last_name,department_code,sections\nBG-001,Maria,Cruz,CCIS,BSIT 3A\n");
    $this->review = fn () => $this->tasks->upload($this->actor, $this->campus, ($this->upload)(), 'student-review', 'Review students', ['semester_id' => $this->offering->semester_id]);
});

it('returns a queued operation and persistent start notification without importing in the web request even with a sync default', function () {
    config(['queue.default' => 'sync']);
    $task = ($this->review)();
    expect($task->status)->toBe('queued')->and(User::where('student_id', 'BG-001')->exists())->toBeFalse();
    Queue::assertPushed(RunBackgroundTask::class, fn ($job) => $job->connection === 'bulk' && $job->queue === 'bulk' && $job->taskId === $task->id);
    expect($this->actor->notifications()->count())->toBe(1);
    expect($task->getRawOriginal('payload'))->not->toContain('source')->not->toContain('semester_id');
});

it('reviews then confirms from a durable plan, imports once and announces completion', function () {
    $task = ($this->review)();
    $source = $task->payload['source'];
    $this->tasks->run($task->id);
    $task->refresh();
    expect($task->status)->toBe('completed')->and($task->result['review']['error_count'])->toBe(0)
        ->and(User::where('student_id', 'BG-001')->exists())->toBeFalse();
    Storage::disk('local')->assertMissing($source);
    Cache::flush(); // a worker restart/cache eviction cannot lose an approved plan
    $import = $this->tasks->confirm($task, $this->actor, $this->campus);
    $again = $this->tasks->confirm($task->fresh(), $this->actor, $this->campus);
    expect($again->id)->toBe($import->id);
    $this->tasks->run($import->id);
    $this->tasks->run($import->id);
    expect($import->fresh()->status)->toBe('completed')->and(User::where('student_id', 'BG-001')->count())->toBe(1)
        ->and($this->actor->notifications()->count())->toBe(4);
});

it('keeps row validation in the background and prevents confirmation of invalid data', function () {
    $bad = UploadedFile::fake()->createWithContent('bad.csv', "student_id,first_name,last_name,department_code\nBAD-1,,Cruz,CCIS\n");
    $task = $this->tasks->upload($this->actor, $this->campus, $bad, 'student-review', 'Review invalid rows', ['semester_id' => $this->offering->semester_id]);
    $this->tasks->run($task->id);
    $task->refresh();
    expect($task->status)->toBe('completed')->and($task->result['review']['error_count'])->toBeGreaterThan(0)->and($task->file_path)->not->toBeNull();
    expect(fn () => $this->tasks->confirm($task, $this->actor, $this->campus))->toThrow(HttpException::class);
    expect(User::where('student_id', 'BAD-1')->exists())->toBeFalse();
});

it('rechecks revoked import permissions before a queued job writes records', function () {
    $task = ($this->review)();
    $this->actor->revokePermissionTo('import_academic_students');
    $this->tasks->run($task->id);
    expect($task->fresh()->status)->toBe('failed')->and(User::where('student_id', 'BG-001')->exists())->toBeFalse();
    expect($this->actor->notifications()->get()->pluck('data.title')->all())->toContain('Background processing failed');
});

it('exports private CSV in the worker with formula escaping and owner-only downloads', function () {
    $this->actor->update(['employee_id' => '=HYPERLINK("unsafe")']);
    $task = $this->tasks->enqueue($this->actor, $this->campus, 'staff-export', 'Export staff', ['ids' => [$this->actor->id]]);
    expect($task->file_path)->toBeNull();
    $this->tasks->run($task->id);
    $task->refresh();
    expect($task->status)->toBe('completed');
    expect(Storage::disk('local')->get($task->file_path))->toContain("'=HYPERLINK")->not->toContain($this->actor->password);
    $this->get(route('background.download', $task))->assertOk()->assertDownload($task->filename);
    $other = User::factory()->create(['is_student' => false]);
    $other->campuses()->attach($this->campus);
    $this->actingAs($other)->get(route('background.download', $task))->assertForbidden();
});

it('does not export selections outside the current department or changed assignments', function () {
    $department = Department::create(['campus_id' => $this->campus->id, 'name' => 'Other college', 'code' => 'OTHER', 'is_active' => true]);
    $foreign = User::factory()->create(['is_student' => false, 'instructor_department_id' => $department->id]);
    $foreign->campuses()->attach($this->campus);
    $task = $this->tasks->enqueue($this->actor, $this->campus, 'staff-export', 'Export selection', ['ids' => [$foreign->id]]);
    $this->tasks->run($task->id);
    expect($task->fresh()->status)->toBe('failed')->and($task->fresh()->file_path)->toBeNull();
});

it('keeps the operations page private to its owner and selected campus', function () {
    $mine = ($this->review)();
    $other = User::factory()->create(['is_student' => false, 'instructor_department_id' => $this->actor->instructor_department_id]);
    $other->campuses()->attach($this->campus);
    $other->givePermissionTo('view_any_user');
    $foreign = $this->tasks->enqueue($other, $this->campus, 'staff-export', 'Private other task', ['ids' => []]);
    Livewire::test(BackgroundProcessing::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$foreign]);
});

it('restores authentication tenant and scoping after each worker job', function () {
    $beforeActor = auth()->id();
    $beforeTenant = Filament::getTenant();
    $beforeScope = app(AssignedDataAccess::class)->actor;
    $task = $this->tasks->enqueue($this->actor, $this->campus, 'staff-export', 'Export staff', ['ids' => [$this->actor->id]]);
    $this->tasks->run($task->id);
    expect(auth()->id())->toBe($beforeActor)->and(Filament::getTenant())->toBe($beforeTenant)
        ->and(app(AssignedDataAccess::class)->actor)->toBe($beforeScope);
});

it('processes a thousand student rows outside the upload request', function () {
    $csv = "student_id,first_name,last_name,department_code,sections\n";
    for ($i = 1; $i <= 1000; $i++) {
        $csv .= "BULK-{$i},Student,Sample{$i},CCIS,BSIT 3A\n";
    }
    $review = $this->tasks->upload($this->actor, $this->campus, UploadedFile::fake()->createWithContent('thousand.csv', $csv), 'student-review', 'Review 1000 students', ['semester_id' => $this->offering->semester_id]);
    expect(User::where('student_id', 'like', 'BULK-%')->count())->toBe(0);
    $this->tasks->run($review->id);
    expect($review->fresh()->result['review']['error_count'])->toBe(0);
    $import = $this->tasks->confirm($review->fresh(), $this->actor, $this->campus);
    expect(User::where('student_id', 'like', 'BULK-%')->count())->toBe(0);
    $this->tasks->run($import->id);
    expect($import->fresh()->status)->toBe('completed')->and(User::where('student_id', 'like', 'BULK-%')->count())->toBe(1000);
});

it('expires private files and marks abandoned jobs without touching retained workbooks', function () {
    $task = $this->tasks->enqueue($this->actor, $this->campus, 'staff-export', 'Old export', ['ids' => [$this->actor->id]]);
    $this->tasks->run($task->id);
    $task->refresh()->update(['completed_at' => now()->subDays(8)]);
    Storage::disk('local')->put('class-records/retained.xlsx', 'retained workbook');
    $this->get(route('background.download', $task))->assertStatus(410);
    $abandoned = ($this->review)();
    $abandoned->update(['status' => 'processing', 'started_at' => now()->subMinutes(31)]);
    $this->artisan('background:maintain')->assertSuccessful();
    expect(BackgroundTask::find($task->id))->toBeNull()->and($abandoned->fresh()->status)->toBe('failed');
    Storage::disk('local')->assertMissing($task->file_path);
    Storage::disk('local')->assertExists('class-records/retained.xlsx');
});

it('parses a class record workbook in the worker and retains the permanent copy', function () {
    $this->actor->givePermissionTo(Permission::findOrCreate('page_ClassRecords', 'web'));
    $book = new Spreadsheet;
    $book->getActiveSheet()->setTitle('Class A')->setCellValue('A1', 'Student ID');
    $path = tempnam(sys_get_temp_dir(), 'bg-workbook-');
    try {
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $upload = UploadedFile::fake()->createWithContent('class-record.xlsx', file_get_contents($path));
    } finally {
        $book->disconnectWorksheets();
        unlink($path);
    }
    Livewire::test(ClassRecords::class)->callTableAction('upload', data: ['file' => $upload, 'period' => 'midterm'])->assertHasNoTableActionErrors();
    expect(ClassRecordUpload::count())->toBe(0);
    $task = BackgroundTask::where('kind', 'workbook-upload')->sole();
    $this->tasks->run($task->id);
    expect($task->fresh()->status)->toBe('completed', $task->fresh()->error ?? '');
    $record = ClassRecordUpload::sole();
    expect($record->uploaded_by)->toBe($this->actor->id)->and($record->sheets()->pluck('sheet_name')->all())->toBe(['Class A']);
    Storage::disk('local')->assertExists($record->file_path);
    Storage::disk('local')->assertMissing($task->payload['source']);
});
