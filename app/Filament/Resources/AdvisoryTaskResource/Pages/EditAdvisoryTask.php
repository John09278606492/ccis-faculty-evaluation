<?php

namespace App\Filament\Resources\AdvisoryTaskResource\Pages;

use App\Filament\Resources\AdvisoryTaskResource;
use App\Services\Advising;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAdvisoryTask extends EditRecord
{
    protected static string $resource = AdvisoryTaskResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        app(Advising::class)->syncRoster($this->record);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        abort_unless(array_key_exists((int) $data['advising_semester_id'], AdvisoryTaskResource::semesterOptions()), 403);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('remind')->label('Remind waiting students')->icon('heroicon-o-bell')->requiresConfirmation()
            ->visible(fn () => auth()->user()->can('update', $this->record) && $this->record->acceptsSubmissions())
            ->action(function () {
                $this->authorizeAccess();
                abort_unless(auth()->user()->can('update', $this->record), 403);
                app(Advising::class)->syncRoster($this->record);
                $count = app(Advising::class)->notifyWaiting($this->record, 'Academic advising reminder');
                Notification::make()->title("Reminded {$count} students")->success()->send();
            })];
    }
}
