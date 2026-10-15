<?php

use App\Filament\Pages\ClassRecords;
use App\Filament\Pages\MyQuizzes;
use App\Filament\Pages\QuizBuilder;
use App\Filament\Pages\TakeQuiz;
use App\Filament\Resources\QuizResource\Pages\EditQuiz;
use App\Filament\Resources\QuizResource\Pages\ListQuizzes;
use App\Filament\Resources\QuizResource\RelationManagers\AttemptsRelationManager;
use App\Models\ClassRecordUpload;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\ClassRecordSyncService;
use App\Services\WorkbookPreviewService;
use App\Support\CodeProject;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Permission;

function quizWorkflowFixture(): array
{
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $student->update(['student_id' => '2026-0001']);
    $student->givePermissionTo(Permission::findOrCreate('page_MyQuizzes', 'web'));
    $instructor = $offering->instructor;
    $instructor->assignRole('super_admin');
    foreach (['update_quiz', 'view_any_quiz', 'view_quiz', 'create_quiz', 'page_ClassRecords'] as $permission) {
        $instructor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($offering->campus, isQuiet: true);
    $quiz = Quiz::create(['course_offering_id' => $offering->id, 'auto_sync_class_record' => false, 'title' => 'PHP Basics', 'type' => 'quiz', 'duration_minutes' => 20, 'max_attempts' => 2, 'is_published' => false, 'assign_to_all' => true]);
    $question = $quiz->questions()->create(['type' => 'short_answer', 'question_text' => 'Name the output statement', 'points' => 10, 'order' => 1, 'correct_answer' => ['echo']]);
    $quiz->update(['is_published' => true]);

    return compact('student', 'offering', 'instructor', 'quiz', 'question');
}

function classRecordFixture(string $format = 'Xlsx'): array
{
    $fixture = quizWorkflowFixture();
    Storage::fake('local');
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet()->setTitle('Class A');
    $sheet->mergeCells('D10:G10')->setCellValue('D10', 'Quizzes & Topic Analysis');
    $sheet->setCellValue('C12', 'Student ID')->setCellValue('D12', 'Quiz 1')->setCellValue('E12', 'Total Score');
    $sheet->setCellValue('B14', 'HIGHEST POSSIBLE SCORE')->setCellValue('D14', 5)->setCellValue('E14', '=SUM(D14:D14)');
    $sheet->setCellValue('A15', 1)->setCellValue('B15', 'First Student')->setCellValue('C15', '2026-0001')->setCellValue('D15', 4)->setCellValue('E15', '=SUM(D15:D15)');
    $sheet->setCellValue('A17', 2)->setCellValue('B17', 'Second Student')->setCellValue('C17', '2026-0002')->setCellValue('E17', '=SUM(D17:D17)');
    $sheet->setCellValue('B19', 'This is a note, not a student');
    $book->createSheet()->setTitle('Other')->setCellValue('A1', '=1+2');
    $path = 'class-records/template.'.strtolower($format);
    Storage::disk('local')->makeDirectory('class-records');
    IOFactory::createWriter($book, $format)->save(Storage::disk('local')->path($path));
    $upload = ClassRecordUpload::create(['period' => 'midterm', 'original_filename' => basename($path), 'file_path' => $path, 'uploaded_by' => $fixture['instructor']->id]);
    $record = $upload->sheets()->create(['sheet_name' => 'Class A', 'period' => 'midterm', 'course_offering_id' => $fixture['offering']->id, 'student_id_header' => 'Student ID']);
    $second = User::factory()->create(['student_id' => '2026-0002']);
    $fixture['offering']->students()->attach($second);

    return [...$fixture, 'record' => $record, 'path' => $path, 'second' => $second];
}

function gradedAttempt(Quiz $quiz, User $student, int $number = 1, string $status = 'graded', float $score = 8): QuizAttempt
{
    return $quiz->attempts()->create(['user_id' => $student->id, 'attempt_number' => $number, 'started_at' => now(), 'submitted_at' => now(), 'status' => $status, 'score' => $score, 'max_score' => 10]);
}

it('does not consume an attempt until the student explicitly starts', function () {
    extract(quizWorkflowFixture());
    $this->actingAs($student);
    Livewire::test(TakeQuiz::class, ['quiz' => $quiz])->assertSet('readyToStart', true)->assertSee('Start attempt')
        ->call('beginAttempt')->assertSet('readyToStart', false)->assertSet('reviewMode', false);
    expect($quiz->attempts()->count())->toBe(1);
    Livewire::test(TakeQuiz::class, ['quiz' => $quiz])->assertSet('readyToStart', false);
    expect($quiz->attempts()->count())->toBe(1);
});

it('reviews a completed attempt without silently starting the next one', function () {
    extract(quizWorkflowFixture());
    gradedAttempt($quiz, $student);
    $this->actingAs($student);
    Livewire::test(TakeQuiz::class, ['quiz' => $quiz])->assertSet('reviewMode', true)->assertSee('Start another attempt');
    expect($quiz->attempts()->count())->toBe(1);
});

it('rechecks enrollment before starting an attempt', function () {
    extract(quizWorkflowFixture());
    $this->actingAs($student);
    $page = Livewire::test(TakeQuiz::class, ['quiz' => $quiz]);
    $offering->students()->detach($student);
    $page->call('beginAttempt')->assertForbidden();
    expect($quiz->attempts()->count())->toBe(0);
});

it('saves pending question edits when selecting another question', function () {
    extract(quizWorkflowFixture());
    $other = $quiz->questions()->create(['type' => 'essay', 'question_text' => 'Explain PHP', 'points' => 2, 'order' => 2]);
    $this->actingAs($instructor);
    Livewire::test(QuizBuilder::class, ['record' => $quiz->id])->call('selectQuestion', $question->id)
        ->set('selectedQuestionData.question_text', 'What statement prints output?')->call('selectQuestion', $other->id)
        ->assertHasNoErrors()->assertSet('selectedQuestionId', $other->id);
    expect($question->refresh()->question_text)->toBe('What statement prints output?');
});

it('keeps invalid question edits visible instead of losing them', function () {
    extract(quizWorkflowFixture());
    $this->actingAs($instructor);
    Livewire::test(QuizBuilder::class, ['record' => $quiz->id])->call('selectQuestion', $question->id)
        ->set('selectedQuestionData.question_text', '')->call('addQuestion', 'essay')->assertHasErrors('selectedQuestionData.question_text')
        ->assertSet('selectedQuestionId', $question->id);
    expect($quiz->questions()->count())->toBe(1);
});

it('requires exactly one correct multiple choice answer', function () {
    extract(quizWorkflowFixture());
    $this->actingAs($instructor);
    Livewire::test(QuizBuilder::class, ['record' => $quiz->id])->call('addQuestion', 'multiple_choice')
        ->set('selectedQuestionData.options', [['label' => 'A', 'is_correct' => true], ['label' => 'B', 'is_correct' => true]])
        ->call('saveQuestionProperties')->assertHasErrors('selectedQuestionData');
});

it('protects questions once students have started', function () {
    extract(quizWorkflowFixture());
    gradedAttempt($quiz, $student);
    expect(fn () => $question->update(['points' => 1]))->toThrow(ValidationException::class);
});

it('refuses to publish an empty quiz', function () {
    extract(quizWorkflowFixture());
    $draft = Quiz::create(['course_offering_id' => $offering->id, 'title' => 'Empty draft', 'type' => 'quiz', 'is_published' => false]);
    expect(fn () => $draft->update(['is_published' => true]))->toThrow(ValidationException::class);
});

it('previews without writing and syncs across blank rows while preserving formulas and format', function (string $format) {
    extract(classRecordFixture($format));
    gradedAttempt($quiz, $student);
    gradedAttempt($quiz, $second, score: 9);
    $service = new ClassRecordSyncService;
    $before = Storage::disk('local')->get($path);
    expect($service->headers($record))->toHaveKey('Student ID');
    expect($service->preview($quiz, $record)['matched'])->toBe(2);
    expect(Storage::disk('local')->get($path))->toBe($before);
    expect($record->quizColumns()->count())->toBe(0);
    $result = $service->sync($quiz, $record);
    expect($result['matched'])->toBe(2)->and($result['column'])->toBe('E');
    expect(Storage::disk('local')->get($path.'.before-sync'))->toBe($before);
    $book = IOFactory::load(Storage::disk('local')->path($path));
    expect($book->getSheetByName('Class A')->getCell('E15')->getValue())->toEqual(8);
    expect($book->getSheetByName('Class A')->getCell('E17')->getValue())->toEqual(9);
    expect($book->getSheetByName('Class A')->getCell('F17')->getValue())->toBe('=SUM(D17:E17)');
    expect($book->getSheetByName('Other')->getCell('A1')->getValue())->toBe('=1+2');
    expect(IOFactory::identify(Storage::disk('local')->path($path)))->toBe($format);
    $service->sync($quiz, $record);
    expect($record->quizColumns()->count())->toBe(1);
})->with(['Xlsx', 'Xls']);

it('excludes partial grades and never substitutes an older final attempt', function () {
    extract(classRecordFixture());
    gradedAttempt($quiz, $student);
    gradedAttempt($quiz, $student, 2, 'submitted', 2);
    gradedAttempt($quiz, $second, score: 9);
    $result = (new ClassRecordSyncService)->sync($quiz, $record);
    expect($result['matched'])->toBe(1)->and($result['no_score_yet'])->toContain('2026-0001');
    $book = IOFactory::load(Storage::disk('local')->path($path));
    expect($book->getSheetByName('Class A')->getCell('E15')->getValue())->toBeNull();
});

it('leaves the workbook and column mappings intact when validation fails', function () {
    extract(classRecordFixture());
    gradedAttempt($quiz, $student);
    $record->update(['student_id_header' => 'Wrong header']);
    $before = Storage::disk('local')->get($path);
    expect(fn () => (new ClassRecordSyncService)->sync($quiz, $record))->toThrow(RuntimeException::class);
    expect(Storage::disk('local')->get($path))->toBe($before)->and($record->quizColumns()->count())->toBe(0);
});

it('rejects duplicate spreadsheet IDs instead of writing a score to two students', function () {
    extract(classRecordFixture());
    $book = IOFactory::load(Storage::disk('local')->path($path));
    $book->getSheetByName('Class A')->setCellValue('C17', '2026-0001');
    IOFactory::createWriter($book, 'Xlsx')->save(Storage::disk('local')->path($path));
    expect(fn () => (new ClassRecordSyncService)->inspect($record, $offering, 'Student ID'))->toThrow(RuntimeException::class, 'Duplicate student IDs');
});

it('renders the quiz list and guided class record mapping for instructors', function () {
    extract(classRecordFixture());
    $this->actingAs($instructor);
    Livewire::test(ListQuizzes::class)->assertSuccessful()->searchTable('PHP')->assertCanSeeTableRecords([$quiz]);
    Livewire::test(ClassRecords::class)->assertSuccessful()->mountTableAction('map', $record)
        ->assertSee('Roster check')->assertSee('2 of 2');
});

it('returns an incomplete published quiz to draft when a new question needs an answer key', function () {
    extract(quizWorkflowFixture());
    $this->actingAs($instructor);
    Livewire::test(QuizBuilder::class, ['record' => $quiz->id])->call('addQuestion', 'multiple_choice')->assertHasNoErrors();
    expect($quiz->refresh()->is_published)->toBeFalse();
});

it('rejects malformed reorder requests without changing question order', function () {
    extract(quizWorkflowFixture());
    $this->actingAs($instructor);
    Livewire::test(QuizBuilder::class, ['record' => $quiz->id])->call('reorderQuestions', [$question->id, $question->id])->assertStatus(422);
    expect($question->refresh()->order)->toBe(1);
});

it('uses the deadline for activities even when an old duration remains saved', function () {
    extract(quizWorkflowFixture());
    $deadline = now()->addDays(2)->startOfMinute();
    $quiz->update(['type' => 'activity', 'available_until' => $deadline]);
    $this->actingAs($student);
    Livewire::test(TakeQuiz::class, ['quiz' => $quiz])->call('beginAttempt')->assertHasNoErrors();
    expect($quiz->attempts()->first()->expires_at->equalTo($deadline))->toBeTrue();
});

it('saves a guided mapping and rejects a header with no matching students', function () {
    extract(classRecordFixture());
    $record->update(['course_offering_id' => null, 'student_id_header' => '']);
    $this->actingAs($instructor);
    Livewire::test(ClassRecords::class)->callTableAction('map', $record, data: ['course_offering_id' => $offering->id, 'student_id_header' => 'Student ID'])
        ->assertHasNoTableActionErrors();
    expect($record->refresh()->course_offering_id)->toBe($offering->id)->and($record->student_id_header)->toBe('Student ID');
    Livewire::test(ClassRecords::class)->callTableAction('map', $record, data: ['course_offering_id' => $offering->id, 'student_id_header' => 'Quiz 1'])
        ->assertHasTableActionErrors();
    expect($record->refresh()->student_id_header)->toBe('Student ID');
});

it('does not create a spreadsheet column when there are no final grades', function () {
    extract(classRecordFixture());
    gradedAttempt($quiz, $student, status: 'submitted');
    $before = Storage::disk('local')->get($path);
    expect(fn () => (new ClassRecordSyncService)->sync($quiz, $record))->toThrow(RuntimeException::class, 'No fully graded scores');
    expect(Storage::disk('local')->get($path))->toBe($before)->and($record->quizColumns()->count())->toBe(0);
});

it('keeps instructor previews separate from saved student attempts across requests', function () {
    extract(quizWorkflowFixture());
    $this->actingAs($instructor);
    Livewire::test(TakeQuiz::class, ['quiz' => $quiz])->assertSet('previewMode', true)
        ->set('answers.'.$question->id, 'echo')->call('submit')->assertHasNoErrors();
    expect($quiz->attempts()->count())->toBe(0);
});

it('grades manual answers and updates the final total through the instructor form', function () {
    extract(quizWorkflowFixture());
    $essay = $quiz->questions()->create(['type' => 'essay', 'question_text' => 'Explain the statement', 'points' => 5, 'order' => 2]);
    $attempt = gradedAttempt($quiz, $student, status: 'submitted', score: 10);
    $attempt->update(['max_score' => 15]);
    $attempt->answers()->create(['quiz_question_id' => $question->id, 'answer_text' => 'echo', 'points_awarded' => 10, 'is_correct' => true]);
    $answer = $attempt->answers()->create(['quiz_question_id' => $essay->id, 'answer_text' => 'It prints text.']);
    $this->actingAs($instructor);
    Livewire::test(AttemptsRelationManager::class, ['ownerRecord' => $quiz, 'pageClass' => EditQuiz::class])
        ->callTableAction('grade', $attempt, data: ['awards' => [$answer->id => ['points' => 4, 'feedback' => 'Good explanation.']]])
        ->assertHasNoTableActionErrors();
    expect($attempt->refresh()->status)->toBe('graded')->and((float) $attempt->score)->toBe(14.0);
    expect($answer->refresh()->feedback)->toBe('Good explanation.');
});

it('allows an activity deadline without requiring an opening date', function () {
    extract(quizWorkflowFixture());
    $this->actingAs($instructor);
    Livewire::test(EditQuiz::class, ['record' => $quiz->id])
        ->fillForm(['type' => 'activity', 'available_from' => null, 'available_until' => now()->addDay()->format('Y-m-d H:i:s')])
        ->call('save')->assertHasNoFormErrors();
    expect($quiz->refresh()->type)->toBe('activity')->and($quiz->available_from)->toBeNull();
});

it('rejects a closing date before the opening date', function () {
    extract(quizWorkflowFixture());
    $this->actingAs($instructor);
    Livewire::test(EditQuiz::class, ['record' => $quiz->id])
        ->fillForm(['available_from' => now()->addDays(2)->format('Y-m-d H:i:s'), 'available_until' => now()->addDay()->format('Y-m-d H:i:s')])
        ->call('save')->assertHasFormErrors(['available_until']);
});

it('previews saved and proposed cells without modifying the workbook and explains partial grading', function () {
    extract(classRecordFixture());
    foreach (range(3, 27) as $number) {
        $offering->students()->attach(User::factory()->create(['student_id' => '2026-'.str_pad($number, 4, '0', STR_PAD_LEFT)]));
    }
    gradedAttempt($quiz, $student);
    gradedAttempt($quiz, $second, status: 'in_progress');
    $before = Storage::disk('local')->get($path);
    $service = app(WorkbookPreviewService::class);
    $preview = $service->preview($record, $quiz, proposed: true);
    expect($preview['error'])->toBeNull()->and($preview['plan']['matched'])->toBe(1)
        ->and($preview['plan']['roster_count'])->toBe(27)->and($preview['plan']['status_counts']['in_progress'])->toBe(1);
    $cell = collect($preview['rows'])->flatMap(fn ($row) => $row['cells'])->firstWhere('address', 'E15');
    expect($cell['changed'])->toBeTrue()->and($cell['display'])->toBe('8')
        ->and(Storage::disk('local')->get($path))->toBe($before)->and($record->quizColumns()->count())->toBe(0);
    $other = $service->preview($record, worksheet: 'Other');
    expect($other['rows'][0]['cells'][0]['formula'])->toBe('=1+2');
    $this->actingAs($instructor);
    Livewire::test(ClassRecords::class)->mountTableAction('previewWorkbook', $record)->assertSee('Current saved workbook')->assertSee('First Student');
    Livewire::test(EditQuiz::class, ['record' => $quiz->id])->mountAction('syncClassRecord')
        ->assertSee('1 of 27 students ready to sync.')->assertSee('Proposed result')->assertSee('Attempt is still marked in progress');
    expect(app(ClassRecordSyncService::class)->sync($quiz, $record)['matched'])->toBe(1);
});

it('syncs without calculating unavailable external workbooks and preserves their package links on repeated sync', function () {
    extract(classRecordFixture());
    gradedAttempt($quiz, $student);
    $fullPath = Storage::disk('local')->path($path);
    $book = IOFactory::load($fullPath);
    $book->getSheetByName('Other')->setCellValue('A1', "='[1]Source'!A1");
    IOFactory::createWriter($book, 'Xlsx')->setPreCalculateFormulas(false)->save($fullPath);
    $zip = new ZipArchive;
    $zip->open($fullPath);
    $zip->addFromString('xl/workbook.xml', str_replace('<calcPr', '<externalReferences><externalReference r:id="rIdExternalTest"/></externalReferences><calcPr', $zip->getFromName('xl/workbook.xml')));
    $zip->addFromString('xl/_rels/workbook.xml.rels', str_replace('</Relationships>', '<Relationship Id="rIdExternalTest" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/externalLink" Target="externalLinks/externalLink1.xml"/></Relationships>', $zip->getFromName('xl/_rels/workbook.xml.rels')));
    $zip->addFromString('[Content_Types].xml', str_replace('</Types>', '<Override PartName="/xl/externalLinks/externalLink1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.externalLink+xml"/></Types>', $zip->getFromName('[Content_Types].xml')));
    $link = '<?xml version="1.0"?><externalLink xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><externalBook r:id="rId1"><sheetNames><sheetName val="Source"/></sheetNames></externalBook></externalLink>';
    $relation = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/externalLinkPath" Target="file:///missing.xlsx" TargetMode="External"/></Relationships>';
    $zip->addFromString('xl/externalLinks/externalLink1.xml', $link);
    $zip->addFromString('xl/externalLinks/_rels/externalLink1.xml.rels', $relation);
    $zip->close();
    $preview = app(WorkbookPreviewService::class)->preview($record, worksheet: 'Other');
    expect($preview['error'])->toBeNull()->and($preview['external_formula_count'])->toBe(1);
    foreach (range(1, 2) as $iteration) {
        expect(app(ClassRecordSyncService::class)->sync($quiz, $record)['matched'])->toBe(1);
        $zip->open($fullPath);
        expect($zip->getFromName('xl/externalLinks/externalLink1.xml'))->toBe($link)
            ->and($zip->getFromName('xl/externalLinks/_rels/externalLink1.xml.rels'))->toBe($relation)
            ->and(substr_count($zip->getFromName('xl/workbook.xml'), '<externalReferences>'))->toBe(1);
        $zip->close();
        $saved = IOFactory::load($fullPath);
        expect($saved->getSheetByName('Other')->getCell('A1')->getValue())->toBe("='[1]Source'!A1")
            ->and((float) $saved->getSheetByName('Class A')->getCell('E15')->getValue())->toBe(8.0);
        $saved->disconnectWorksheets();
    }
    expect($record->quizColumns()->count())->toBe(1);
});

it('syncs the scheduled opening date as an Excel date and highlights it in the preview', function () {
    extract(classRecordFixture());
    $quiz->update(['available_from' => '2026-10-01 08:00:00']);
    gradedAttempt($quiz, $student)->update(['started_at' => '2026-10-02 09:00:00']);
    $preview = app(WorkbookPreviewService::class)->preview($record, $quiz, proposed: true);
    expect($preview['plan']['opened_date'])->toBe('2026-10-01')->and($preview['plan']['date_source'])->toBe('Scheduled opening date');
    $date = collect($preview['rows'])->flatMap(fn ($row) => $row['cells'])->firstWhere('address', 'E11');
    expect($date['changed'])->toBeTrue()->and($date['display'])->toBe('Oct 1, 2026');
    app(ClassRecordSyncService::class)->sync($quiz, $record);
    $quiz->update(['available_from' => '2026-10-03 08:00:00']);
    app(ClassRecordSyncService::class)->sync($quiz, $record);
    $saved = IOFactory::load(Storage::disk('local')->path($path));
    expect($saved->getSheetByName('Class A')->getCell('E11')->getFormattedValue())->toBe('Oct 3, 2026')
        ->and($record->quizColumns()->count())->toBe(1);
});

it('uses the first student start when there is no scheduled opening date and never invents a date', function () {
    extract(classRecordFixture());
    expect(app(ClassRecordSyncService::class)->preview($quiz, $record)['opened_date'])->toBeNull();
    gradedAttempt($quiz, $student)->update(['started_at' => '2026-09-29 23:00:00']);
    gradedAttempt($quiz, $second)->update(['started_at' => '2026-09-28 10:00:00']);
    $plan = app(ClassRecordSyncService::class)->preview($quiz, $record);
    expect($plan['opened_date'])->toBe('2026-09-28')->and($plan['date_source'])->toBe('Earliest student start');
});

it('syncs activity dates and scores into Activities and keeps their mapping when a quiz column is inserted', function () {
    extract(classRecordFixture());
    $book = IOFactory::load(Storage::disk('local')->path($path));
    $sheet = $book->getSheetByName('Class A');
    $sheet->mergeCells('H10:K10')->setCellValue('H10', 'Project, Activities & etc (30%)');
    $sheet->setCellValue('H12', 'Activity No. 1')->setCellValue('I12', 'Total Score');
    IOFactory::createWriter($book, 'Xlsx')->save(Storage::disk('local')->path($path));
    $activity = Quiz::create(['course_offering_id' => $offering->id, 'auto_sync_class_record' => false, 'title' => 'HTML exercise', 'type' => 'activity', 'available_from' => '2026-10-05 08:00:00', 'is_published' => false, 'assign_to_all' => true]);
    $activity->questions()->create(['type' => 'essay', 'question_text' => 'Build a page', 'points' => 10, 'order' => 1]);
    gradedAttempt($activity, $student, score: 7);
    gradedAttempt($quiz, $student);
    $this->actingAs($instructor);
    Livewire::test(EditQuiz::class, ['record' => $activity->id])->mountAction('syncClassRecord')->assertSee('Opening date: 2026-10-05');
    $service = app(ClassRecordSyncService::class);
    expect($service->sync($activity, $record)['column'])->toBe('I');
    $service->sync($quiz, $record);
    expect($service->sync($activity, $record)['column'])->toBe('J');
    $saved = IOFactory::load(Storage::disk('local')->path($path))->getSheetByName('Class A');
    expect($saved->getCell('J11')->getFormattedValue())->toBe('Oct 5, 2026')
        ->and($saved->getCell('J12')->getValue())->toBe('HTML exercise')
        ->and((float) $saved->getCell('J15')->getValue())->toBe(7.0)
        ->and((float) $saved->getCell('E15')->getValue())->toBe(8.0)
        ->and($record->quizColumns()->count())->toBe(2);
});

it('enables automatic syncing by default and writes final grades after commit', function () {
    extract(classRecordFixture());
    expect((new Quiz)->auto_sync_class_record)->toBeTrue();
    $quiz->update(['auto_sync_class_record' => true]);
    $before = Storage::disk('local')->get($path);
    $attempt = gradedAttempt($quiz, $student, status: 'submitted');
    expect(Storage::disk('local')->get($path))->toBe($before);
    DB::transaction(function () use ($attempt, $path, $before) {
        $attempt->update(['status' => 'graded', 'score' => 9]);
        expect(Storage::disk('local')->get($path))->toBe($before);
    });
    expect($record->quizColumns()->count())->toBe(1)->and($quiz->refresh()->class_record_synced_at)->not->toBeNull();
    $saved = IOFactory::load(Storage::disk('local')->path($path));
    expect((float) $saved->getSheetByName('Class A')->getCell('E15')->getValue())->toBe(9.0);
    $attempt->update(['score' => 6]);
    $saved = IOFactory::load(Storage::disk('local')->path($path));
    expect((float) $saved->getSheetByName('Class A')->getCell('E15')->getValue())->toBe(6.0);
});

it('keeps auto-sync off when toggled and catches up when enabled', function () {
    extract(classRecordFixture());
    gradedAttempt($quiz, $student);
    expect($record->quizColumns()->count())->toBe(0);
    $this->actingAs($instructor);
    Livewire::test(EditQuiz::class, ['record' => $quiz->id])->fillForm(['auto_sync_class_record' => true])->call('save')->assertHasNoFormErrors();
    expect($quiz->refresh()->auto_sync_class_record)->toBeTrue()->and($record->quizColumns()->count())->toBe(1);
    Livewire::test(EditQuiz::class, ['record' => $quiz->id])->fillForm(['auto_sync_class_record' => false])->call('save')->assertHasNoFormErrors();
    $before = Storage::disk('local')->get($path);
    $quiz->attempts()->first()->update(['score' => 2]);
    expect($quiz->refresh()->auto_sync_class_record)->toBeFalse()->and(Storage::disk('local')->get($path))->toBe($before);
});

it('does not sync a rolled back grade and keeps grades when a workbook fails', function () {
    extract(classRecordFixture());
    $quiz->update(['auto_sync_class_record' => true]);
    $attempt = gradedAttempt($quiz, $student, status: 'submitted');
    try {
        DB::transaction(function () use ($attempt) {
            $attempt->update(['status' => 'graded']);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('rollback');
    }
    expect($record->quizColumns()->count())->toBe(0);
    Storage::disk('local')->delete($path);
    $attempt->refresh()->update(['status' => 'graded']);
    expect($attempt->refresh()->status)->toBe('graded')->and($quiz->refresh()->class_record_sync_error)->toContain('missing');
});

it('requires one unambiguous instructor-owned destination and retries after mapping', function () {
    extract(classRecordFixture());
    $record->update(['course_offering_id' => null]);
    $quiz->update(['auto_sync_class_record' => true]);
    gradedAttempt($quiz, $student);
    expect($quiz->refresh()->class_record_sync_error)->toContain('Waiting for a class record');
    $record->update(['course_offering_id' => $offering->id]);
    expect($record->quizColumns()->count())->toBe(1)->and($quiz->refresh()->class_record_sync_error)->toBeNull();
});

it('does not guess between grading periods or use another instructors workbook', function () {
    extract(classRecordFixture());
    $other = $record->upload->sheets()->create(['sheet_name' => 'Other', 'period' => 'final', 'course_offering_id' => $offering->id, 'student_id_header' => 'Student ID']);
    $quiz->update(['auto_sync_class_record' => true]);
    $attempt = gradedAttempt($quiz, $student);
    expect($quiz->refresh()->class_record_sync_error)->toContain('Multiple worksheets')->and($record->quizColumns()->count())->toBe(0);
    app(ClassRecordSyncService::class)->sync($quiz, $record);
    $attempt->update(['score' => 4]);
    expect($quiz->refresh()->class_record_sync_error)->toBeNull()->and($other->quizColumns()->count())->toBe(0);
    $before = Storage::disk('local')->get($path);
    $record->upload->update(['uploaded_by' => $second->id]);
    $attempt->update(['score' => 3]);
    expect($quiz->refresh()->class_record_sync_error)->toContain('Waiting for a class record')->and(Storage::disk('local')->get($path))->toBe($before);
});

it('automatically syncs an administrator-uploaded workbook after a final grade is committed', function () {
    extract(classRecordFixture());
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $record->upload->update(['uploaded_by' => $admin->id]);
    $quiz->update(['auto_sync_class_record' => true]);
    $attempt = gradedAttempt($quiz, $student, status: 'submitted');
    DB::transaction(fn () => $attempt->update(['status' => 'graded', 'score' => 7]));
    expect($quiz->refresh()->class_record_sync_error)->toBeNull()
        ->and($quiz->class_record_synced_at)->not->toBeNull()->and($record->quizColumns()->count())->toBe(1);
    $saved = IOFactory::load(Storage::disk('local')->path($path));
    expect((float) $saved->getSheetByName('Class A')->getCell('E15')->getValue())->toBe(7.0);
    $attempt->update(['score' => 9]);
    $saved = IOFactory::load(Storage::disk('local')->path($path));
    expect((float) $saved->getSheetByName('Class A')->getCell('E15')->getValue())->toBe(9.0)
        ->and($record->quizColumns()->count())->toBe(1);
});

it('refreshes published quiz lists and grades from another device without navigation', function () {
    extract(quizWorkflowFixture());
    $this->actingAs($student);
    $quiz->update(['is_published' => false]);
    $page = Livewire::test(MyQuizzes::class)->assertCanNotSeeTableRecords([$quiz]);
    $quiz->update(['is_published' => true]);
    $page->dispatch('classroom-push', kind: 'quiz', quizId: $quiz->id, courseId: $offering->id)->assertCanSeeTableRecords([$quiz]);
    $attempt = gradedAttempt($quiz, $student, status: 'submitted', score: 3);
    $review = Livewire::test(TakeQuiz::class, ['quiz' => $quiz]);
    $attempt->update(['status' => 'graded', 'score' => 9]);
    $review->dispatch('classroom-push', kind: 'attempt', quizId: $quiz->id, courseId: $offering->id)->assertSet('attempt.status', 'graded')->assertSet('attempt.score', '9.00');
});

it('refreshes opening times without consuming attempts and preserves active answer inputs', function () {
    extract(quizWorkflowFixture());
    $this->actingAs($student);
    $quiz->update(['available_from' => now()->addHour()]);
    $page = Livewire::test(TakeQuiz::class, ['quiz' => $quiz])->assertSet('waitingForStart', true);
    $quiz->update(['available_from' => now()->subMinute()]);
    $page->call('refreshLiveState')->assertSet('waitingForStart', false)->assertSet('readyToStart', true);
    expect($quiz->attempts()->count())->toBe(0);
    $page->call('beginAttempt')->set('answers.'.$question->id, 'my draft answer');
    $quiz->update(['title' => 'Updated on another device']);
    $page->call('refreshLiveState')->assertSet('quiz.title', 'Updated on another device')->assertSet('answers.'.$question->id, 'my draft answer');
});

it('warns about remote quiz edits without replacing unsaved form data or overwriting the remote version', function () {
    extract(quizWorkflowFixture());
    $this->actingAs($instructor);
    $page = Livewire::test(EditQuiz::class, ['record' => $quiz->id])->fillForm(['title' => 'Local draft']);
    $quiz->update(['title' => 'Saved on another device']);
    $page->call('checkRemoteChanges')->assertSet('changedElsewhere', true)->assertSet('data.title', 'Local draft');
    $page->call('save')->assertHasFormErrors(['title']);
    expect($quiz->refresh()->title)->toBe('Saved on another device');
});

it('pushes quiz changes only after commit and never broadcasts rolled-back changes', function () {
    extract(quizWorkflowFixture());
    \Illuminate\Support\Facades\Event::fake([\App\Events\ClassroomChanged::class]);
    DB::beginTransaction();
    $quiz->update(['title' => 'Committed title']);
    \Illuminate\Support\Facades\Event::assertNotDispatched(\App\Events\ClassroomChanged::class);
    DB::commit();
    \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\ClassroomChanged::class, fn ($event) =>
        $event->quizId === $quiz->id && $event->courseId === $offering->id && $event->kind === 'quiz');
    \Illuminate\Support\Facades\Event::fake([\App\Events\ClassroomChanged::class]);
    DB::beginTransaction();
    $quiz->update(['title' => 'Rolled back']);
    DB::rollBack();
    \Illuminate\Support\Facades\Event::assertNotDispatched(\App\Events\ClassroomChanged::class);
    expect($quiz->refresh()->title)->toBe('Committed title');
});

it('authorizes live channels by campus and environment without broadcasting private data', function () {
    extract(quizWorkflowFixture());
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
    // The application booted with the null broadcaster; register on the test driver.
    require base_path('routes/channels.php');
    $channel = 'private-'.app()->environment().'.campus.'.$offering->campus->id;
    $this->actingAs($student)->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])->assertOk();
    $this->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-production.campus.'.$offering->campus->id])->assertForbidden();
    $outsider = User::factory()->create();
    $this->actingAs($outsider)->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])->assertForbidden();
    $event = new \App\Events\ClassroomChanged($offering->campus->id, $offering->id, $quiz->id, 'attempt');
    expect($event->broadcastWith())->toBe(['courseId' => $offering->id, 'quizId' => $quiz->id, 'kind' => 'attempt']);
});

