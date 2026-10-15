<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Widgets\EvaluationResultsStatsOverview;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\FormSubmission;
use App\Models\User;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EvaluationResults extends Page implements HasTable
{
    // Gated by the page_EvaluationResults permission instead of a hardcoded
    // role-name list (the old check also referenced a view_evaluation_results
    // permission that was never actually created, so it never took effect).
    use HasPageShield;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $view = 'filament.pages.evaluation-results';

    protected static ?string $navigationLabel = 'Evaluation Results';

    protected static ?string $title = 'Evaluation Results';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationGroup = 'Evaluations';

    protected function getHeaderWidgets(): array
    {
        return [
            EvaluationResultsStatsOverview::class,
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
                Tables\Columns\TextColumn::make('name')
                    ->label('Evaluatee')
                    ->searchable(['first_name', 'last_name', 'name'])
                    ->sortable()
                    ->description(fn ($record) => $record->employee_id ? "ID: {$record->employee_id}" : null),

                Tables\Columns\TextColumn::make('instructorDepartment.name')
                    ->label('Department')
                    ->badge()
                    ->color('primary')
                    ->sortable()
                    ->default('—'),

                Tables\Columns\TextColumn::make('courses_count')
                    ->label('Courses')
                    ->getStateUsing(fn ($record) => $record->courseOfferingsAsInstructor()
                        ->whereHas('formSubmissions')
                        ->count())
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('evaluations_count')
                    ->label('Evaluations')
                    ->getStateUsing(fn ($record) => FormSubmission::whereHas(
                        'courseOffering',
                        fn ($q) => $q->where('user_id', $record->id)
                    )->count())
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('overall_score')
                    ->label('Overall Score')
                    ->getStateUsing(function ($record) {
                        return $this->calculateInstructorScore($record);
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

                Tables\Columns\TextColumn::make('rating')
                    ->label('Rating')
                    ->getStateUsing(function ($record) {
                        $score = $this->calculateInstructorScore($record);
                        if (! $score) {
                            return '—';
                        }

                        return $this->getRatingLabel((float) $score);
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
            ->filters([
                Tables\Filters\SelectFilter::make('instructor_department_id')
                    ->label('Department')
                    ->options(function () {
                        $campusId = filament()->getTenant()?->id;

                        return Department::when($campusId, fn ($q) => $q->where('campus_id', $campusId))
                            ->pluck('name', 'id');
                    })
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('has_evaluations')
                    ->label('With evaluations only')
                    ->toggle()
                    ->default(true)
                    ->query(
                        fn (Builder $query) => $query->whereHas('courseOfferingsAsInstructor.formSubmissions')
                    ),
            ], layout: FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\Action::make('view_details')
                    ->label('View Details')
                    ->icon('heroicon-o-eye')
                    ->color('primary')
                    ->modalHeading(fn ($record) => "Evaluation Results: {$record->name}")
                    ->modalWidth('4xl')
                    ->modalContent(function ($record) {
                        return view('filament.pages.partials.instructor-evaluation-details', [
                            'instructor' => $record,
                            'courseOfferings' => $this->getInstructorCourseData($record),
                        ]);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No Evaluations Yet')
            ->emptyStateDescription('No one has received evaluations yet.')
            ->emptyStateIcon('heroicon-o-chart-bar');
    }

    protected function getTableQuery(): Builder
    {
        $campusId = filament()->getTenant()?->id;

        return User::query()
            ->whereHas('courseOfferingsAsInstructor', function ($q) use ($campusId) {
                $q->when($campusId, fn ($query) => $query->whereHas('semester.schoolYear', fn ($sy) => $sy->where('campus_id', $campusId)));
            })
            ->with(['instructorDepartment']);
    }

    /**
     * Calculate one course-offering's score using the form's scoring_config.
     */
    private function calculateCourseOfferingScore(CourseOffering $co, $submissions): array
    {
        $form = $co->evaluationForm;
        if (! $form) {
            return ['score' => 0, 'method' => 'average'];
        }

        $scoringConfig = $form->scoring_config ?? [];
        $sectionMethod = $scoringConfig['section_method'] ?? 'average';
        $overallMethod = $scoringConfig['overall_method'] ?? 'average';
        $maxScaleValue = collect($form->rating_scale ?? [['value' => 5]])->max('value') ?? 5;

        $ratingFields = $form->fields->whereIn('type', ['likert_scale', 'rating']);
        $allRatings = [];

        // Group fields by section
        $groupedFields = $ratingFields->groupBy(fn ($f) => $f->section ?? 'General');

        // Calculate per-submission scores, then average across submissions
        $submissionScores = [];

        foreach ($submissions as $sub) {
            $data = $sub->data ?? [];
            $sectionScores = [];

            foreach ($groupedFields as $section => $fields) {
                $ratings = [];
                foreach ($fields as $field) {
                    $val = $data[$field->name] ?? null;
                    if ($val !== null && is_numeric($val)) {
                        $ratings[] = (float) $val;
                        $allRatings[] = (float) $val;
                    }
                }

                if (! empty($ratings)) {
                    $sum = array_sum($ratings);
                    $count = count($ratings);
                    $sectionScores[$section] = match ($sectionMethod) {
                        'sum' => $sum,
                        'percentage' => ($sum / ($count * $maxScaleValue)) * 100,
                        default => $sum / $count,
                    };
                }
            }

            if (! empty($sectionScores)) {
                $scores = array_values($sectionScores);
                $subScore = match ($overallMethod) {
                    'sum' => array_sum($scores),
                    'grand_average' => ! empty($allRatings) ? array_sum($allRatings) / count($allRatings) : 0,
                    'weighted_average' => $this->calculateWeightedAverage($sectionScores, $scoringConfig),
                    default => array_sum($scores) / count($scores),
                };
                $submissionScores[] = $subScore;
            }
        }

        $finalScore = ! empty($submissionScores) ? array_sum($submissionScores) / count($submissionScores) : 0;

        return [
            'score' => $finalScore,
            'method' => $overallMethod,
            'section_method' => $sectionMethod,
        ];
    }

    private function calculateWeightedAverage(array $sectionScores, array $scoringConfig): float
    {
        $weights = collect($scoringConfig['section_weights'] ?? []);
        $weightMap = $weights->pluck('weight', 'section_name')->map(fn ($w) => $w / 100);
        $weightedSum = 0;
        $totalWeight = 0;

        foreach ($sectionScores as $name => $score) {
            $w = $weightMap[$name] ?? (1 / count($sectionScores));
            $weightedSum += $score * $w;
            $totalWeight += $w;
        }

        return $totalWeight > 0 ? $weightedSum / $totalWeight : 0;
    }

    /**
     * Calculate the overall score for an instructor (across all course offerings).
     */
    private function calculateInstructorScore(User $instructor): ?string
    {
        $courseOfferings = CourseOffering::where('user_id', $instructor->id)
            ->whereHas('formSubmissions')
            ->with(['evaluationForm.fields', 'formSubmissions'])
            ->get();

        if ($courseOfferings->isEmpty()) {
            return null;
        }

        $coScores = [];
        foreach ($courseOfferings as $co) {
            $result = $this->calculateCourseOfferingScore($co, $co->formSubmissions);
            if ($result['score'] > 0) {
                $coScores[] = $result['score'];
            }
        }

        if (empty($coScores)) {
            return null;
        }

        return number_format(array_sum($coScores) / count($coScores), 2);
    }

    /**
     * Get course-level evaluation data for the detail modal.
     */
    private function getInstructorCourseData(User $instructor): array
    {
        $courseOfferings = CourseOffering::where('user_id', $instructor->id)
            ->whereHas('formSubmissions')
            ->with(['course', 'semester.schoolYear', 'evaluationForm.fields', 'formSubmissions'])
            ->get();

        $results = [];

        foreach ($courseOfferings as $co) {
            $form = $co->evaluationForm;
            if (! $form) {
                continue;
            }

            $submissions = $co->formSubmissions;
            $scoringConfig = $form->scoring_config ?? [];
            $sectionMethod = $scoringConfig['section_method'] ?? 'average';
            $overallMethod = $scoringConfig['overall_method'] ?? 'average';
            $maxScaleValue = collect($form->rating_scale ?? [['value' => 5]])->max('value') ?? 5;

            $ratingScale = $form->rating_scale ?? [
                ['value' => 5, 'label' => 'Outstanding', 'color' => '#22c55e'],
                ['value' => 4, 'label' => 'Very Satisfactory', 'color' => '#3b82f6'],
                ['value' => 3, 'label' => 'Satisfactory', 'color' => '#eab308'],
                ['value' => 2, 'label' => 'Fair', 'color' => '#f97316'],
                ['value' => 1, 'label' => 'Poor', 'color' => '#ef4444'],
            ];

            $ratingFields = $form->fields->whereIn('type', ['likert_scale', 'rating']);
            $fieldAverages = [];

            foreach ($ratingFields as $field) {
                $values = [];
                foreach ($submissions as $sub) {
                    $val = ($sub->data ?? [])[$field->name] ?? null;
                    if ($val !== null && is_numeric($val)) {
                        $values[] = (float) $val;
                    }
                }
                if (! empty($values)) {
                    $avg = array_sum($values) / count($values);
                    $fieldAverages[] = [
                        'label' => $field->label,
                        'section' => $field->section ?? 'General',
                        'avg' => $avg,
                        'count' => count($values),
                    ];
                }
            }

            // Calculate section scores
            $sectionScores = [];
            $sectionGroups = collect($fieldAverages)->groupBy('section');
            foreach ($sectionGroups as $section => $fields) {
                $avgs = $fields->pluck('avg');
                $sectionScores[$section] = match ($sectionMethod) {
                    'sum' => $avgs->sum(),
                    'percentage' => ($avgs->sum() / (count($avgs) * $maxScaleValue)) * 100,
                    default => $avgs->avg(),
                };
            }

            // Calculate overall score using the form's method
            $overallScore = 0;
            if (! empty($sectionScores)) {
                $scores = array_values($sectionScores);
                $overallScore = match ($overallMethod) {
                    'sum' => array_sum($scores),
                    'grand_average' => collect($fieldAverages)->avg('avg') ?? 0,
                    'weighted_average' => $this->calculateWeightedAverage($sectionScores, $scoringConfig),
                    default => array_sum($scores) / count($scores),
                };
            }

            // Format display suffix
            $scoreSuffix = $sectionMethod === 'percentage' ? '%' : '';

            // Collect per-field comments and overall comments from all submissions
            $allFieldComments = [];
            $allOverallComments = [];
            foreach ($submissions as $sub) {
                $subData = $sub->data ?? [];

                // Gather field comments
                $fc = $subData['_field_comments'] ?? [];
                foreach ($fc as $fieldName => $comment) {
                    if (! empty($comment)) {
                        $allFieldComments[$fieldName][] = $comment;
                    }
                }

                // Gather overall comments
                $oc = $subData['_overall_comment'] ?? null;
                if (! empty($oc)) {
                    $allOverallComments[] = $oc;
                }
            }

            $results[] = [
                'course_code' => $co->course?->code ?? '—',
                'course_name' => $co->course?->name ?? '—',
                'section' => $co->section ?? '—',
                'semester' => $co->semester?->name ?? '—',
                'school_year' => $co->semester?->schoolYear?->name ?? '—',
                'submission_count' => $submissions->count(),
                'overall_score' => $overallScore,
                'overall_rating' => $this->getRatingLabelFromScale($overallScore, $ratingScale, $sectionMethod),
                'rating_color' => $this->getRatingColor($overallScore, $ratingScale, $sectionMethod),
                'field_averages' => $fieldAverages,
                'section_scores' => $sectionScores,
                'rating_scale' => $ratingScale,
                'scoring_method' => $overallMethod,
                'section_method' => $sectionMethod,
                'score_suffix' => $scoreSuffix,
                'max_scale' => $maxScaleValue,
                'form_name' => $form->name,
                'field_comments' => $allFieldComments,
                'overall_comments' => $allOverallComments,
            ];
        }

        return $results;
    }

    private function getRatingLabel(float $score): string
    {
        if ($score >= 4.5) {
            return 'Outstanding';
        }
        if ($score >= 3.5) {
            return 'Very Satisfactory';
        }
        if ($score >= 2.5) {
            return 'Satisfactory';
        }
        if ($score >= 1.5) {
            return 'Fair';
        }

        return 'Poor';
    }

    private function getRatingLabelFromScale(float $score, array $ratingScale, string $sectionMethod = 'average'): string
    {
        // For percentage method, convert back to scale equivalent
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

    private function getRatingColor(float $score, array $ratingScale, string $sectionMethod = 'average'): string
    {
        if ($sectionMethod === 'percentage') {
            $maxScale = collect($ratingScale)->max('value') ?? 5;
            $score = ($score / 100) * $maxScale;
        }

        $scale = collect($ratingScale)->sortByDesc('value');
        foreach ($scale as $s) {
            if ($score >= ($s['value'] - 0.5)) {
                return $s['color'] ?? '#6b7280';
            }
        }

        return $scale->last()['color'] ?? '#6b7280';
    }
}
