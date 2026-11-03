<?php

namespace App\Filament\Resources\TeachingClassResource\Pages;

use App\Filament\Concerns\ShowsEvaluationWorkflow;
use App\Filament\Pages\BackgroundProcessing;
use App\Filament\Resources\TeachingClassResource;
use App\Models\User;
use App\Services\AcademicPeriod;
use App\Services\AcademicSections;
use App\Services\AcademicStudents;
use App\Services\BackgroundTasks;
use App\Services\InstructorOfferingImports;
use App\Services\TeachingRosterExports;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListTeachingClasses extends ListRecords
{
    use ShowsEvaluationWorkflow;

    protected static string $view = 'filament.resources.workflow-list';

    protected static string $resource = TeachingClassResource::class;

    protected function getHeaderActions(): array
    {
        $allowed = fn () => app(InstructorOfferingImports::class)->allowed(auth()->user(), filament()->getTenant());
        $departments = fn () => app(AcademicStudents::class)->departments(auth()->user(), filament()->getTenant())->pluck('code', 'id');

        return [
            Action::make('exportMissingRosters')->label('Export missing rosters')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn () => auth()->user()->can('view_any_course::offering'))
                ->tooltip('Instructors whose active teaching classes need sections or student lists. Uses the current filters.')
                ->action(function () {
                    $ids = app(TeachingRosterExports::class)->query(auth()->user(), filament()->getTenant())
                        ->whereIn('course_offerings.id', $this->getFilteredTableQuery()->reorder()->select('course_offerings.id')->toBase())
                        ->pluck('course_offerings.id')->all();
                    app(BackgroundTasks::class)->enqueue(auth()->user(), filament()->getTenant(), 'class-followup-export', 'Export instructors missing sections or student lists', ['ids' => $ids]);
                    Notification::make()->success()->title('Roster follow-up export queued')->body('You can continue working. Download the CSV from the bell notification when it is ready.')->send();
                }),
            Action::make('importOfferings')->label('Import Offering File')->icon('heroicon-o-document-arrow-up')->visible($allowed)
                ->extraAttributes(['data-guide' => 'offering-upload'])
                ->modalHeading('Import Instructor Offerings')->modalWidth('2xl')->modalSubmitActionLabel('Check File')
                ->form([
                    Forms\Components\Placeholder::make('offeringTour')->hiddenLabel()->content(fn () => view('components.guided-help', ['context' => 'offering-upload', 'compact' => true])),
                    Forms\Components\Placeholder::make('instructions')->hiddenLabel()->content('Upload the original instructor offering Excel or CSV file. No filtering or rearranging is needed. Only relevant columns and rows for this academic period are reviewed.'),
                    Forms\Components\Select::make('semester_id')->label('Academic Period')->required()->live()->extraFieldWrapperAttributes(['data-guide' => 'offering-period'])
                        ->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default(fn () => AcademicPeriod::current(filament()->getTenant())),
                    Forms\Components\Select::make('department_ids')->label('Teaching Departments')->multiple()->options($departments)
                        ->helperText('Leave empty for all departments you can manage.'),
                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('downloadOfferingExample')->label('Download example CSV')->icon('heroicon-o-arrow-down-tray')->color('gray')
                            ->visible(fn (Forms\Get $get) => filled($get('semester_id')))
                            ->url(fn (Forms\Get $get) => route('instructor-offerings.template', ['campus' => filament()->getTenant(), 'semester_id' => $get('semester_id'), 'source_campus_id' => $get('source_campus_id') ?: '6']))
                            ->extraAttributes(['download' => 'instructor-offerings-example.csv', 'data-guide' => 'offering-template']),
                    ]),
                    Forms\Components\FileUpload::make('file')->label('Offering File')->required()->disk('local')->storeFiles(false)->maxSize(5120)
                        ->extraFieldWrapperAttributes(['data-guide' => 'offering-file'])
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->helperText('Excel (.xlsx, .xls) or CSV, up to 5 MB. Extra columns, unrelated sheets and repeated headings are ignored. The example CSV is optional.'),
                    Forms\Components\Toggle::make('create_faculty')->label('Create missing faculty profiles')->default(true)
                        ->visible(fn () => auth()->user()->can('create_user'))->helperText('Profiles have no email or sign-in access until you complete their details.'),
                    Forms\Components\Toggle::make('auto_assign_enrolments')->label('Automatically assign enrolled students')->default(true)
                        ->helperText('Matches subjects, programs and academic period after import. A single faculty roster can include students whose section is pending. Ambiguous teacher choices remain for review.'),
                    Forms\Components\Section::make('Optional Matches')->collapsible()->collapsed()->schema([
                        Forms\Components\Toggle::make('sync_section_rosters')->label('Follow confirmed section uploads')->default(true)
                            ->helperText('Keeps imported class rosters updated until you customize evaluators or prepare an evaluation.'),
                        Forms\Components\TextInput::make('source_campus_id')->label('Campus ID in the export')->default('6')->required()->maxLength(50)->live(onBlur: true)
                            ->helperText('Balilihan uses 6 in this export. It is separate from this system’s campus ID.'),
                        Forms\Components\Repeater::make('faculty_matches')->label('Match Existing Faculty')->defaultItems(0)->addActionLabel('Match Faculty')
                            ->schema([
                                Forms\Components\TextInput::make('source_id')->label('ID in the file')->required()->maxLength(50),
                                Forms\Components\Select::make('user_id')->label('Existing Faculty')->required()->searchable()
                                    ->getSearchResultsUsing(fn (string $search) => User::withoutGlobalScope('assigned_data')->where('is_student', false)->where('is_instructor', true)
                                        ->whereHas('campuses', fn ($q) => $q->whereKey(filament()->getTenant()->id))->where('name', 'like', '%'.$search.'%')->limit(50)->pluck('name', 'id'))
                                    ->getOptionLabelUsing(fn ($value) => User::withoutGlobalScope('assigned_data')->whereHas('campuses', fn ($q) => $q->whereKey(filament()->getTenant()->id))->find($value)?->name),
                            ])->columns(2),
                        Forms\Components\Repeater::make('program_matches')->label('Program Departments')->defaultItems(0)->addActionLabel('Match Program')
                            ->schema([
                                Forms\Components\TextInput::make('program_code')->label('Program code in file')->required(),
                                Forms\Components\Select::make('department_id')->label('Teaching Department')->required()->options($departments),
                            ])->columns(2),
                        Forms\Components\Repeater::make('section_matches')->label('Section Names')->defaultItems(0)->addActionLabel('Match Section')
                            ->schema([
                                Forms\Components\TextInput::make('source_section')->label('Section in file')->required(),
                                Forms\Components\Select::make('section_id')->label('Saved Section')->required()->searchable()
                                    ->options(fn (Forms\Get $get) => app(AcademicSections::class)->query(auth()->user(), filament()->getTenant())
                                        ->where('semester_id', $get('../../semester_id'))->where('is_active', true)->pluck('name', 'id')),
                            ])->columns(2),
                    ]),
                ])->action(function (array $data) {
                    $file = is_array($data['file']) ? reset($data['file']) : $data['file'];
                    unset($data['file']);
                    app(BackgroundTasks::class)->upload(auth()->user(), filament()->getTenant(), $file, 'offering-review', 'Review instructor offerings', $data);
                    Notification::make()->success()->title('Offering review queued')->body('You can continue working. Review the notification before importing.')->send();
                }),
            CreateAction::make()->label('Add Teaching Class')->color('gray'),
            Action::make('reviewUploads')->label('Review Uploads')->icon('heroicon-o-check-circle')->color('gray')->visible($allowed)->url(fn () => BackgroundProcessing::getUrl()),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Multi-instructor subjects need sections. Configure them here; clear enrolment matches are assigned automatically.';
    }
}
