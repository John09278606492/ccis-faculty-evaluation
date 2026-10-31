<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TeachingClassResource\Pages;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\User;
use App\Services\AcademicPeriod;
use App\Services\AcademicStudents;
use App\Services\AssignedDataAccess;
use App\Services\FacultySectioning;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TeachingClassResource extends CourseOfferingResource
{
    protected static ?string $slug = 'teaching-classes';

    protected static ?string $navigationLabel = 'Teaching Classes';

    protected static ?string $modelLabel = 'Teaching Class';

    protected static ?string $pluralModelLabel = 'Teaching Classes';

    protected static ?string $navigationGroup = 'Faculty Evaluation';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return parent::canViewAny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('course_id')->where(fn ($q) => $q->whereDoesntHave('evaluationForm')->orWhereHas('evaluationForm', fn ($f) => $f->where('settings->annex', '!=', 'B')->orWhereNull('settings->annex')));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Class Details')->schema([
                Forms\Components\Select::make('semester_id')->label('Academic Period')->required()->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default(fn () => AcademicPeriod::current(filament()->getTenant())),
                Forms\Components\Select::make('course_id')->label('Subject')->required()->searchable()
                    ->options(fn () => app(AssignedDataAccess::class)->apply(Course::query(), auth()->user(), filament()->getTenant())->with('department')->get()->mapWithKeys(fn ($c) => [$c->id => $c->department->code.' · '.$c->code.' — '.$c->name])),
                Forms\Components\Select::make('user_id')->label('Faculty')->required()->searchable()
                    ->options(fn () => DB::table('users')->where('is_instructor', true)->whereIn('id', DB::table('campus_user')->select('user_id')->where('campus_id', filament()->getTenant()->id))->orderBy('name')->pluck('name', 'id'))
                    ->helperText('Includes visiting faculty from other departments in this campus.'),
                Forms\Components\TextInput::make('section')->label('Class reference (optional)')->placeholder('Optional reference')->maxLength(20)
                    ->helperText('Optional label; assign student sections after saving.'),
                Forms\Components\Toggle::make('is_active')->default(true),
            ])->columns(2),
            Forms\Components\Placeholder::make('sectionGuide')->hiddenLabel()->content('After saving, choose Assign Sections to select students and supervisors.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['course', 'evaluatee', 'semester.schoolYear'])->withCount('students'))
            ->columns([
                Tables\Columns\TextColumn::make('course.code')->label('Subject')->description(fn (CourseOffering $record) => $record->course?->name)->searchable(['code', 'name'])->wrap()->grow(),
                Tables\Columns\TextColumn::make('evaluatee.name')->label('Faculty')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('assigned_sections')->label('Assigned sections')->badge()->state(function (CourseOffering $record) {
                    if (! $record->students_count) {
                        return static::sharedCountLabel($record) !== 0 ? ['Shared faculty roster'] : [];
                    }
                    if (($record->evaluator_selection['source'] ?? null) !== 'sections_v1') {
                        if (($record->evaluator_selection['source'] ?? null) === 'auto_enrolment_v1') {
                            $labels = array_values(array_unique(array_filter($record->evaluator_selection['report_sections'] ?? [])));
                            if ($record->evaluator_selection['auto_match']['pending_section'] ?? 0) {
                                $labels[] = 'Section pending';
                            }

                            return $labels ?: ['Section pending'];
                        }

                        return ['Needs review'];
                    }

                    return array_map(fn ($key) => rawurldecode(explode('~', $key, 3)[2] ?? ''), $record->evaluator_selection['sections'] ?? []);
                })->placeholder('Not assigned'),
                Tables\Columns\TextColumn::make('students_count')->label('Evaluators')->sortable()->badge()
                    ->state(fn (CourseOffering $record) => $record->students_count ?: static::sharedCountLabel($record))
                    ->description(fn (CourseOffering $record) => static::evaluatorStatus($record))
                    ->color(fn (CourseOffering $record) => $record->is_active && $record->students_count ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('semester.name')->label('Academic period')->description(fn (CourseOffering $record) => $record->semester?->schoolYear?->name)->toggleable(isToggledHiddenByDefault: true),
            ])->filters([
                Tables\Filters\SelectFilter::make('semester_id')->label('Academic period')->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default(fn () => AcademicPeriod::current(filament()->getTenant())),
                Tables\Filters\SelectFilter::make('department')->label('Department')->options(fn () => app(AcademicStudents::class)->departments(auth()->user(), filament()->getTenant())->pluck('code', 'id'))
                    ->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($q, $id) => $q->whereHas('course', fn ($c) => $c->where('department_id', $id)))),
                Tables\Filters\SelectFilter::make('user_id')->label('Faculty')->searchable()
                    ->options(fn () => User::whereIn('id', CourseOffering::query()->select('user_id'))->orderBy('name')->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('evaluation_status')->label('Evaluation status')->options([
                    'not_in_evaluation' => 'Not in any evaluation yet', 'in_evaluation' => 'Already in an evaluation', 'no_students' => 'No evaluators (students) yet',
                ])->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                    'not_in_evaluation' => $query->whereNull('evaluation_cycle_id'),
                    'in_evaluation' => $query->whereNotNull('evaluation_cycle_id'),
                    'no_students' => $query->whereDoesntHave('students'),
                    default => $query,
                }),
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
            ], layout: FiltersLayout::AboveContent)->filtersFormColumns(['md' => 2, 'xl' => 5])->actions([
                Tables\Actions\Action::make('sectionStudents')->label('Section students')->icon('heroicon-o-rectangle-stack')
                    ->visible(fn (CourseOffering $record) => app(FacultySectioning::class)->allowed(auth()->user(), filament()->getTenant()) && auth()->user()->can('manageEvaluators', $record))
                    ->modalHeading('Student Sectioning')->modalWidth('5xl')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->closeModalByEscaping(false)->closeModalByClickingAway(false)
                    ->stickyModalHeader()->stickyModalFooter()->modalAutofocus(false)
                    ->extraModalWindowAttributes(['style' => 'min-width:0;width:calc(100vw - 2rem);max-width:64rem;'])
                    ->modalContent(fn (CourseOffering $record) => view('filament.forms.faculty-sectioning-panel', ['record' => $record])),
                Tables\Actions\Action::make('assignEvaluators')->label('Review evaluators')->icon('heroicon-o-user-group')
                    ->iconButton()->tooltip('View evaluator roster')
                    ->visible(fn (CourseOffering $record) => auth()->user()->can('manageEvaluators', $record))
                    ->url(fn (CourseOffering $record) => static::getUrl('edit', ['record' => ! $record->students_count && static::sharedCountLabel($record) !== 0
                        ? $record->evaluator_selection['auto_match']['grouped_class_id'] : $record->id, 'activeRelationManager' => '0'])),
                Tables\Actions\EditAction::make()->label('Class details')->iconButton()->tooltip('Edit class details'),
                Tables\Actions\Action::make('deleteEmpty')->label('Delete')->icon('heroicon-o-trash')->color('danger')->iconButton()->tooltip('Delete this unused class')
                    ->visible(fn (CourseOffering $record) => auth()->user()->can('delete', $record) && static::deletable($record))
                    ->requiresConfirmation()->modalHeading('Delete this class?')
                    ->modalDescription(fn (CourseOffering $record) => ($record->course?->code ?? 'This class').' has '.static::emptyReason($record).' and no evaluation or assessment records. This cannot be undone.')
                    ->action(function (CourseOffering $record) {
                        abort_unless(auth()->user()->can('delete', $record) && static::deletable($record->fresh()), 403);
                        $record->delete();
                        Notification::make()->success()->title('Class deleted')->send();
                    }),
            ])->bulkActions([
                Tables\Actions\BulkAction::make('deleteEmpty')->label('Delete unused classes')->icon('heroicon-o-trash')->color('danger')
                    ->visible(fn () => auth()->user()->can('deleteAny', CourseOffering::class))
                    ->requiresConfirmation()->modalHeading('Delete unused classes?')
                    ->modalDescription('Only selected classes with no students or no faculty, and no evaluation or assessment records, are deleted. Others are skipped.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $deleted = 0;
                        foreach ($records as $record) {
                            if (auth()->user()->can('delete', $record) && static::deletable($record->fresh())) {
                                $record->delete();
                                $deleted++;
                            }
                        }
                        Notification::make()->success()->title($deleted.' classes deleted')->body(($records->count() - $deleted).' skipped because they have students and faculty or evaluation records.')->send();
                    }),
            ])->recordUrl(fn (CourseOffering $record) => auth()->user()->can('update', $record) ? static::getUrl('edit', ['record' => $record, 'activeRelationManager' => '0']) : null)
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('evaluator_selection->auto_match->ambiguous')->orderByDesc('evaluator_selection->auto_match->section_required')->orderByDesc('id'))
            ->emptyStateHeading('No teaching classes yet')->emptyStateDescription('Import instructor offerings to match enrolled students automatically.');
    }

    /** Unused setup rows only: never a class in an evaluation or with responses, scores or assessments. */
    public static function deletable(?CourseOffering $record): bool
    {
        if (! $record || $record->evaluation_cycle_id || $record->formSubmissions()->withoutGlobalScope('assigned_data')->exists()
            || $record->ratings()->exists() || $record->quizzes()->exists()) {
            return false;
        }

        return static::emptyReason($record) !== null;
    }

    public static function emptyReason(CourseOffering $record): ?string
    {
        $faculty = User::withoutGlobalScope('assigned_data')->withTrashed()->find($record->user_id);
        $noFaculty = ! $faculty || $faculty->trashed() || ! $faculty->is_instructor || $faculty->is_student;
        $noStudents = ! $record->students()->exists() && static::sharedCountLabel($record) === 0;

        return match (true) {
            $noFaculty && $noStudents => 'no faculty and no students',
            $noFaculty => 'no faculty',
            $noStudents => 'no students',
            default => null,
        };
    }

    public static function sharedCountLabel(CourseOffering $record): string|int
    {
        if (($record->evaluator_selection['source'] ?? '') !== 'auto_enrolment_v1' || empty($record->evaluator_selection['auto_enrolment'])) {
            return 0;
        }
        $s = $record->evaluator_selection['auto_match'] ?? [];

        return ! empty($s['grouped_class_id']) && ! empty($s['grouped_count']) && empty($s['ambiguous']) && empty($s['elsewhere'])
            ? 'Shared '.$s['grouped_count'] : 0;
    }

    public static function evaluatorStatus(CourseOffering $record): string
    {
        if (! $record->is_active) {
            return 'Inactive';
        }
        $s = ($record->evaluator_selection['source'] ?? '') === 'auto_enrolment_v1' ? ($record->evaluator_selection['auto_match'] ?? null) : null;
        if ($record->students_count) {
            if ($s['section_required'] ?? 0) {
                return $s['section_required'].' require sections';
            }

            return $s && ($s['pending_section'] ?? 0) ? $s['pending_section'].' section pending'
                : (in_array($record->evaluator_selection['source'] ?? '', ['sections_v1', 'auto_enrolment_v1'], true) ? 'Ready' : 'Review sections');
        }
        if (! $s) {
            return 'None assigned';
        }
        if ($s['ambiguous']) {
            return $s['ambiguous'].' need teacher / section';
        }
        if ($s['elsewhere']) {
            return $s['elsewhere'].' assigned in another class';
        }
        if (static::sharedCountLabel($record) !== 0) {
            return 'One evaluation per faculty / subject';
        }

        return 'No matching subject enrolments';
    }

    public static function getRelations(): array
    {
        return [CourseOfferingResource\RelationManagers\StudentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTeachingClasses::route('/'), 'create' => Pages\CreateTeachingClass::route('/create'), 'edit' => Pages\EditTeachingClass::route('/{record}/edit')];
    }
}
