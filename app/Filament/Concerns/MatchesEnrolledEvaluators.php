<?php

namespace App\Filament\Concerns;

use App\Models\AcademicSection;
use App\Services\AcademicSections;
use App\Services\EnrolmentEvaluatorAssignments;
use App\Services\EvaluationSections;
use App\Services\StudentAcademicRecords;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

trait MatchesEnrolledEvaluators
{
    private array $enrolmentPreviewCache = [];

    private function enrolmentPreview(?string $subject, array $programs = []): array
    {
        if (! $subject) {
            return ['rows' => [], 'automatic_ids' => [], 'eligible_ids' => [], 'hash' => ''];
        }
        $key = hash('sha256', json_encode([$subject, $programs]));

        return $this->enrolmentPreviewCache[$key] ??= app(EnrolmentEvaluatorAssignments::class)->preview(auth()->user(), $this->getOwnerRecord()->fresh(), $subject, $programs);
    }

    private function selectedEnrolments(Forms\Get $get): array
    {
        $automatic = $get('use_clear_matches') ? $this->enrolmentPreview($get('subject_key'), $get('programs') ?? [])['automatic_ids'] : [];

        return array_values(array_unique(array_merge($automatic, $get('selected_ids') ?? [])));
    }

    private function matchEnrolledAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('matchEnrolledStudents')->label('Match Enrolled Students')->icon('heroicon-o-academic-cap')
            ->extraAttributes(['data-guide' => 'match-enrolled-students'])
            ->visible(fn () => $this->canAttach() && ! $this->getOwnerRecord()->evaluationForm?->isSupervisorInstrument())
            ->modalHeading('Match Enrolled Students')->modalWidth('4xl')->modalSubmitActionLabel('Assign Reviewed Students')
            ->stickyModalHeader()->stickyModalFooter()
            ->extraModalWindowAttributes(['style' => 'min-width:0;width:calc(100vw - 2rem);max-width:56rem;'])
            ->fillForm(function () {
                $subject = app(StudentAcademicRecords::class)->preferredSubject(auth()->user(), $this->getOwnerRecord());

                return ['subject_key' => $subject, 'programs' => [], 'use_clear_matches' => true, 'selected_ids' => [], 'review_hash' => ''];
            })->steps([
                Forms\Components\Wizard\Step::make('Match Students')->schema([
                    Forms\Components\Placeholder::make('matchContext')->hiddenLabel()->content(fn () => $this->getOwnerRecord()->course?->name.' - '.$this->getOwnerRecord()->instructor?->name),
                    Forms\Components\Placeholder::make('matchHelp')->hiddenLabel()->content('Match by enrolled subject and academic period. Clear class matches are preselected. If several teachers or classes fit, select only the students who attend this class. Sections can be assigned afterward.'),
                    Forms\Components\Select::make('subject_key')->label('Enrolled subject')->required()->live()
                        ->options(fn () => app(EnrolmentEvaluatorAssignments::class)->subjectOptions(auth()->user(), $this->getOwnerRecord()))
                        ->afterStateUpdated(function (Forms\Set $set, $state) {
                            $set('programs', []);
                            $set('selected_ids', []);
                            $set('review_hash', '');
                        }),
                    Forms\Components\Select::make('programs')->label('Degree programs')->multiple()->live()->helperText('Optional: narrow the enrolled students shown below.')
                        ->options(fn (Forms\Get $get) => app(StudentAcademicRecords::class)->cohortOptions(auth()->user(), $this->getOwnerRecord(), 'program', $get('subject_key')))
                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                            $set('selected_ids', []);
                            $set('review_hash', '');
                        }),
                    Forms\Components\Toggle::make('use_clear_matches')->label('Include all clear matches')->default(true)->live(),
                    Forms\Components\Select::make('selected_ids')->label('Specific students to confirm')->multiple()->searchable()
                        ->required(fn (Forms\Get $get) => ! $get('use_clear_matches') || ! $this->enrolmentPreview($get('subject_key'), $get('programs') ?? [])['automatic_ids'])
                        ->extraFieldWrapperAttributes(['data-guide' => 'enrolment-students'])
                        ->getSearchResultsUsing(function (string $search, Forms\Get $get) {
                            return collect($this->enrolmentPreview($get('subject_key'), $get('programs') ?? [])['rows'])->where('eligible', true)
                                ->filter(fn ($r) => str_contains(mb_strtolower($r['student_id'].' '.$r['name']), mb_strtolower($search)))->take(50)
                                ->mapWithKeys(fn ($r) => [$r['id'] => $r['student_id'].' - '.$r['name'].' ('.$r['status'].')'])->all();
                        })->getOptionLabelsUsing(function (array $values, Forms\Get $get) {
                            return collect($this->enrolmentPreview($get('subject_key'), $get('programs') ?? [])['rows'])->whereIn('id', $values)
                                ->mapWithKeys(fn ($r) => [$r['id'] => $r['student_id'].' - '.$r['name']])->all();
                        }),
                    Forms\Components\Placeholder::make('matchCandidates')->hiddenLabel()->content(fn (Forms\Get $get) => view('filament.forms.enrolled-evaluator-matches', [
                        'preview' => $this->enrolmentPreview($get('subject_key'), $get('programs') ?? []),
                        'selected' => $this->selectedEnrolments($get),
                    ])),
                    Forms\Components\Hidden::make('review_hash'),
                ])->afterValidation(fn (Forms\Set $set, Forms\Get $get) => $set('review_hash', $this->enrolmentPreview($get('subject_key'), $get('programs') ?? [])['hash'])),
                Forms\Components\Wizard\Step::make('Review')->schema([
                    Forms\Components\Placeholder::make('matchReview')->hiddenLabel()->content(fn (Forms\Get $get) => count($this->selectedEnrolments($get)).' enrolled students selected. Existing evaluators and responses are kept. Students without a known section can evaluate and appear as Section pending until you assign their section.'),
                    Forms\Components\Checkbox::make('confirmed')->label('These students attend this instructor’s class.')->accepted(),
                ]),
            ])->action(function (array $data) {
                try {
                    $automatic = ! empty($data['use_clear_matches']) ? $this->enrolmentPreview($data['subject_key'], $data['programs'] ?? [])['automatic_ids'] : [];
                    $ids = array_values(array_unique(array_merge($automatic, $data['selected_ids'] ?? [])));
                    $added = app(EnrolmentEvaluatorAssignments::class)->assign(auth()->user(), $this->getOwnerRecord()->fresh(),
                        $data['subject_key'], $data['programs'] ?? [], $ids, $data['review_hash']);
                } catch (ValidationException $e) {
                    $prefix = 'mountedTableActionsData.'.array_key_last($this->mountedTableActionsData).'.';
                    throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => [$prefix.$key => $messages])->all());
                }
                $this->getOwnerRecord()->refresh();
                Notification::make()->success()->title($added.' students assigned')->body('Assign Section is available for selected evaluators. Existing results are preserved.')->send();
            });
    }

    private function reportSectionAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('assignReportSection')->label('Assign Section')->icon('heroicon-o-rectangle-stack')
            ->visible(fn () => $this->canAttach() && auth()->user()->can('manage_academic_students') && auth()->user()->can('view_academic_students')
                && ! $this->getOwnerRecord()->evaluationForm?->isSupervisorInstrument())
            ->modalDescription('Add the selected students to a saved section and use it in this class reports. Pending submitted responses receive the section label; ratings, comments and known historical sections are preserved.')
            ->form([
                Forms\Components\Select::make('section_id')->label('Section')->required()->searchable()
                    ->options(fn () => app(AcademicSections::class)->query(auth()->user(), $this->getOwnerRecord()->campus)
                        ->where('semester_id', $this->getOwnerRecord()->semester_id)->where('is_active', true)->with('department')->get()
                        ->mapWithKeys(fn (AcademicSection $s) => [$s->id => $s->department->code.' - '.$s->name]))
                    ->helperText('Select students from the same department for each section assignment.'),
            ])->action(function (Collection $records, array $data) {
                $result = app(EvaluationSections::class)->assign(auth()->user(), $this->getOwnerRecord(), $records->modelKeys(), (int) $data['section_id']);
                $this->getOwnerRecord()->refresh();
                Notification::make()->success()->title($result['students'].' students assigned to section')
                    ->body($result['responses'].' pending evaluation responses classified. Scores are unchanged.')->send();
            })->deselectRecordsAfterCompletion();
    }
}
