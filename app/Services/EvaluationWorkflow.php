<?php

namespace App\Services;

use App\Filament\Pages\EvaluationResults;
use App\Filament\Pages\StudentEvaluationProgress;
use App\Filament\Resources\AcademicStudentResource;
use App\Filament\Resources\EvaluationCycleResource;
use App\Filament\Resources\FormResource;
use App\Filament\Resources\SectionResource;
use App\Filament\Resources\TeachingClassResource;

/** One vocabulary and route map for the evaluation workflow. Policies remain authoritative. */
class EvaluationWorkflow
{
    public function steps(): array
    {
        if (! auth()->user() || auth()->user()->isStudent() || ! filament()->getTenant() || ! auth()->user()->canAccessTenant(filament()->getTenant())) {
            return [];
        }
        $steps = [];
        if (AcademicStudentResource::canViewAny()) {
            $steps[] = ['number' => 1, 'title' => 'Students', 'description' => 'Upload and confirm the campus student list.',
                'links' => [['label' => 'Students', 'url' => AcademicStudentResource::getUrl()]]];
        }
        if (SectionResource::canViewAny()) {
            $steps[] = ['number' => 2, 'title' => 'Sections', 'description' => 'Prepare section lists now, or assign sections after matching enrolled students.',
                'links' => [['label' => 'Sections', 'url' => SectionResource::getUrl()]]];
        }
        if (TeachingClassResource::canViewAny()) {
            $steps[] = ['number' => 3, 'title' => 'Teaching Classes', 'description' => 'Match enrolled students or use section lists, then select supervisors.',
                'links' => [['label' => 'Teaching Classes', 'url' => TeachingClassResource::getUrl()]]];
        }
        $setup = EvaluationCycleResource::canViewAny();
        $links = $setup ? [['label' => 'Evaluations', 'url' => EvaluationCycleResource::getUrl()]] : [];
        if (FormResource::canViewAny()) {
            $links[] = ['label' => 'Form Templates', 'url' => FormResource::getUrl()];
        }
        if ($links) {
            $steps[] = ['number' => 4, 'title' => $setup ? 'Evaluations' : 'Form Templates',
                'description' => $setup ? 'Prepare department drafts, include ready classes and review before opening. Dates are optional.' : 'Prepare the student and supervisor forms.', 'links' => $links];
        }
        $reports = [];
        if (EvaluationResults::canAccess()) {
            $reports[] = ['label' => 'Results & Reports', 'url' => EvaluationResults::getUrl()];
        }
        if (StudentEvaluationProgress::canAccess()) {
            $reports[] = ['label' => 'Student Progress', 'url' => StudentEvaluationProgress::getUrl()];
        }
        if ($reports) {
            $steps[] = ['number' => 5, 'title' => 'Results', 'description' => 'Check completion and print reports.', 'links' => $reports];
        }

        return $steps;
    }

    public function resourceStep(string $resource): ?int
    {
        return match ($resource) {
            AcademicStudentResource::class => 1,
            SectionResource::class => 2,
            TeachingClassResource::class => 3,
            EvaluationCycleResource::class, FormResource::class => 4,
            default => null,
        };
    }
}
