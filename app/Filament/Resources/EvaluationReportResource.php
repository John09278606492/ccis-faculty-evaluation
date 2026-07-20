<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EvaluationReportResource\Pages;
use App\Filament\Resources\EvaluationReportResource\Widgets\EvaluationReportStatsOverview;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Form;
use App\Models\Semester;
use App\Models\User;
use App\Services\ReportService;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Database\Eloquent\Builder;

class EvaluationReportResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'Evaluations';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $modelLabel = 'Evaluation Report';

    protected static ?string $pluralModelLabel = 'Evaluation Reports';

    protected static ?string $slug = 'reports/evaluations';

    protected static ?int $navigationSort = 50;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->isSuperAdmin() || $user->can('view_any_form'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        // Only get users who are evaluatees (have active course offerings with forms)
        $query = User::query()
            ->whereHas('courseOfferingsAsEvaluatee', function (Builder $q) use ($tenant) {
                $q->where('is_active', true)
                    ->whereNotNull('form_id');

                if ($tenant) {
                    $q->whereHas('semester.schoolYear', fn(Builder $sq) => $sq->where('campus_id', $tenant->id));
                }
            })
            ->with('evaluateeDepartment');

        return $query;
    }

    public static function table(Table $table): Table
    {
        $service = app(ReportService::class);
        $tenant = Filament::getTenant();

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('rank')
                    ->label('#')
                    ->rowIndex(),

                Tables\Columns\TextColumn::make('full_name')
                    ->label('Name')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name']),

                Tables\Columns\TextColumn::make('evaluateeDepartment.name')
                    ->label('Department')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_instructor')
                    ->label('Instructor')
                    ->boolean()
                    ->trueIcon('heroicon-o-academic-cap')
                    ->falseIcon('heroicon-o-user')
                    ->trueColor('primary')
                    ->falseColor('gray')
                    ->alignCenter()
                    ->tooltip(fn($state) => $state ? 'Instructor' : 'Non-Instructor'),

                Tables\Columns\TextColumn::make('total_courses')
                    ->label('Assignments')
                    ->getStateUsing(function (User $record) use ($tenant) {
                        return CourseOffering::where('user_id', $record->id)
                            ->where('is_active', true)
                            ->whereNotNull('form_id')
                            ->when($tenant, fn($q) => $q->whereHas('semester.schoolYear', fn($sq) => $sq->where('campus_id', $tenant->id)))
                            ->count();
                    })
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('total_submissions')
                    ->label('Submissions')
                    ->getStateUsing(function (User $record) use ($tenant) {
                        $coIds = CourseOffering::where('user_id', $record->id)
                            ->where('is_active', true)
                            ->whereNotNull('form_id')
                            ->when($tenant, fn($q) => $q->whereHas('semester.schoolYear', fn($sq) => $sq->where('campus_id', $tenant->id)))
                            ->pluck('id');

                        return \App\Models\FormSubmission::whereIn('course_offering_id', $coIds)->count();
                    })
                    ->badge()
                    ->color('info')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('avg_score')
                    ->label('Avg Score')
                    ->getStateUsing(function (User $record) use ($service, $tenant) {
                        $summary = $service->getEvaluateeSummary($record, null, $tenant?->id);

                        return $summary['avg_score'];
                    })
                    ->numeric(2)
                    ->placeholder('—')
                    ->badge()
                    ->color(fn($state) => match (true) {
                        $state === null => 'gray',
                        $state >= 4.5 => 'success',
                        $state >= 3.5 => 'info',
                        $state >= 2.5 => 'warning',
                        default => 'danger',
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        // Sort by computed avg_score is not directly possible on DB,
                        // so we sort by submission count as a proxy
                        return $query;
                    })
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('rating_label')
                    ->label('Rating')
                    ->getStateUsing(function (User $record) use ($service, $tenant) {
                        $summary = $service->getEvaluateeSummary($record, null, $tenant?->id);

                        return $summary['rating_label'];
                    })
                    ->badge()
                    ->color(fn($state) => match ($state) {
                        'Outstanding' => 'success',
                        'Very Satisfactory' => 'info',
                        'Satisfactory' => 'warning',
                        'Fair' => 'warning',
                        'Poor' => 'danger',
                        default => 'gray',
                    })
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('completion_rate')
                    ->label('Completion')
                    ->getStateUsing(function (User $record) use ($service, $tenant) {
                        $summary = $service->getEvaluateeSummary($record, null, $tenant?->id);

                        return $summary['completion_rate'] . '%';
                    })
                    ->alignCenter(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('semester')
                    ->label('Semester')
                    ->options(function () {
                        $tenant = Filament::getTenant();
                        $query = Semester::query()
                            ->with('schoolYear')
                            ->orderByDesc('start_date');
                        if ($tenant) {
                            $query->whereHas('schoolYear', fn($q) => $q->where('campus_id', $tenant->id));
                        }

                        return $query->get()->mapWithKeys(fn($s) => [
                            $s->id => ($s->schoolYear?->name ?? '') . ' — ' . $s->name,
                        ])->toArray();
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when($data['value'], function (Builder $q, $semesterId) {
                            $q->whereHas('courseOfferingsAsEvaluatee', fn($sq) => $sq->where('semester_id', $semesterId)->where('is_active', true)->whereNotNull('form_id'));
                        });
                    }),

                Tables\Filters\SelectFilter::make('department')
                    ->label('Department')
                    ->options(function () {
                        $tenant = Filament::getTenant();
                        $query = Department::where('is_active', true)->orderBy('name');
                        if ($tenant) {
                            $query->where('campus_id', $tenant->id);
                        }

                        return $query->pluck('name', 'id')->toArray();
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when($data['value'], fn(Builder $q, $deptId) => $q->where('instructor_department_id', $deptId));
                    }),

                Tables\Filters\SelectFilter::make('form')
                    ->label('Form')
                    ->options(function () {
                        $tenant = Filament::getTenant();
                        $query = Form::where('is_active', true)->orderBy('name');
                        if ($tenant) {
                            $query->whereHas('campuses', fn($q) => $q->where('campuses.id', $tenant->id));
                        }

                        return $query->pluck('name', 'id')->toArray();
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when($data['value'], function (Builder $q, $formId) {
                            $q->whereHas('courseOfferingsAsEvaluatee', fn($sq) => $sq->where('form_id', $formId)->where('is_active', true));
                        });
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('View Report')
                    ->icon('heroicon-o-chart-bar-square')
                    ->url(fn(User $record): string => static::getUrl('view', ['record' => $record])),
            ])
            ->headerActions([
                Tables\Actions\Action::make('export_csv')
                    ->label('Export CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function () use ($service, $tenant) {
                        $query = static::getEloquentQuery();
                        $evaluatees = $query->get();

                        return response()->streamDownload(function () use ($evaluatees, $service, $tenant) {
                            $handle = fopen('php://output', 'w');
                            fputcsv($handle, ['Name', 'Department', 'Courses', 'Submissions', 'Avg Score', 'Rating', 'Completion %']);

                            foreach ($evaluatees as $evaluatee) {
                                $summary = $service->getEvaluateeSummary($evaluatee, null, $tenant?->id);
                                fputcsv($handle, [
                                    $evaluatee->full_name,
                                    $evaluatee->evaluateeDepartment?->name ?? '—',
                                    $summary['total_courses'],
                                    $summary['total_submissions'],
                                    $summary['avg_score'] ?? 'N/A',
                                    $summary['rating_label'],
                                    $summary['completion_rate'] . '%',
                                ]);
                            }

                            fclose($handle);
                        }, 'evaluation-report-' . now()->format('Y-m-d') . '.csv');
                    }),
            ])
            ->defaultSort('first_name')
            ->striped()
            ->paginated([10, 25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEvaluationReports::route('/'),
            'view' => Pages\ViewEvaluationReport::route('/{record}'),
        ];
    }

    public static function getWidgets(): array
    {
        return [
            EvaluationReportStatsOverview::class,
        ];
    }
}
