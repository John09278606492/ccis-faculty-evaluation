<?php

namespace App\Filament\Resources\AcademicStudentResource\Pages;

use App\Filament\Concerns\ShowsEvaluationWorkflow;
use App\Filament\Pages\BackgroundProcessing;
use App\Filament\Resources\AcademicStudentResource;
use App\Services\AcademicPeriod;
use App\Services\AcademicStudents;
use App\Services\BackgroundTasks;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAcademicStudents extends ListRecords
{
    use ShowsEvaluationWorkflow;

    protected static string $view = 'filament.resources.workflow-list';

    public function getTitle(): string
    {
        return 'Students';
    }

    public function getSubheading(): ?string
    {
        return 'Upload the campus student list, then review and confirm the import.';
    }

    protected static string $resource = AcademicStudentResource::class;

    protected function getHeaderActions(): array
    {
        $period = fn () => AcademicPeriod::current(filament()->getTenant());
        $departments = fn () => app(AcademicStudents::class)->departments(auth()->user(), filament()->getTenant())->pluck('name', 'code');

        $actions = [
            Actions\CreateAction::make()->label('Add Student'),
            Actions\Action::make('syncImportedCourses')->label('Sync Courses')->icon('heroicon-o-book-open')
                ->visible(fn () => auth()->user()->can('manage_academic_students') && auth()->user()->can('import_academic_students') && auth()->user()->can('create_course'))
                ->modalSubmitActionLabel('Check File')->form([
                    Forms\Components\Placeholder::make('instructions')->content('We will check enrolments for missing courses. Review the changes before confirming.'),
                    Forms\Components\Select::make('semester_id')->label('Academic Period')->required()->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default($period),
                    Forms\Components\Select::make('department_codes')->label('Departments')->multiple()->required()->options($departments)->default(fn () => $departments()->keys()->all()),
                ])->action(function (array $data) {
                    AcademicPeriod::validate(filament()->getTenant(), $data['semester_id']);
                    app(BackgroundTasks::class)->enqueue(auth()->user(), filament()->getTenant(), 'course-review', 'Review courses from enrolments', $data);
                    Notification::make()->success()->title('Course review queued')->body('You can continue working. Check your notification bell when ready.')->send();
                }),
            Actions\Action::make('exampleCsv')->label('CSV Template')->icon('heroicon-o-arrow-down-tray')
                ->extraAttributes(['data-guide' => 'master-template'])
                ->action(function () {
                    $department = app(AcademicStudents::class)->departments(auth()->user(), filament()->getTenant())->first();

                    return response()->streamDownload(function () use ($department) {
                        $out = fopen('php://output', 'w');
                        fputcsv($out, ['student_id', 'first_name', 'middle_name', 'last_name', 'email', 'department_code', 'sections', 'program', 'total_units',
                            'Subject Code', 'Description', 'Lec', 'Lab', 'Units', 'Subject Code', 'Description', 'Lec', 'Lab', 'Units']);
                        fputcsv($out, ['SAMPLE-001', 'Juan', '', 'Dela Cruz', '', $department?->code ?? 'DEPARTMENT-CODE', 'BSIT 3A|BSIT 2B', 'BSIT', 6,
                            'SAMPLE-SUBJECT-1', 'Replace with actual subject one', 3, 0, 3, 'SAMPLE-SUBJECT-2', 'Replace with actual subject two', 3, 0, 3]);
                        fclose($out);
                    }, 'academic-students-example.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
            Actions\Action::make('importStudents')->label('Upload Students')->icon('heroicon-o-document-arrow-up')
                ->extraAttributes(['data-guide' => 'master-upload-action'])
                ->visible(fn () => auth()->user()->can('import_academic_students') && auth()->user()->can('manage_academic_students'))
                ->modalSubmitActionLabel('Check File')->form([
                    Forms\Components\Placeholder::make('uploadTour')->hiddenLabel()->content(fn () => view('components.guided-help', ['context' => 'master-upload', 'compact' => true])),
                    Forms\Components\Placeholder::make('instructions')->content('Upload the campus report. We will check it before you confirm any changes.'),
                    Forms\Components\Select::make('semester_id')->label('Academic Period')->required()->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default($period)->extraFieldWrapperAttributes(['data-guide' => 'master-period']),
                    Forms\Components\Select::make('department_codes')->label('Departments')->multiple()->options($departments)->default(fn () => $departments()->keys()->all())
                        ->extraFieldWrapperAttributes(['data-guide' => 'master-departments'])
                        ->helperText('Leave empty for all departments. New departments appear in the review.'),
                    Forms\Components\FileUpload::make('file')->label('Student File')->helperText('Upload the original campus Excel report or CSV, up to 5 MB. No manual column filtering is needed; unrelated columns and worksheets are ignored.')->required()->disk('local')->storeFiles(false)->maxSize(5120)
                        ->extraFieldWrapperAttributes(['data-guide' => 'master-file'])
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']),
                ])->action(function (array $data) {
                    AcademicPeriod::validate(filament()->getTenant(), $data['semester_id']);
                    $file = is_array($data['file']) ? reset($data['file']) : $data['file'];
                    app(BackgroundTasks::class)->upload(auth()->user(), filament()->getTenant(), $file, 'student-review', 'Validate student master list',
                        ['semester_id' => (int) $data['semester_id'], 'department_codes' => $data['department_codes'] ?? []]);
                    Notification::make()->success()->title('Student review queued')->body('We will notify you when the file is ready to review. You can continue working.')->send();
                }),
        ];
        $byName = collect($actions)->keyBy(fn ($action) => $action->getName());

        return [
            $byName['importStudents']->color('primary'),
            $byName['exampleCsv']->color('gray'),
            Actions\Action::make('reviewUploads')->label('Review Uploads')->icon('heroicon-o-check-circle')->color('gray')
                ->visible(fn () => auth()->user()->can('import_academic_students') && auth()->user()->can('manage_academic_students'))
                ->url(fn () => BackgroundProcessing::getUrl()),
            Actions\ActionGroup::make([$byName['create'], $byName['syncImportedCourses']])->label('More')->icon('heroicon-o-ellipsis-horizontal')->button()->color('gray'),
        ];
    }
}
