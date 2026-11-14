<?php

namespace App\Filament\Resources\AcknowledgementFormResource\Pages;

use App\Filament\Resources\AcknowledgementFormResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAcknowledgementForms extends ListRecords
{
    protected static string $resource = AcknowledgementFormResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
