<?php

namespace App\Filament\Concerns;

use Livewire\Attributes\On;

trait ReceivesClassroomUpdates
{
    #[On('classroom-push')]
    public function receiveClassroomUpdate(string $kind = 'reconnected', ?int $quizId = null, ?int $courseId = null): void
    {
        if (method_exists($this, 'getOwnerRecord') && $quizId && (int) $this->getOwnerRecord()->id !== $quizId) {
            $this->skipRender();

            return;
        }
        $action = $this->getMountedTableAction();
        if ($action && ! in_array($action->getName(), ['previewWorkbook', 'syncClassRecord'], true)) {
            $this->skipRender();

            return;
        }
        // Normal Livewire rendering re-queries the authorized table and open read-only previews.
    }
}