it('pushes roster and individual assignment changes including removals', function () {
    extract(quizWorkflowFixture());
    \Illuminate\Support\Facades\Event::fake([\App\Events\ClassroomChanged::class]);
    $quiz->assignedStudents()->attach($student);
    $quiz->assignedStudents()->detach($student);
    $offering->students()->detach($student);
    $student->courseOfferings()->attach($offering);
    \Illuminate\Support\Facades\Event::assertDispatchedTimes(\App\Events\ClassroomChanged::class, 4);
    \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\ClassroomChanged::class, fn ($event) => $event->kind === 'assignment' && $event->quizId === $quiz->id);
    \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\ClassroomChanged::class, fn ($event) => $event->kind === 'enrollment' && $event->courseId === $offering->id);
});

it('builds a multi-file code question with starter files, a reference solution and a run setting', function () {
    extract(quizWorkflowFixture());
    $this->actingAs($instructor);
    $page = Livewire::test(QuizBuilder::class, ['record' => $quiz->id])->call('addQuestion', 'code');
    $code = $quiz->questions()->where('type', 'code')->firstOrFail();
    expect(array_column($code->starterFiles(), 'name'))->toBe(['index.html', 'style.css', 'app.js'])
        ->and($code->codeRunner())->toBe('web');
    $page->set('selectedQuestionData.code_language', 'php')
        ->assertSet('selectedQuestionData.starter_files', CodeProject::encode(CodeProject::preset('php')))
        ->set('selectedQuestionData.starter_files', CodeProject::encode([
            ['name' => 'index.php', 'content' => '<?php require "lib.php";'],
            ['name' => 'lib.php', 'content' => '<?php'],
        ]))
        ->set('selectedQuestionData.reference_files', CodeProject::encode([
            ['name' => 'index.php', 'content' => '<?php require "lib.php"; echo greet();'],
            ['name' => 'lib.php', 'content' => '<?php function greet() { return "hi"; }'],
        ]))
        ->set('selectedQuestionData.allow_run', false)
        ->call('saveQuestionProperties')->assertHasNoErrors();
    $code->refresh();
    expect($code->codeLanguage())->toBe('php')->and(array_column($code->starterFiles(), 'name'))->toBe(['index.php', 'lib.php'])
        ->and($code->referenceFiles()[0]['content'])->toContain('greet()')
        ->and($code->codeRunner())->toBe('php')->and($code->studentsCanRunCode())->toBeFalse();
    $page->set('selectedQuestionData.starter_files', CodeProject::encode([['name' => '../evil.php', 'content' => '']]))
        ->call('saveQuestionProperties')->assertHasErrors('selectedQuestionData');
    expect($code->refresh()->starterFiles()[1]['name'])->toBe('lib.php');
});

