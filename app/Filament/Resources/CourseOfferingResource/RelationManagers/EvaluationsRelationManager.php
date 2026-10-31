<?php

namespace App\Filament\Resources\CourseOfferingResource\RelationManagers;

use App\Models\FormSubmission;
use App\Services\EvaluationResultsRelease;
use App\Services\ReportService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EvaluationsRelationManager extends RelationManager
{
    /**
     * The person being evaluated sees these scores only once the results are released.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return parent::canViewForRecord($ownerRecord, $pageClass)
            && EvaluationResultsRelease::canSeeScores(auth()->user(), $ownerRecord);
    }

    protected static string $relationship = 'formSubmissions';

    protected static ?string $title = 'Evaluation Submissions';

    protected static ?string $icon = 'heroicon-o-clipboard-document-check';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('anonymous_evaluator')->label('Evaluator')->state(fn () => $this->getOwnerRecord()->evaluationForm?->isSupervisorInstrument() ? 'Supervisor' : 'Student'),

                Tables\Columns\TextColumn::make('form.name')
                    ->label('Form')
                    ->badge()
                    ->color('primary')
                    ->sortable(),

                Tables\Columns\TextColumn::make('score')
                    ->label('Mean / 5')
                    ->getStateUsing(function (FormSubmission $record) {
                        return $this->calculateOverallScore($record);
                    })
                    ->badge()
                    ->color(function ($state) {
                        if (! $state || ! is_numeric($state)) {
                            return 'gray';
                        }
                        $val = (float) $state;
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
                        $score = $this->calculateOverallScore($record);
                        if (! $score) {
                            return '—';
                        }

                        return $this->getRatingLabel($record, $score);
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
            ->modifyQueryUsing(fn ($query) => $query->where('form_id', $this->getOwnerRecord()->form_id))
            ->defaultSort('data')
            ->filters([
                //
            ], layout: FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\Action::make('view_details')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('Evaluation Details')
                    ->modalWidth('2xl')
                    ->modalContent(function (FormSubmission $record) {
                        return view('filament.pages.partials.evaluation-view', [
                            'submission' => $record,
                            'record' => $this->getOwnerRecord(),
                        ]);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->emptyStateHeading('No Evaluations Yet')
            ->emptyStateDescription('No evaluators have submitted evaluations for this assignment.')
            ->emptyStateIcon('heroicon-o-clipboard-document-check');
    }

    /**
     * Calculate the overall score for a submission.
     */
    private function calculateOverallScore(FormSubmission $record): ?string
    {
        $score = app(ReportService::class)->calculateSubmissionScore($record);

        return $score !== null ? number_format($score, 2) : null;
    }

    private function getRatingLabel(FormSubmission $record, string $score): string
    {
        return app(ReportService::class)->getRatingLabel((float) $score);
    }
}
