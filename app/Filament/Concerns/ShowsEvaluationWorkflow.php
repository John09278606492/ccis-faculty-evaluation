<?php

namespace App\Filament\Concerns;

use App\Services\EvaluationWorkflow;

trait ShowsEvaluationWorkflow
{
    public function getEvaluationWorkflowStep(): ?int
    {
        return app(EvaluationWorkflow::class)->resourceStep(static::getResource());
    }
}
