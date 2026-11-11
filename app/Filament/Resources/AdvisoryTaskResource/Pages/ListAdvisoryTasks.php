<?php

namespace App\Filament\Resources\AdvisoryTaskResource\Pages;

use App\Filament\Resources\AdvisoryTaskResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAdvisoryTasks extends ListRecords
{
    protected static string $resource = AdvisoryTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
