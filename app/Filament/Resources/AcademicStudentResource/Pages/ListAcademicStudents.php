<?php

namespace App\Filament\Resources\AcademicStudentResource\Pages;

use App\Filament\Concerns\ShowsEvaluationWorkflow;
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
        return 'Student Master List';
    }

    public function getSubheading(): ?string
    {
        return 'Step 1: upload the campus master list, review and confirm it. Then go to Sections to upload each section’s students. Imported subjects and departments are reviewed with the master list.';
    }

    protected static string $resource = AcademicStudentResource::class;

    protected function getHeaderActions(): array
    {
        $period = fn () => AcademicPeriod::current(filament()->getTenant());
        $departments = fn () => app(AcademicStudents::class)->departments(auth()->user(), filament()->getTenant())->pluck('name', 'code');

        $actions = [
            Actions\CreateAction::make()->label('Add Student'),
            Actions\Action::make('syncImportedCourses')->label('Sync Courses from Enrolments')->icon('heroicon-o-book-open')
                ->visible(fn () => auth()->user()->can('manage_academic_students') && auth()->user()->can('import_academic_students') && auth()->user()->can('create_course'))
                ->modalSubmitActionLabel('Start Background Review')->form([
                    Forms\Components\Placeholder::make('instructions')->content('Courses are reviewed in the background. Open the notification when ready, then confirm the proposed changes.'),
                    Forms\Components\Select::make('semester_id')->label('Academic Period')->required()->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default($period),
                    Forms\Components\Select::make('department_codes')->label('Departments')->multiple()->required()->options($departments)->default(fn () => $departments()->keys()->all()),
                ])->action(function (array $data) {
                    AcademicPeriod::validate(filament()->getTenant(), $data['semester_id']);
                    app(BackgroundTasks::class)->enqueue(auth()->user(), filament()->getTenant(), 'course-review', 'Review courses from enrolments', $data);
                    Notification::make()->success()->title('Course review queued')->body('You can continue working. Check your notification bell when ready.')->send();
                }),
            Actions\Action::make('exampleCsv')->label('Download Example CSV')->icon('heroicon-o-arrow-down-tray')
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
                ->visible(fn () => auth()->user()->can('import_academic_students') && auth()->user()->can('manage_academic_students'))
                ->modalSubmitActionLabel('Start Background Review')->form([
                    Forms\Components\Placeholder::make('instructions')->content('Upload the original campus CSV or Excel report. Validation, duplicate checks, proposed departments and courses are prepared in the background. Review the results from your notification bell before confirming the import.'),
                    Forms\Components\Select::make('semester_id')->label('Academic Period')->required()->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default($period),
                    Forms\Components\Select::make('department_codes')->label('Existing colleges / departments to import')->multiple()->options($departments)->default(fn () => $departments()->keys()->all())
                        ->helperText('Leave empty to include all. New departments are reviewed for creation only when your permissions allow it.'),
                    Forms\Components\FileUpload::make('file')->required()->disk('local')->storeFiles(false)->maxSize(5120)
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']),
                ])->action(function (array $data) {
                    AcademicPeriod::validate(filament()->getTenant(), $data['semester_id']);
                    $file = is_array($data['file']) ? reset($data['file']) : $data['file'];
                    app(BackgroundTasks::class)->upload(auth()->user(), filament()->getTenant(), $file, 'student-review', 'Validate student master list',
                        ['semester_id' => (int) $data['semester_id'], 'department_codes' => $data['department_codes'] ?? []]);
                    Notification::make()->success()->title('Student review queued')->body('Continue using the system. Your notification bell will announce the review results. No students are imported until you confirm.')->send();
                }),
        ];
        $byName = collect($actions)->keyBy(fn ($action) => $action->getName());

        return [
            $byName['importStudents']->color('primary'),
            $byName['create']->color('gray'),
            $byName['exampleCsv']->color('gray'),
            Actions\ActionGroup::make([$byName['syncImportedCourses']])->label('Other tools')->icon('heroicon-o-ellipsis-horizontal')->button()->color('gray'),
        ];
    }
}
