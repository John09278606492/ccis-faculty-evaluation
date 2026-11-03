<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Concerns\RefreshesRecordDisplay;
use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    use RefreshesRecordDisplay;

    protected static string $view = 'filament.pages.live-record-view';

    protected static string $resource = UserResource::class;

    protected static bool $canCreateAnother = false;

    // customize redirect after create
    public function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
