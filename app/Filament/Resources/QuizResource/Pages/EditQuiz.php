<?php

namespace App\Filament\Resources\QuizResource\Pages;

use App\Filament\Resources\QuizResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class EditQuiz extends EditRecord
{
    protected static string $resource = QuizResource::class;

    #[Locked]
    public string $loadedRevision = '';

    public bool $changedElsewhere = false;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->loadedRevision = $this->currentRevision();
    }

    protected function currentRevision(): string
    {
        return hash('sha256', json_encode($this->getRecord()->fresh()->except(['updated_at', 'class_record_synced_at', 'class_record_sync_error'])));
    }

    #[\Livewire\Attributes\On('classroom-push')]
    public function checkRemoteChanges(string $kind = 'reconnected', ?int $quizId = null, ?int $courseId = null): void
    {
        if (($quizId && $quizId !== $this->getRecord()->id) || ($courseId && $courseId !== $this->getRecord()->course_offering_id)) {
            $this->skipRender();

            return;
        }
        $this->authorizeAccess();
        $this->changedElsewhere = $this->loadedRevision !== $this->currentRevision();
    }

    protected function beforeSave(): void
    {
        $this->checkRemoteChanges();
        if ($this->changedElsewhere) {
            throw ValidationException::withMessages(['data.title' => 'This quiz changed on another device. Copy any unsaved edits, then reload the page before saving.']);
        }
    }

    protected function afterSave(): void
    {
        $this->loadedRevision = $this->currentRevision();
        $this->changedElsewhere = false;
    }

    public function getFooter(): ?View
    {
        return view('filament.partials.quiz-live-status', ['changedElsewhere' => $this->changedElsewhere]);
    }

    protected function getHeaderActions(): array
    {
        return [
            QuizResource::buildHeaderAction(),
            QuizResource::previewHeaderAction(),
            QuizResource::copyLinkHeaderAction(),
            QuizResource::syncClassRecordHeaderAction(),
            Actions\DeleteAction::make(),
        ];
    }
}
