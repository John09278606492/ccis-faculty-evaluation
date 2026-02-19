<?php

namespace App\Filament\Pages;

use App\Models\CourseOffering;
use App\Models\FormSubmission;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class MyEvaluationResults extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string $view = 'filament.pages.my-evaluation-results';

    protected static ?string $navigationLabel = 'My Evaluation Results';

    protected static ?string $title = 'My Evaluation Results';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationGroup = 'Evaluations';

    /**
     * Only show to users who have permission to view their own evaluation results.
     * Admins/higher-ups can grant this permission per-role or per-user via Shield.
     */
    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();
        if (!$user)
            return false;

        return $user->hasRole('super_admin') ||
            $user->can('page_MyEvaluationResults');
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                Tables\Columns\TextColumn::make('courseOffering.course.code')
                    ->label('Course')
                    ->sortable()
                    ->description(fn($record) => $record->courseOffering?->course?->name),

                Tables\Columns\TextColumn::make('courseOffering.section')
                    ->label('Section')
                    ->default('—'),

                Tables\Columns\TextColumn::make('courseOffering.semester.name')
                    ->label('Semester')
                    ->description(fn($record) => $record->courseOffering?->semester?->schoolYear?->name),

                Tables\Columns\TextColumn::make('form.name')
                    ->label('Form')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('score')
                    ->label('Score')
                    ->getStateUsing(function (FormSubmission $record) {
                        return $this->calculateSubmissionScore($record);
                    })
                    ->badge()
                    ->color(function ($state) {
                        if (!$state || !is_numeric(str_replace('%', '', $state)))
                            return 'gray';
                        $val = (float) str_replace('%', '', $state);
                        // For percentage, convert
                        if (str_contains($state, '%'))
                            $val = ($val / 100) * 5;
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

                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('course_offering_id')
                    ->label('Course')
                    ->options(function () {
                        $user = Auth::user();
                        return CourseOffering::where('user_id', $user->id)
                            ->whereHas('formSubmissions')
                            ->with('course')
                            ->get()
                            ->mapWithKeys(fn($co) => [$co->id => "{$co->course?->code} - {$co->section}"]);
                    })
                    ->searchable()
                    ->preload(),
            ])
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
            ->emptyStateDescription('You have not received any evaluations yet.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }

    /**
     * Query only submissions for course offerings where the current user is the instructor.
     */
    protected function getTableQuery(): Builder
    {
        $user = Auth::user();

        return FormSubmission::query()
            ->whereHas('courseOffering', fn($q) => $q->where('user_id', $user->id))
            ->with(['form', 'courseOffering.course', 'courseOffering.semester.schoolYear']);
    }

    /**
     * Calculate the score for a single submission using the form's scoring config.
     */
    private function calculateSubmissionScore(FormSubmission $record): ?string
    {
        $form = $record->form;
        if (!$form)
            return null;

        $data = $record->data ?? [];
        $scoringConfig = $form->scoring_config ?? [];
        $sectionMethod = $scoringConfig['section_method'] ?? 'average';
        $overallMethod = $scoringConfig['overall_method'] ?? 'average';
        $maxScaleValue = collect($form->rating_scale ?? [['value' => 5]])->max('value') ?? 5;

        $fields = $form->fields()->whereIn('type', ['likert_scale', 'rating'])->get();
        $groupedFields = $fields->groupBy(fn($f) => $f->section ?? 'General');

        $sectionScores = [];
        $allRatings = [];

        foreach ($groupedFields as $section => $sFields) {
            $ratings = [];
            foreach ($sFields as $field) {
                $val = $data[$field->name] ?? null;
                if ($val !== null && is_numeric($val)) {
                    $ratings[] = (float) $val;
                    $allRatings[] = (float) $val;
                }
            }
            if (!empty($ratings)) {
                $sum = array_sum($ratings);
                $count = count($ratings);
                $sectionScores[$section] = match ($sectionMethod) {
                    'sum' => $sum,
                    'percentage' => ($sum / ($count * $maxScaleValue)) * 100,
                    default => $sum / $count,
                };
            }
        }

        if (empty($sectionScores))
            return null;

        $scores = array_values($sectionScores);
        $overall = match ($overallMethod) {
            'sum' => array_sum($scores),
            'grand_average' => !empty($allRatings) ? array_sum($allRatings) / count($allRatings) : 0,
            'weighted_average' => $this->calculateWeightedAverage($sectionScores, $scoringConfig),
            default => array_sum($scores) / count($scores),
        };

        $suffix = $sectionMethod === 'percentage' ? '%' : '';
        return number_format($overall, 2) . $suffix;
    }

    private function getSubmissionRating(FormSubmission $record): string
    {
        $form = $record->form;
        if (!$form)
            return '—';

        $scoreStr = $this->calculateSubmissionScore($record);
        if (!$scoreStr)
            return '—';

        $score = (float) str_replace('%', '', $scoreStr);
        $ratingScale = $form->rating_scale ?? [
            ['value' => 5, 'label' => 'Outstanding'],
            ['value' => 4, 'label' => 'Very Satisfactory'],
            ['value' => 3, 'label' => 'Satisfactory'],
            ['value' => 2, 'label' => 'Fair'],
            ['value' => 1, 'label' => 'Poor'],
        ];

        $sectionMethod = ($form->scoring_config ?? [])['section_method'] ?? 'average';
        if ($sectionMethod === 'percentage') {
            $maxScale = collect($ratingScale)->max('value') ?? 5;
            $score = ($score / 100) * $maxScale;
        }

        $scale = collect($ratingScale)->sortByDesc('value');
        foreach ($scale as $s) {
            if ($score >= ($s['value'] - 0.5)) {
                return $s['label'];
            }
        }
        return $scale->last()['label'] ?? '—';
    }

    private function calculateWeightedAverage(array $sectionScores, array $scoringConfig): float
    {
        $weights = collect($scoringConfig['section_weights'] ?? []);
        $weightMap = $weights->pluck('weight', 'section_name')->map(fn($w) => $w / 100);
        $weightedSum = 0;
        $totalWeight = 0;

        foreach ($sectionScores as $name => $score) {
            $w = $weightMap[$name] ?? (1 / count($sectionScores));
            $weightedSum += $score * $w;
            $totalWeight += $w;
        }

        return $totalWeight > 0 ? $weightedSum / $totalWeight : 0;
    }
}
