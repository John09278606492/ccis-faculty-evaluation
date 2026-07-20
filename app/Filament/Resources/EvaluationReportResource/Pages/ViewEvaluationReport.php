<?php

namespace App\Filament\Resources\EvaluationReportResource\Pages;

use App\Filament\Resources\EvaluationReportResource;
use App\Models\CourseOffering;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\ReportService;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;

class ViewEvaluationReport extends ViewRecord
{
    protected static string $resource = EvaluationReportResource::class;

    protected static string $view = 'filament.resources.evaluation-report.view';

    public function getTitle(): string
    {
        return $this->record->full_name . ' — Evaluation Report';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    $service = app(ReportService::class);
                    $coIds = $this->getCourseOfferingIds();
                    $fieldBreakdown = $service->getFieldBreakdown($coIds);

                    return response()->streamDownload(function () use ($fieldBreakdown) {
                        $handle = fopen('php://output', 'w');
                        fputcsv($handle, ['Section', 'Field', 'Avg Score', 'Min', 'Max', 'Responses']);

                        foreach ($fieldBreakdown as $row) {
                            fputcsv($handle, [
                                $row['section'],
                                $row['field_label'],
                                $row['avg'],
                                $row['min'],
                                $row['max'],
                                $row['count'],
                            ]);
                        }

                        fclose($handle);
                    }, 'evaluatee-report-' . str($this->record->full_name)->slug() . '-' . now()->format('Y-m-d') . '.csv');
                }),

            Actions\Action::make('back')
                ->label('Back to Reports')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(EvaluationReportResource::getUrl('index')),
        ];
    }

    /**
     * Get course offering IDs for this evaluatee.
     */
    public function getCourseOfferingIds(): Collection
    {
        $tenant = Filament::getTenant();

        $query = CourseOffering::where('user_id', $this->record->id)
            ->where('is_active', true)
            ->whereNotNull('form_id');

        if ($tenant) {
            $query->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', $tenant->id));
        }

        return $query->pluck('id');
    }

    /**
     * Get summary data for the view.
     */
    public function getSummaryProperty(): array
    {
        $service = app(ReportService::class);

        return $service->getEvaluateeSummary(
            $this->record,
            null,
            Filament::getTenant()?->id,
        );
    }

    /**
     * Get section breakdown.
     */
    public function getSectionBreakdownProperty(): Collection
    {
        $service = app(ReportService::class);
        $coIds = $this->getCourseOfferingIds();

        if ($coIds->isEmpty()) {
            return collect();
        }

        return $service->getSectionBreakdown($coIds);
    }

    /**
     * Get field breakdown.
     */
    public function getFieldBreakdownProperty(): Collection
    {
        $service = app(ReportService::class);
        $coIds = $this->getCourseOfferingIds();

        if ($coIds->isEmpty()) {
            return collect();
        }

        return $service->getFieldBreakdown($coIds);
    }

    /**
     * Get course cards data.
     */
    public function getCourseCardsProperty(): Collection
    {
        $service = app(ReportService::class);
        $summary = $this->summary;

        return $summary['course_offerings']->map(function ($co) use ($service) {
            $submissions = FormSubmission::where('course_offering_id', $co->id)->with('form.fields')->get();
            $scores = $submissions->map(fn ($s) => $service->calculateSubmissionScore($s))->filter();
            $avgScore = $scores->isNotEmpty() ? round($scores->avg(), 2) : null;

            return [
                'id' => $co->id,
                'course' => $co->course?->code . ' — ' . $co->course?->name,
                'section' => $co->section ?? '—',
                'semester' => ($co->semester?->schoolYear?->name ?? '') . ' ' . ($co->semester?->name ?? ''),
                'submissions' => $co->form_submissions_count,
                'students' => $co->students_count,
                'avg_score' => $avgScore,
                'rating_label' => $avgScore ? $service->getRatingLabel($avgScore, $co->evaluationForm?->rating_scale) : '—',
                'completion' => $co->students_count > 0
                    ? round(($co->form_submissions_count / $co->students_count) * 100, 1)
                    : 0,
            ];
        })->values();
    }

    /**
     * Get aggregated comments.
     */
    public function getCommentsProperty(): array
    {
        $service = app(ReportService::class);
        $coIds = $this->getCourseOfferingIds();

        if ($coIds->isEmpty()) {
            return ['field_comments' => [], 'overall_comments' => []];
        }

        return $service->getAggregatedComments($coIds);
    }
}
