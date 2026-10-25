<?php

namespace App\Services;

use App\Filament\Pages\BackgroundProcessing;
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
        if (! auth()->user() || auth()->user()->isStudent() || ! filament()->getTenant() || ! auth()->user()->canAccessTenant(filament()->getTenant())) return [];
        $steps = [];
        $records = [];
        if (AcademicStudentResource::canViewAny()) $records[] = ['label' => 'Student Master List', 'url' => AcademicStudentResource::getUrl()];
        if (SectionResource::canViewAny()) $records[] = ['label' => 'Sections', 'url' => SectionResource::getUrl()];
        if ($records) {
            if (BackgroundProcessing::canAccess()) $records[] = ['label' => 'Review / Confirm Uploads', 'url' => BackgroundProcessing::getUrl()];
            $steps[] = ['number' => 1, 'title' => 'Students & sections', 'description' => 'Import the campus master list and confirm it. Then upload each section’s students.', 'links' => $records];
        }
        if (TeachingClassResource::canViewAny()) {
            $steps[] = ['number' => 2, 'title' => 'Teaching classes', 'description' => 'Choose the faculty and subject. Assign the saved sections and any individual exceptions.',
                'links' => [['label' => 'Teaching Classes', 'url' => TeachingClassResource::getUrl()]]];
        }
        $setup = EvaluationCycleResource::canViewAny();
        $links = $setup ? [['label' => 'Evaluation Setup', 'url' => EvaluationCycleResource::getUrl()]] : [];
        if (FormResource::canViewAny()) $links[] = ['label' => 'Evaluation Forms', 'url' => FormResource::getUrl()];
        if ($links) $steps[] = ['number' => 3, 'title' => $setup ? 'Open evaluations' : 'Evaluation forms',
            'description' => $setup ? 'Choose Annex A/B, dates and prepared classes. Review the setup, then open the evaluation.' : 'Prepare Annex A/B forms for the staff who manage the evaluation schedule.', 'links' => $links];
        $reports = [];
        if (StudentEvaluationProgress::canAccess()) $reports[] = ['label' => 'Monitor Student Progress', 'url' => StudentEvaluationProgress::getUrl()];
        if (EvaluationResults::canAccess()) $reports[] = ['label' => 'Faculty Results', 'url' => EvaluationResults::getUrl()];
        if ($reports) $steps[] = ['number' => 4, 'title' => 'Monitor & print', 'description' => 'Follow up by section or student. Review SET/SEF and preview or print reports.', 'links' => $reports];

        return $steps;
    }

    public function resourceStep(string $resource): ?int
    {
        return match ($resource) {
            AcademicStudentResource::class, SectionResource::class => 1,
            TeachingClassResource::class => 2,
            EvaluationCycleResource::class, FormResource::class => 3,
            default => null,
        };
    }
}