it('saves only the question files from a student answer and leaves it for manual grading', function () {
    extract(quizWorkflowFixture());
    $code = $quiz->questions()->create(['type' => 'code', 'question_text' => 'Build a page', 'points' => 5, 'order' => 2, 'options' => [
        'language' => 'web',
        'files' => [['name' => 'index.html', 'content' => '<h1>Start</h1>'], ['name' => 'style.css', 'content' => '']],
    ]]);
    $this->actingAs($student);
    $page = Livewire::test(TakeQuiz::class, ['quiz' => $quiz])->call('beginAttempt')->assertSee('index.html');
    $page->set('answers.'.$code->id, json_encode(['files' => [
        ['name' => 'index.html', 'content' => '<h1>Done</h1>'],
        ['name' => 'extra.php', 'content' => '<?php system("dir");'],
    ]]))->assertHasNoErrors();
    $page->set('answers.'.$code->id, json_encode(['files' => [['name' => 'index.html', 'content' => str_repeat('x', CodeProject::MAX_LENGTH + 1)]]]))
        ->assertHasErrors('answers.'.$code->id);
    $page->set('answers.'.$question->id, 'echo')->call('submit')->assertSet('reviewMode', true);
    $attempt = $quiz->attempts()->firstOrFail();
    $saved = $code->answerFiles($attempt->answers()->where('quiz_question_id', $code->id)->value('answer_text'));
    expect($attempt->status)->toBe('submitted')->and((float) $attempt->score)->toBe(10.0)
        ->and($saved)->toBe([['name' => 'index.html', 'content' => '<h1>Done</h1>'], ['name' => 'style.css', 'content' => '']]);
});

