<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasResourceFormDraft;

abstract class DraftCreateRecord extends \Filament\Resources\Pages\CreateRecord
{
    use HasResourceFormDraft;
    use \App\Filament\Concerns\ShowsEvaluationWorkflow;

    protected function fillForm(): void
    {
        parent::fillForm();
        // Create & create another starts a fresh working copy.
        $this->completeWorkspaceDraft();
    }
}
