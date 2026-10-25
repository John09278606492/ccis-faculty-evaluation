<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Widgets\MyEvaluationResultsStatsOverview;
use App\Models\CourseOffering;
use App\Models\FormSubmission;
use App\Services\EvaluationResultsRelease;
use App\Services\ReportService;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class MyEvaluationResults extends Page implements HasTable
{
    // Gated by the page_MyEvaluationResults permission — super_admin already
    // holds every generated permission automatically, so no extra role check needed.
    use HasPageShield;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string $view = 'filament.pages.my-evaluation-results';

    protected static ?string $navigationLabel = 'My Evaluation Results';

    protected static ?string $title = 'My Evaluation Results';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationGroup = 'My Work';

    protected function getHeaderWidgets(): array
    {
        return [
            MyEvaluationResultsStatsOverview::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 2;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                Tables\Columns\TextColumn::make('courseOffering.course.code')
                    ->label('Course')
                    ->sortable()
                    ->description(fn ($record) => $record->courseOffering?->course?->name),

                Tables\Columns\TextColumn::make('courseOffering.section')
                    ->label('Section')
                    ->default('—'),

                Tables\Columns\TextColumn::make('courseOffering.semester.name')
                    ->label('Semester')
                    ->description(fn ($record) => $record->courseOffering?->semester?->schoolYear?->name),

                Tables\Columns\TextColumn::make('form.name')
                    ->label('Form')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('score')
                    ->label('Mean / 5')
                    ->getStateUsing(function (FormSubmission $record) {
                        return $this->calculateSubmissionScore($record);
                    })
                    ->badge()
                    ->color(function ($state) {
                        if (! $state || ! is_numeric(str_replace('%', '', $state))) {
                            return 'gray';
                        }
                        $val = (float) str_replace('%', '', $state);
                        // For percentage, convert
                        if (str_contains($state, '%')) {
                            $val = ($val / 100) * 5;
                        }
                        if ($val >= 4.5) {
                            return 'success';
                        }
                        if ($val >= 3.5) {
                            return 'info';
                        }
                        if ($val >= 2.5) {
                            return 'warning';
                        }

                        return 'danger';
                    }),

                Tables\Columns\TextColumn::make('rating_label')
                    ->label('Rating')
                    ->getStateUsing(function (FormSubmission $record) {
                        return $this->getSubmissionRating($record);
                    })
                    ->color(function ($state) {
                        return match ($state) {
                            'Outstanding' => 'success',
                            'Very Satisfactory' => 'info',
                            'Satisfactory' => 'warning',
                            'Fair' => 'warning',
                            'Poor' => 'danger',
                            default => 'gray',
                        };
                    }),

            ])
            // No submission times and no submission order: either could point to a student.
            ->defaultSort(fn (Builder $query) => $query->orderBy('course_offering_id')->orderBy('data'))
            ->filters([
                Tables\Filters\SelectFilter::make('course_offering_id')
                    ->label('Course')
                    ->options(function () {
                        $user = Auth::user();

                        return EvaluationResultsRelease::ownOfferings($user)
                            ->filter(fn (CourseOffering $co) => EvaluationResultsRelease::isReleased($co))
                            ->mapWithKeys(fn ($co) => [$co->id => "{$co->course?->code} - {$co->section}"]);
                    })
                    ->searchable()
                    ->preload(),
            ], layout: FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\Action::make('view_scores')
                    ->label('View Scores')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('Evaluation Scores')
                    ->modalDescription('Individual responses are anonymized. Only scores are shown.')
                    ->modalWidth('2xl')
                    ->modalContent(function (FormSubmission $record) {
                        return view('filament.pages.partials.anonymous-evaluation-view', [
                            'submission' => $record,
                        ]);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->emptyStateHeading('No Evaluations Yet')
            ->emptyStateDescription('Results appear here after an evaluation closes and enough students have responded.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }

    /**
     * Course offerings whose results are not open to the instructor yet, with the reason.
     *
     * @return array<int, array{label: string, reason: string}>
     */
    public function getPendingOfferingsProperty(): array
    {
        return EvaluationResultsRelease::ownOfferings(Auth::user())
            ->map(fn (CourseOffering $co) => [
                'label' => trim(($co->course?->code ?? $co->evaluatee_label ?? 'Evaluation').' '.$co->section),
                'reason' => EvaluationResultsRelease::pendingReason($co),
            ])
            ->filter(fn (array $row) => $row['reason'] !== null)
            ->values()
            ->all();
    }

    /**
     * Submissions for the current user's course offerings whose results have been released.
     */
    protected function getTableQuery(): Builder
    {
        $user = Auth::user();

        $released = EvaluationResultsRelease::ownOfferings($user)
            ->filter(fn (CourseOffering $co) => EvaluationResultsRelease::isReleased($co))
            ->modelKeys();

        return FormSubmission::query()
            ->whereIn('course_offering_id', $released)
            ->whereHas('courseOffering', fn ($q) => $q->whereColumn('course_offerings.form_id', 'form_submissions.form_id'))
            ->with(['form', 'courseOffering.course', 'courseOffering.semester.schoolYear']);
    }

    /**
     * Calculate the score for a single submission using the form's scoring config.
     */
    private function calculateSubmissionScore(FormSubmission $record): ?string
    {
        $score = app(ReportService::class)->calculateSubmissionScore($record);

        return $score !== null ? number_format($score, 2) : null;
    }

    private function getSubmissionRating(FormSubmission $record): string
    {
        $score = app(ReportService::class)->calculateSubmissionScore($record);

        return $score !== null ? app(ReportService::class)->getRatingLabel($score) : 'Incomplete';
    }
}