it('loads single-file code questions and answers saved before file tabs', function () {
    extract(quizWorkflowFixture());
    $code = $quiz->questions()->create(['type' => 'code', 'question_text' => 'Write solve()', 'points' => 5, 'order' => 2,
        'options' => ['language' => 'python', 'starter_code' => 'def solve():'], 'correct_answer' => ['reference' => 'def solve(): return 42']]);
    expect($code->starterFiles())->toBe([['name' => 'main.py', 'content' => 'def solve():']])
        ->and($code->referenceFiles())->toBe([['name' => 'main.py', 'content' => 'def solve(): return 42']])
        ->and($code->answerFiles("def solve():\n    return 41"))->toBe([['name' => 'main.py', 'content' => "def solve():\n    return 41"]])
        ->and($code->codeRunner())->toBeNull()->and($code->studentsCanRunCode())->toBeFalse();
});

it('picks the in-browser runner from the project files', function () {
    $files = fn (string ...$names) => array_map(fn ($name) => ['name' => $name, 'content' => ''], $names);
    expect(CodeProject::runner($files('index.html', 'style.css', 'app.js')))->toBe('web')
        ->and(CodeProject::runner($files('index.php', 'style.css')))->toBe('php')
        ->and(CodeProject::runner($files('schema.sql')))->toBe('sql')
        ->and(CodeProject::runner($files('Main.java')))->toBeNull()
        ->and(CodeProject::issues($files('index.html', 'INDEX.html')))->toContain('File names must be unique.')
        ->and(CodeProject::issues($files('run.exe')))->not->toBeEmpty();
});

