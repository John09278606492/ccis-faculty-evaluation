<?php

namespace App\Filament\Resources\AcknowledgementFormResource\Pages;

use App\Filament\Resources\AcknowledgementFormResource;
use App\Models\AcknowledgementRecipient;
use Filament\Actions;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/** The receipt as it will print, inside the panel. Its Print button prints only the document. */
class PreviewAcknowledgementForm extends Page
{
    use InteractsWithRecord;

    protected static string $resource = AcknowledgementFormResource::class;

    protected static string $view = 'filament.resources.acknowledgement-form-resource.pages.preview';

    public int $signedCount = 0;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(auth()->user()->can('view', $this->getRecord()), 403);
        $this->signedCount = $this->countSigned();
    }

    public function getTitle(): string
    {
        return 'Preview: '.$this->getRecord()->document_received;
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->year_section.' · '.$this->getRecord()->date_label;
    }

    public function getPrintUrl(): string
    {
        return route('acknowledgements.print', ['forms' => $this->getRecord()->id]);
    }

    /** Polled by the page: the document only reloads when a signature actually changed. */
    public function checkForNewSignatures(): void
    {
        $count = $this->countSigned();
        if ($count !== $this->signedCount) {
            $this->signedCount = $count;
            $this->dispatch('acknowledgement-receipt-changed');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('students')
                ->label('Students & signatures')
                ->icon('heroicon-o-users')
                ->color('gray')
                ->url(fn () => static::getResource()::getUrl('edit', ['record' => $this->getRecord(), 'activeRelationManager' => 0])),
            Actions\Action::make('newTab')
                ->label('Open in new tab')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn () => $this->getPrintUrl(), shouldOpenInNewTab: true),
        ];
    }

    /** Signatures plus removed students (weighted apart), so either change reloads the preview. */
    private function countSigned(): int
    {
        $counts = AcknowledgementRecipient::where('acknowledgement_form_id', $this->getRecord()->id)
            ->toBase()->selectRaw('count(signed_at) as signed, count(removed_at) as removed')->first();

        return (int) $counts->signed + 10000 * (int) $counts->removed;
    }
}
