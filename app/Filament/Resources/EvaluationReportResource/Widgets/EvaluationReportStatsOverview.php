<?php

namespace App\Filament\Resources\EvaluationReportResource\Widgets;

use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\FormSubmission;
use App\Services\ReportService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EvaluationReportStatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        $service = app(ReportService::class);

        // Total submissions
        $submissionsQuery = FormSubmission::query();
        if ($tenant) {
            $submissionsQuery->whereHas('form.campuses', fn ($q) => $q->where('campuses.id', $tenant->id));
        }
        $totalSubmissions = $submissionsQuery->count();

        // Average score
        $submissions = $submissionsQuery->with('form.fields')->limit(500)->get();
        $scores = $submissions->map(fn ($s) => $service->calculateSubmissionScore($s))->filter();
        $avgScore = $scores->isNotEmpty() ? round($scores->avg(), 2) : null;
        $ratingLabel = $avgScore ? $service->getRatingLabel($avgScore) : '—';

        // Completion rate
        $coQuery = CourseOffering::where('is_active', true)->whereNotNull('form_id');
        if ($tenant) {
            $coQuery->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', $tenant->id));
        }
        $coIds = $coQuery->pluck('id');
        $totalStudents = $coIds->isNotEmpty()
            ? \DB::table('course_offering_student')->whereIn('course_offering_id', $coIds)->count()
            : 0;
        $completionRate = $totalStudents > 0 ? round(($totalSubmissions / $totalStudents) * 100, 1) : 0;

        // Department count
        $deptQuery = Department::where('is_active', true);
        if ($tenant) {
            $deptQuery->where('campus_id', $tenant->id);
        }
        $totalDepartments = $deptQuery->count();

        // Active evaluatees
        $totalEvaluatees = $coQuery->pluck('user_id')->unique()->count();

        return [
            Stat::make('Total Submissions', number_format($totalSubmissions))
                ->description('Evaluation responses received')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('primary'),

            Stat::make('Average Score', $avgScore ?? 'N/A')
                ->description($ratingLabel)
                ->descriptionIcon('heroicon-m-star')
                ->color(match (true) {
                    $avgScore === null => 'gray',
                    $avgScore >= 4.5 => 'success',
                    $avgScore >= 3.5 => 'info',
                    $avgScore >= 2.5 => 'warning',
                    default => 'danger',
                }),

            Stat::make('Completion Rate', $completionRate.'%')
                ->description($totalStudents.' total students enrolled')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($completionRate >= 80 ? 'success' : ($completionRate >= 50 ? 'warning' : 'danger')),

            Stat::make('Active Evaluatees', $totalEvaluatees)
                ->description('Across '.$totalDepartments.' departments')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('info'),
        ];
    }
}