it('grades a code answer from the attempts tab next to the reference solution', function () {
    extract(quizWorkflowFixture());
    $code = $quiz->questions()->create(['type' => 'code', 'question_text' => 'Write solve()', 'points' => 5, 'order' => 2,
        'options' => ['language' => 'php', 'files' => [['name' => 'index.php', 'content' => '']]],
        'correct_answer' => ['files' => [['name' => 'index.php', 'content' => '<?php echo 42;']]]]);
    $attempt = gradedAttempt($quiz, $student, status: 'submitted', score: 10);
    $attempt->update(['max_score' => 15]);
    $attempt->answers()->create(['quiz_question_id' => $question->id, 'answer_text' => 'echo', 'points_awarded' => 10, 'is_correct' => true]);
    $answer = $attempt->answers()->create(['quiz_question_id' => $code->id, 'answer_text' => CodeProject::encode([['name' => 'index.php', 'content' => '<?php echo 41;']])]);
    $this->actingAs($instructor);
    Livewire::test(AttemptsRelationManager::class, ['ownerRecord' => $quiz, 'pageClass' => EditQuiz::class])
        ->mountTableAction('grade', $attempt)->assertSee('Your Reference Solution')
        ->setTableActionData(['awards' => [$answer->id => ['points' => 3, 'feedback' => 'Off by one.']]])->callMountedTableAction()
        ->assertHasNoTableActionErrors();
    expect($attempt->refresh()->status)->toBe('graded')->and((float) $attempt->score)->toBe(13.0);
});
