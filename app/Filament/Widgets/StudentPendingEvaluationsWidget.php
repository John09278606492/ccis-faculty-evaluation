<?php

namespace App\Filament\Widgets;

use App\Models\CourseOffering;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class StudentPendingEvaluationsWidget extends BaseWidget
{
    use HasWidgetShield;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'My Pending Evaluations';

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
                    ->where('is_active', true)
                    ->whereNotNull('form_id')
                    ->whereHas('students', fn (Builder $q) => $q->where('users.id', $user->id))
                    ->whereDoesntHave('formSubmissions', fn (Builder $q) => $q->where('user_id', $user->id))
                    ->with(['course', 'evaluatee', 'semester.schoolYear', 'evaluationForm'])
            )
            ->emptyStateHeading('No Pending Evaluations')
            ->emptyStateDescription('You have completed all your evaluations. Great job!')
            ->emptyStateIcon('heroicon-o-check-circle')
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
                Tables\Columns\TextColumn::make('evaluatee.name')
                    ->label('Evaluatee')
                    ->sortable(),
                Tables\Columns\TextColumn::make('evaluatee_label')
                    ->label('Role')
                    ->badge()
                    ->color(fn (string $state): string => match (strtolower($state)) {
                        'faculty' => 'primary',
                        'dean' => 'danger',
                        'staff' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('semester.name')
                    ->label('Semester')
                    ->sortable(),
                Tables\Columns\TextColumn::make('evaluationForm.name')
                    ->label('Form')
                    ->limit(20),
            ]);
    }
}
