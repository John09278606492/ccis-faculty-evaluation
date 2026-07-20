<?php

namespace App\Filament\Pages;

use App\Models\CourseOffering;
use App\Models\FormSubmission;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class MyEvaluations extends Page implements HasTable
{
    use InteractsWithTable;
    // Gates access via the page_MyEvaluations permission (see Roles) instead of
    // just checking isStudent(), so an admin can actually toggle this on/off.
    use HasPageShield;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string $view = 'filament.pages.my-evaluations';

    protected static ?string $navigationLabel = 'My Evaluations';

    protected static ?string $title = 'My Instructor Evaluations';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationGroup = 'Student';

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Pages\Widgets\MyEvaluationsStatsOverview::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 3;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                Tables\Columns\TextColumn::make('course.code')
                    ->label('Course Code')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('course.name')
                    ->label('Course Name')
                    ->searchable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('instructor.name')
                    ->label('Evaluatee')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('section')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('semester.name')
                    ->label('Semester')
                    ->description(fn($record) => $record->semester?->schoolYear?->name),

                Tables\Columns\IconColumn::make('has_evaluation_form')
                    ->label('Form?')
                    ->getStateUsing(fn($record) => $record->form_id !== null)
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('evaluation_status')
                    ->label('Status')
                    ->getStateUsing(function ($record) {
                        if (!$record->form_id) {
                            return 'No Form';
                        }

                        $submitted = FormSubmission::where('form_id', $record->form_id)
                            ->where('course_offering_id', $record->id)
                            ->where('user_id', Auth::id())
                            ->exists();

                        return $submitted ? 'Completed' : 'Pending';
                    })
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'Completed' => 'success',
                        'Pending' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('evaluation_status')
                    ->label('Evaluation Status')
                    ->options([
                        'pending' => 'Pending',
                        'completed' => 'Completed',
                    ])
                    ->query(function (Builder $query, array $data) {
                        if ($data['value'] === 'pending') {
                            $query->whereNotNull('form_id')
                                ->whereDoesntHave('formSubmissions', function ($q) {
                                    $q->where('user_id', Auth::id());
                                });
                        } elseif ($data['value'] === 'completed') {
                            $query->whereHas('formSubmissions', function ($q) {
                                $q->where('user_id', Auth::id());
                            });
                        }
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\Action::make('evaluate')
                    ->label('Evaluate')
                    ->icon('heroicon-o-star')
                    ->color('primary')
                    ->visible(function ($record) {
                        if (!$record->form_id) {
                            return false;
                        }

                        // Check if already submitted
                        return !FormSubmission::where('form_id', $record->form_id)
                            ->where('course_offering_id', $record->id)
                            ->where('user_id', Auth::id())
                            ->exists();
                    })
                    ->url(fn($record) => route('evaluate.course-offering', $record))
                    ->openUrlInNewTab(),

                Tables\Actions\Action::make('view_submission')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(function ($record) {
                        if (!$record->form_id) {
                            return false;
                        }

                        return FormSubmission::where('form_id', $record->form_id)
                            ->where('course_offering_id', $record->id)
                            ->where('user_id', Auth::id())
                            ->exists();
                    })
                    ->modalHeading('Your Evaluation')
                    ->modalContent(function ($record) {
                        $submission = FormSubmission::where('form_id', $record->form_id)
                            ->where('course_offering_id', $record->id)
                            ->where('user_id', Auth::id())
                            ->first();

                        return view('filament.pages.partials.evaluation-view', [
                            'submission' => $submission,
                            'record' => $record,
                        ]);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelAction(false),
            ])
            ->emptyStateHeading('No Evaluation Assignments')
            ->emptyStateDescription('You are not enrolled in any courses yet.')
            ->emptyStateIcon('heroicon-o-academic-cap');
    }

    protected function getTableQuery(): Builder
    {
        // Get course offerings the student is enrolled in
        return CourseOffering::query()
            ->whereHas('students', function ($query) {
                $query->where('user_id', Auth::id());
            })
            ->with(['course', 'instructor', 'semester.schoolYear', 'evaluationForm']);
    }
}
