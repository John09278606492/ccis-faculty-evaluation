<?php

namespace App\Filament\Imports;

trait QueuesBulkFiles
{
    public function getJobConnection(): ?string
    {
        return 'bulk';
    }

    public function getJobQueue(): ?string
    {
        return 'bulk';
    }
}
