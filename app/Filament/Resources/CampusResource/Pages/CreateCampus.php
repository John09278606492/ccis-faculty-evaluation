<?php

namespace App\Filament\Resources\CampusResource\Pages;

use App\Filament\Resources\CampusResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCampus extends CreateRecord
{
    protected static string $resource = CampusResource::class;

    protected static bool $canCreateAnother = false;

    // customize redirect after create
    public function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
