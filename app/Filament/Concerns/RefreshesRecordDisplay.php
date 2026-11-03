<?php

namespace App\Filament\Concerns;

trait RefreshesRecordDisplay
{
    public function refreshDisplayedRecord(): void
    {
        $this->record = $this->resolveRecord($this->getRecord()->getKey());
        $this->authorizeAccess();
        if (! $this->hasInfolist()) {
            $this->fillForm();
        }
    }
}
