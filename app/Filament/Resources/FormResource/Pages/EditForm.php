<?php

namespace App\Filament\Resources\FormResource\Pages;

use App\Filament\Pages\DraftEditRecord as EditRecord;
use App\Filament\Resources\FormResource;
use App\Models\Form;
use App\Services\EvaluationTemplates;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

class EditForm extends EditRecord
{
    protected static string $resource = FormResource::class;

    protected function shouldKeepWorkspaceDraft(): bool
    {
        return ! $this->record->isLocked();
    }

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

        // Comment requirements are independent of the rating scale.
        if (empty($data['rating_scale'])) {
            $data['scoring_config'] = array_intersect_key($data['scoring_config'] ?? [], array_flip(['overall_comment', 'field_comments']));
        }

        return $data;
    }

    public function getSubheading(): ?string
    {
        return $this->record->isLocked() ? 'Published form: use Overall comments to change the comment requirement. Duplicate to edit criteria or scoring.' : 'Prepare the criteria groups and preview this form. Then select it in Evaluation Setup.';
    }

    protected function getFormActions(): array
    {
        return $this->record->isLocked() ? [] : parent::getFormActions();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('overallComments')->label('Overall comments')->icon('heroicon-o-chat-bubble-left-right')
                ->visible(fn () => $this->record->isLocked() && auth()->user()->can('update', $this->record))
                ->modalHeading('Overall comments')->modalDescription('Applies to new submissions of this form. Existing responses and scores stay unchanged.')
                ->fillForm(fn () => ['mode' => $this->record->fresh()->scoring_config['overall_comment'] ?? 'optional'])
                ->form([Select::make('mode')->label('Overall comments')->options(['required' => 'Required', 'optional' => 'Optional', 'hidden' => 'Hidden'])->required()->in(['required', 'optional', 'hidden'])])
                ->modalSubmitActionLabel('Save comment setting')
                ->action(function (array $data) {
                    abort_unless(auth()->user()->can('update', $this->record), 403);
                    $this->record->getConnection()->transaction(function () use ($data) {
                        $record = $this->record->newQuery()->lockForUpdate()->findOrFail($this->record->id);
                        $record->update(['scoring_config' => [...($record->scoring_config ?? []), 'overall_comment' => $data['mode']]]);
                    });
                    $this->record->refresh();
                    // Refresh this one displayed setting without replacing unsaved form edits.
                    $this->data['scoring_config']['overall_comment'] = $data['mode'];
                    Notification::make()->success()->title('Overall comment setting saved')->send();
                }),
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
