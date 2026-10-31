<?php

namespace App\Filament\Widgets;

use App\Models\CourseOffering;
use App\Services\AcademicPeriod;
use App\Services\EvaluationResultsRelease;
use App\Services\EvaluationScoring;
use App\Services\ReportService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class InstructorEvaluationSummaryWidget extends BaseWidget
{
    use HasWidgetShield;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'My Evaluation Summary';

    public static function canView(): bool
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->can(static::getPermissionName());
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();

        return $table
            ->query(
                CourseOffering::query()
                    ->where('user_id', $user->id)
                    ->where('is_active', true)
                    ->when(filament()->getTenant(), fn ($q, $campus) => $q->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', $campus->id))->where('semester_id', AcademicPeriod::current($campus)))
                    ->with(['course', 'semester.schoolYear', 'evaluationForm', 'semester'])
                    ->withCount(['students' => fn ($q) => $q->withTrashed(), 'formSubmissions'])
            )
            ->emptyStateHeading('No Evaluation Assignments')
            ->emptyStateDescription('You have no active evaluation assignments.')
            ->emptyStateIcon('heroicon-o-book-open')
            ->defaultPaginationPageOption(5)
            ->striped()
            ->columns([
                Tables\Columns\TextColumn::make('course.code')
                    ->label('Course Code')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('course.name')
                    ->label('Course')
                    ->sortable()
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('section')
                    ->label('Section')
                    ->sortable(),
                Tables\Columns\TextColumn::make('semester.name')
                    ->label('Semester')
                    ->sortable(),
                Tables\Columns\TextColumn::make('students_count')
                    ->label('Evaluators')
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('form_submissions_count')
                    ->label('Current Responses')
                    ->state(fn (CourseOffering $record) => app(EvaluationScoring::class)->assignment($record)['submission_count'])
                    ->alignCenter()
                    ->badge()
                    ->color('success'),
                Tables\Columns\TextColumn::make('completion_rate')
                    ->label('Completion')
                    ->getStateUsing(function (CourseOffering $record) {
                        $value = app(ReportService::class)->assignmentCompletion($record)['percentage'];

                        return $value !== null ? $value.'%' : 'N/A';
                    })
                    ->badge()
                    ->color(function (CourseOffering $record) {
                        $rate = app(ReportService::class)->assignmentCompletion($record)['percentage'];

                        return $rate === null ? 'gray' : ($rate >= 100 ? 'success' : ($rate >= 50 ? 'warning' : 'danger'));
                    }),
                Tables\Columns\TextColumn::make('average_score')
                    ->label('Mean / 5')
                    ->getStateUsing(function (CourseOffering $record) {
                        if (! EvaluationResultsRelease::isReleased($record, $record->form_submissions_count)) {
                            return 'After close';
                        }

                        $score = app(EvaluationScoring::class)->assignment($record);

                        return $score['mean_5'] !== null ? number_format($score['mean_5'], 2) : 'N/A';
                    })
                    ->alignCenter(),
            ]);
    }
}
