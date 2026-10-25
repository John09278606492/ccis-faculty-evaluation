<?php

namespace App\Filament\Concerns;

trait ShowsEvaluationWorkflow
{
    public function getEvaluationWorkflowStep(): ?int
    {
        return app(\App\Services\EvaluationWorkflow::class)->resourceStep(static::getResource());
    }
}
