<?php

namespace App\Filament\Resources\FormResource\Pages;

use App\Filament\Resources\FormResource;
use App\Models\Form;
use App\Services\EvaluationTemplates;
use Filament\Actions;
use App\Filament\Pages\DraftEditRecord as EditRecord;

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
        $this->record->assertEditable();

        if (! array_key_exists('rating_scale', $data)) {
            $data['rating_scale'] = null;
        }

        // When rating scale is disabled, also clear scoring config
        if (empty($data['rating_scale'])) {
            $data['scoring_config'] = null;
        }

        return $data;
    }

    public function getSubheading(): ?string
    {
        return $this->record->isLocked() ? 'Published form — criteria and scoring are protected. Duplicate as a new version to make changes.' : 'Prepare the criteria groups and preview this form. Then select it in Evaluation Setup.';
    }

    protected function getFormActions(): array
    {
        return $this->record->isLocked() ? [] : parent::getFormActions();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('printInstrument')->label('Print Instrument')->icon('heroicon-o-printer')
                ->modalHeading('Print Instrument')->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn () => view('reports.preview-frame', ['url' => route('form.preview', ['form' => $this->record, 'print' => 1]), 'title' => 'Instrument print preview', 'hint' => 'Review the instrument and use Print / Save PDF inside the preview.'])),
            Actions\Action::make('newVersion')->label('Duplicate as New Version')->icon('heroicon-o-document-duplicate')
                ->visible(fn () => auth()->user()->can('create', Form::class))
                ->action(function () {
                    $copy = app(EvaluationTemplates::class)->duplicate($this->record, filament()->getTenant(), $this->record->name.' — Version '.(($this->record->version ?? 1) + 1));
                    $this->redirect(FormResource::getUrl('edit', ['record' => $copy]));
                }),
            Actions\Action::make('builder')
                ->label('Edit Criteria Groups')
                ->visible(fn () => ! $this->record->isLocked())
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
                ->modalHeading('Form Preview')->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn () => view('reports.preview-frame', ['url' => route('form.preview', $this->record), 'title' => 'Evaluation form preview', 'hint' => 'Preview the evaluator form. Submissions are disabled.'])),
            Actions\DeleteAction::make(),
        ];
    }
}
