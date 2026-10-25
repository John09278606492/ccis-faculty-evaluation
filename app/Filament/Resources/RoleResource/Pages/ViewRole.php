<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Concerns\RefreshesRecordDisplay;
use App\Filament\Resources\RoleResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewRole extends ViewRecord
{
    use RefreshesRecordDisplay;

    protected static string $view = 'filament.pages.live-record-view';

    protected static string $resource = RoleResource::class;

    protected function getActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
