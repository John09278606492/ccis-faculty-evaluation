<?php

namespace App\Filament\Pages;

use App\Models\CourseOffering;
use App\Services\EvaluationSubmissionIdentity;
use App\Services\FacultySupervisorEvaluators;
use App\Services\SupervisorEvaluationAssignments;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class SupervisorEvaluations extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.supervisor-evaluations';

    protected static ?string $title = 'My Faculty Evaluations';

    protected static ?string $navigationLabel = 'My Supervisor Tasks';

    protected static ?string $navigationGroup = 'Faculty Evaluation';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 5;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ! $user->is_student && filament()->getTenant() && $user->canAccessTenant(filament()->getTenant())
            && app(FacultySupervisorEvaluators::class)->taskIds($user, filament()->getTenant())->exists();
    }

    private function submitted(CourseOffering $assignment): bool
    {
        return $assignment->formSubmissions()->withoutGlobalScope('assigned_data')->where('form_id', $assignment->form_id)
            ->where('submission_fingerprint', app(EvaluationSubmissionIdentity::class)->fingerprint($assignment, request(), auth()->user()))->exists();
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();

        return $table->query(app(SupervisorEvaluationAssignments::class)->forViewer($user, filament()->getTenant())
            ->with(['evaluatee' => fn ($q) => $q->withoutGlobalScope('assigned_data'), 'course.department', 'teachingDepartment', 'semester.schoolYear', 'evaluationForm', 'evaluationCycle']))
            ->columns([
                Tables\Columns\TextColumn::make('evaluatee.name')->label('Faculty')->searchable(),
                Tables\Columns\TextColumn::make('department_label')->label('Teaching Department')->state(fn ($record) => $record->teaching_department_id
                    ? DB::table('departments')->where('id', $record->teaching_department_id)->value('code') : 'Home department'),
                Tables\Columns\TextColumn::make('course.code')->label('Course')->placeholder('Faculty evaluation'),
                Tables\Columns\TextColumn::make('semester.name')->description(fn ($record) => $record->semester->schoolYear->name),
                Tables\Columns\TextColumn::make('status')->state(fn ($record) => $this->submitted($record) ? 'Completed' : ($record->isEvaluationAvailable() ? 'Pending' : 'Not open'))->badge(),
            ])->actions([
                Tables\Actions\Action::make('evaluate')->label('Complete Annex B')->icon('heroicon-o-pencil-square')
                    ->visible(fn ($record) => ! $this->submitted($record) && $record->isEvaluationAvailable())->url(fn ($record) => route('evaluate.course-offering', $record)),
            ])->emptyStateHeading('No faculty evaluations assigned to your account')
            ->emptyStateDescription('Supervisor evaluators are selected per faculty under Teaching Classes → Evaluators → Assign Evaluators by Section. Deans and chairpersons must also be selected explicitly. Tasks appear once the evaluation is published.');
    }
}
