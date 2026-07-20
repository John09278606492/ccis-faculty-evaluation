<?php

namespace App\Filament\Resources\FormResource\Pages;

use App\Filament\Resources\FormResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditForm extends EditRecord
{
    protected static string $resource = FormResource::class;

    /**
     * Ensure rating_scale and dependent config are cleared when custom scale is toggled off.
     * When the repeater is hidden (use_custom_scale = false), Filament excludes it from the
     * dehydrated data, so we need to explicitly set it to null to clear existing DB values.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! array_key_exists('rating_scale', $data)) {
            $data['rating_scale'] = null;
        }

        // When rating scale is disabled, also clear scoring config
        if (empty($data['rating_scale'])) {
            $data['scoring_config'] = null;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('builder')
                ->label('Build Form')
                ->icon('heroicon-o-squares-plus')
                ->color('primary')
                ->url(fn () => route('filament.admin.pages.forms.{record}.builder', [
                    'tenant' => filament()->getTenant(),
                    'record' => $this->record->id,
                ])),
            Actions\Action::make('preview')
                ->label('Preview Form')
                ->icon('heroicon-o-eye')
                ->color('warning')
                ->url(fn () => route('form.preview', $this->record))
                ->openUrlInNewTab(),
            Actions\DeleteAction::make(),
        ];
    }
}
