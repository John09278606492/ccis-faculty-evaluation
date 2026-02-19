<?php

namespace App\Filament\Resources\CourseOfferingResource\RelationManagers;

use App\Models\FormSubmission;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EvaluationsRelationManager extends RelationManager
{
    protected static string $relationship = 'formSubmissions';

    protected static ?string $title = 'Evaluation Submissions';

    protected static ?string $icon = 'heroicon-o-clipboard-document-check';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Evaluator')
                    ->default('Anonymous')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('form.name')
                    ->label('Form')
                    ->badge()
                    ->color('primary')
                    ->sortable(),

                Tables\Columns\TextColumn::make('score')
                    ->label('Overall Score')
                    ->getStateUsing(function (FormSubmission $record) {
                        return $this->calculateOverallScore($record);
                    })
                    ->badge()
                    ->color(function ($state) {
                        if (!$state || !is_numeric($state))
                            return 'gray';
                        $val = (float) $state;
                        if ($val >= 4.5)
                            return 'success';
                        if ($val >= 3.5)
                            return 'info';
                        if ($val >= 2.5)
                            return 'warning';
                        return 'danger';
                    }),

                Tables\Columns\TextColumn::make('rating_label')
                    ->label('Rating')
                    ->getStateUsing(function (FormSubmission $record) {
                        $score = $this->calculateOverallScore($record);
                        if (!$score)
                            return '—';
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

                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                //
            ])
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
            ->emptyStateDescription('No students have submitted evaluations for this course offering.')
            ->emptyStateIcon('heroicon-o-clipboard-document-check');
    }

    /**
     * Calculate the overall score for a submission.
     */
    private function calculateOverallScore(FormSubmission $record): ?string
    {
        $form = $record->form;
        if (!$form)
            return null;

        $data = $record->data ?? [];
        $fields = $form->fields()->whereIn('type', ['likert_scale', 'rating'])->get();

        $ratings = [];
        foreach ($fields as $field) {
            $val = $data[$field->name] ?? null;
            if ($val !== null && is_numeric($val)) {
                $ratings[] = (float) $val;
            }
        }

        if (empty($ratings))
            return null;

        $scoringConfig = $form->scoring_config ?? [];
        $overallMethod = $scoringConfig['overall_method'] ?? 'average';

        return match ($overallMethod) {
            'sum' => number_format(array_sum($ratings), 2),
            default => number_format(array_sum($ratings) / count($ratings), 2),
        };
    }

    /**
     * Get the rating label for a given score.
     */
    private function getRatingLabel(FormSubmission $record, string $score): string
    {
        $form = $record->form;
        $ratingScale = $form?->rating_scale ?? [
            ['value' => 5, 'label' => 'Outstanding'],
            ['value' => 4, 'label' => 'Very Satisfactory'],
            ['value' => 3, 'label' => 'Satisfactory'],
            ['value' => 2, 'label' => 'Fair'],
            ['value' => 1, 'label' => 'Poor'],
        ];

        $ratingScale = collect($ratingScale)->sortByDesc('value');
        $numericScore = (float) $score;

        foreach ($ratingScale as $scale) {
            if ($numericScore >= ($scale['value'] - 0.5)) {
                return $scale['label'];
            }
        }

        return $ratingScale->last()['label'] ?? '—';
    }
}
