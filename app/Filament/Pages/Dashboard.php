<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\DepartmentOverviewChart;
use App\Filament\Widgets\EvaluationCompletionChart;
use App\Filament\Widgets\EvaluationSubmissionsChart;
use App\Filament\Widgets\InstructorEvaluationSummaryWidget;
use App\Filament\Widgets\LatestUsersWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Filament\Widgets\StudentPendingEvaluationsWidget;
use App\Filament\Widgets\UserRegistrationChart;
use Filament\Pages\Dashboard as BaseDashboard;
use Livewire\Attributes\On;

class Dashboard extends BaseDashboard
{
    protected static string $view = 'filament.pages.dashboard';

    /**
     * Default widget order: stats cards first, then charts/tables.
     */
    protected function getDefaultWidgetOrder(): array
    {
        return [
            // 1. Stats overview (full width)
            StatsOverviewWidget::class,
            // 2-3. Role-specific tables (full width, shown based on role)
            StudentPendingEvaluationsWidget::class,
            InstructorEvaluationSummaryWidget::class,
            // 4-5. Evaluation charts (side-by-side, half width each)
            EvaluationSubmissionsChart::class,
            EvaluationCompletionChart::class,
            // 6. Latest users table (full width, admin only)
            LatestUsersWidget::class,
            // 7-8. Admin charts (side-by-side, half width each)
            DepartmentOverviewChart::class,
            UserRegistrationChart::class,
        ];
    }

    /**
     * Override Filament's getVisibleWidgets to return permission-filtered
     * and user-ordered widgets.
     */
    public function getVisibleWidgets(): array
    {
        $allWidgets = $this->getDefaultWidgetOrder();
        $user = auth()->user();

        // Filter to only visible widgets
        $visible = collect($allWidgets)->filter(function ($widgetClass) {
            if (method_exists($widgetClass, 'canView')) {
                return $widgetClass::canView();
            }

            return true;
        })->values()->all();

        // Apply user's saved order if available
        $savedOrder = $user->widget_order ?? [];

        if (! empty($savedOrder)) {
            // Build lookup: class name => class
            $classMap = [];
            foreach ($visible as $class) {
                $classMap[$class] = $class;
            }

            $ordered = [];
            $remaining = $classMap;

            foreach ($savedOrder as $savedClass) {
                if (isset($remaining[$savedClass])) {
                    $ordered[] = $remaining[$savedClass];
                    unset($remaining[$savedClass]);
                }
            }

            // Append any new widgets not in saved order
            foreach ($remaining as $class) {
                $ordered[] = $class;
            }

            return $ordered;
        }

        return $visible;
    }

    /**
     * Save widget order from drag-and-drop.
     * Receives an array of fully-qualified class names.
     */
    #[On('reorder-widgets')]
    public function reorderWidgets(array $order): void
    {
        $user = auth()->user();
        $user->widget_order = $order;
        $user->save();
    }

    /**
     * Reset widget order to defaults.
     */
    #[On('reset-widget-order')]
    public function resetWidgetOrder(): void
    {
        $user = auth()->user();
        $user->widget_order = null;
        $user->save();
    }
}
