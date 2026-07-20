<?php

namespace App\Filament\Widgets;

use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class StatsOverviewWidget extends BaseWidget
{
    use HasWidgetShield;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $stats = [];

        // Super admin / admin stats
        if ($user->isSuperAdmin() || $user->can('view_any_user')) {
            $totalUsers = $tenant
                ? $tenant->users()->count()
                : User::count();

            $totalStudents = $tenant
                ? $tenant->users()->where('is_student', true)->count()
                : User::where('is_student', true)->count();

            $totalInstructors = $tenant
                ? $tenant->users()->where('is_instructor', true)->count()
                : User::where('is_instructor', true)->count();

            $stats[] = Stat::make('Total Users', $totalUsers)
                ->description('Registered users')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')
                ->chart($this->getUserTrend($tenant));

            $stats[] = Stat::make('Students', $totalStudents)
                ->description('Enrolled students')
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('success');

            $stats[] = Stat::make('Instructors', $totalInstructors)
                ->description('Active instructors')
                ->descriptionIcon('heroicon-m-briefcase')
                ->color('info');
        }

        if ($user->isSuperAdmin() || $user->can('view_any_department')) {
            $totalDepartments = $tenant
                ? $tenant->departments()->where('is_active', true)->count()
                : Department::where('is_active', true)->count();

            $stats[] = Stat::make('Departments', $totalDepartments)
                ->description('Active departments')
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('warning');
        }

        if ($user->isSuperAdmin() || $user->can('view_any_course')) {
            $totalCourses = $tenant
                ? Course::whereHas('department', fn ($q) => $q->where('campus_id', $tenant->id))->where('is_active', true)->count()
                : Course::where('is_active', true)->count();

            $stats[] = Stat::make('Courses', $totalCourses)
                ->description('Active courses')
                ->descriptionIcon('heroicon-m-book-open')
                ->color('danger');
        }

        if ($user->isSuperAdmin() || $user->can('view_any_course::offering')) {
            $activeCourseOfferings = $this->getActiveCourseOfferingsCount($tenant);

            $stats[] = Stat::make('Evaluation Assignments', $activeCourseOfferings)
                ->description('Active this semester')
                ->descriptionIcon('heroicon-m-calendar')
                ->color('success');
        }

        if ($user->isSuperAdmin() || $user->can('view_any_form')) {
            $totalForms = $tenant
                ? $tenant->forms()->where('is_active', true)->count()
                : Form::where('is_active', true)->count();

            $totalSubmissions = $this->getTotalSubmissions($tenant);

            $stats[] = Stat::make('Active Forms', $totalForms)
                ->description($totalSubmissions.' total submissions')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('primary');
        }

        // Student-specific stats
        if ($user->isStudent()) {
            $enrolledCount = $user->courseOfferings()
                ->where('is_active', true)
                ->count();

            $pendingEvaluations = $this->getPendingEvaluationsCount($user);
            $completedEvaluations = $this->getCompletedEvaluationsCount($user);

            $stats[] = Stat::make('Enrolled Courses', $enrolledCount)
                ->description('Active enrollments')
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('primary');

            $stats[] = Stat::make('Pending Evaluations', $pendingEvaluations)
                ->description('Awaiting your feedback')
                ->descriptionIcon('heroicon-m-clipboard-document-check')
                ->color($pendingEvaluations > 0 ? 'warning' : 'success');

            $stats[] = Stat::make('Completed Evaluations', $completedEvaluations)
                ->description('Submitted evaluations')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success');
        }

        // Instructor-specific stats
        if ($user->isInstructor()) {
            $myCourseOfferings = CourseOffering::where('user_id', $user->id)
                ->where('is_active', true)
                ->count();

            $totalStudentsEnrolled = CourseOffering::where('user_id', $user->id)
                ->where('is_active', true)
                ->withCount('students')
                ->get()
                ->sum('students_count');

            $evaluationsReceived = FormSubmission::whereHas('courseOffering', fn ($q) => $q->where('user_id', $user->id))
                ->count();

            $stats[] = Stat::make('My Assignments', $myCourseOfferings)
                ->description('Active assignments')
                ->descriptionIcon('heroicon-m-book-open')
                ->color('primary');

            $stats[] = Stat::make('My Evaluators', $totalStudentsEnrolled)
                ->description('Across all assignments')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('info');

            $stats[] = Stat::make('Evaluations Received', $evaluationsReceived)
                ->description('Total feedback received')
                ->descriptionIcon('heroicon-m-star')
                ->color('warning');
        }

        return $stats;
    }

    /**
     * Cumulative user-count sparkline over the last 7 days. Was 7 separate
     * count() queries (one running total per day); now it's a single baseline
     * count plus one grouped query, with the running total computed in PHP.
     */
    private function getUserTrend(?object $tenant): array
    {
        $days = 7;
        $startDate = now()->subDays($days - 1)->startOfDay();

        if ($tenant) {
            $running = $tenant->users()->wherePivot('created_at', '<', $startDate)->count();
            $dailyCounts = DB::table('campus_user')
                ->where('campus_id', $tenant->id)
                ->where('created_at', '>=', $startDate)
                ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
                ->groupBy('day')
                ->pluck('total', 'day');
        } else {
            $running = User::where('created_at', '<', $startDate)->count();
            $dailyCounts = User::query()
                ->where('created_at', '>=', $startDate)
                ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
                ->groupBy('day')
                ->pluck('total', 'day');
        }

        $data = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $running += (int) ($dailyCounts[$date->toDateString()] ?? 0);
            $data[] = $running;
        }

        return $data;
    }

    private function getActiveCourseOfferingsCount(?object $tenant): int
    {
        $query = CourseOffering::where('is_active', true);

        if ($tenant) {
            $query->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', $tenant->id));
        }

        return $query->count();
    }

    private function getTotalSubmissions(?object $tenant): int
    {
        if ($tenant) {
            return FormSubmission::whereHas('form.campuses', fn ($q) => $q->where('campuses.id', $tenant->id))
                ->count();
        }

        return FormSubmission::count();
    }

    private function getPendingEvaluationsCount(object $user): int
    {
        return $user->courseOfferings()
            ->where('is_active', true)
            ->whereNotNull('form_id')
            ->whereDoesntHave('formSubmissions', fn ($q) => $q->where('user_id', $user->id))
            ->count();
    }

    private function getCompletedEvaluationsCount(object $user): int
    {
        return FormSubmission::where('user_id', $user->id)->count();
    }
}
