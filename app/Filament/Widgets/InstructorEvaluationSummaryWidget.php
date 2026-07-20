<?php

namespace App\Filament\Widgets;

use App\Models\CourseOffering;
use App\Models\FormSubmission;
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
                    ->with(['course', 'semester.schoolYear', 'evaluationForm'])
                    ->withCount(['students', 'formSubmissions'])
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
                    ->label('Submissions')
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('success'),
                Tables\Columns\TextColumn::make('completion_rate')
                    ->label('Completion')
                    ->getStateUsing(function (CourseOffering $record) {
                        if ($record->students_count === 0) {
                            return 'N/A';
                        }
                        $rate = round(($record->form_submissions_count / $record->students_count) * 100, 1);

                        return $rate.'%';
                    })
                    ->badge()
                    ->color(function (CourseOffering $record) {
                        if ($record->students_count === 0) {
                            return 'gray';
                        }
                        $rate = ($record->form_submissions_count / $record->students_count) * 100;

                        return match (true) {
                            $rate >= 100 => 'success',
                            $rate >= 50 => 'warning',
                            default => 'danger',
                        };
                    }),
                Tables\Columns\TextColumn::make('average_score')
                    ->label('Avg Score')
                    ->getStateUsing(function (CourseOffering $record) {
                        $submissions = FormSubmission::where('course_offering_id', $record->id)->get();
                        if ($submissions->isEmpty()) {
                            return 'N/A';
                        }

                        $scores = $submissions->map(function ($submission) {
                            $data = $submission->data ?? [];
                            $numericValues = collect($data)->filter(fn ($v) => is_numeric($v))->values();

                            return $numericValues->isNotEmpty() ? $numericValues->avg() : null;
                        })->filter();

                        return $scores->isNotEmpty() ? number_format($scores->avg(), 2) : 'N/A';
                    })
                    ->alignCenter(),
            ]);
    }
}
