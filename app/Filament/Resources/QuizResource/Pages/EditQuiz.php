<?php

namespace App\Filament\Resources\QuizResource\Pages;

use App\Filament\Pages\DraftEditRecord as EditRecord;
use App\Filament\Resources\QuizResource;
use Filament\Actions;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

class EditQuiz extends EditRecord
{
    protected static string $resource = QuizResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Settings & schedule';
    }

    public function getTitle(): string
    {
        return $this->getRecord()->kindLabel().': '.$this->getRecord()->title;
    }

    public function getSubheading(): ?string
    {
        return 'Details → Questions → Participants & conduct → Grade & results. Review readiness before publishing. Exam scores can be exported; quizzes and activities can also sync to class records.';
    }

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

    #[On('classroom-push')]
    public function checkRemoteChanges(string $kind = 'reconnected', ?int $quizId = null, ?int $courseId = null): void
    {
        if (in_array($kind, ['focus', 'progress'], true) || ($quizId && $quizId !== $this->getRecord()->id) || ($courseId && $courseId !== $this->getRecord()->course_offering_id)) {
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
            throw ValidationException::withMessages(['data.title' => 'This assessment changed on another device. Copy any unsaved edits, then reload the page before saving.']);
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
            Actions\Action::make('studentAccess')->label('Participants & conduct')->icon('heroicon-o-user-group')
                ->color('gray')->action(fn () => $this->activeRelationManager = '1'),
            Actions\Action::make('grading')->label('Grade & results')->icon('heroicon-o-clipboard-document-check')
                ->color('gray')->action(fn () => $this->activeRelationManager = '2'),
            Actions\ActionGroup::make([
                QuizResource::previewHeaderAction(),
                QuizResource::copyLinkHeaderAction(),
                QuizResource::syncClassRecordHeaderAction(),
                QuizResource::releaseResultsHeaderAction(),
                Actions\DeleteAction::make(),
            ])->label('More actions')->button()->color('gray'),
        ];
    }
}
