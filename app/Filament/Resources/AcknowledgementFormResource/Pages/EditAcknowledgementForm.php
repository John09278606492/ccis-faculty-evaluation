<?php

namespace App\Filament\Resources\AcknowledgementFormResource\Pages;

use App\Filament\Resources\AcknowledgementFormResource;
use App\Services\AcknowledgementForms;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAcknowledgementForm extends EditRecord
{
    protected static string $resource = AcknowledgementFormResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        app(AcknowledgementForms::class)->syncRoster($this->getRecord());
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Receipt details';
    }

    public function getTitle(): string
    {
        return $this->getRecord()->document_received;
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->year_section.' · '.$this->getRecord()->date_label;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('preview')
                ->label('Preview & print')
                ->icon('heroicon-o-document-magnifying-glass')
                ->color('gray')
                ->url(fn () => static::getResource()::getUrl('preview', ['record' => $this->getRecord()])),
        ];
    }
}
