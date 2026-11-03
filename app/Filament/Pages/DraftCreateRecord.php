<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasResourceFormDraft;
use App\Filament\Concerns\ShowsEvaluationWorkflow;
use Filament\Resources\Pages\CreateRecord;

abstract class DraftCreateRecord extends CreateRecord
{
    use HasResourceFormDraft;
    use ShowsEvaluationWorkflow;

    protected function fillForm(): void
    {
        parent::fillForm();
        // Create & create another starts a fresh working copy.
        $this->completeWorkspaceDraft();
    }
}
