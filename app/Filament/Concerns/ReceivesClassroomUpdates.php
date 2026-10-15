<?php

namespace App\Filament\Concerns;

use Livewire\Attributes\On;
use ReflectionProperty;

trait ReceivesClassroomUpdates
{
    #[On('classroom-push')]
    public function receiveClassroomUpdate(string $kind = 'reconnected', ?int $quizId = null, ?int $courseId = null): void
    {
        // Relation managers load lazily. An update that arrives before the table has
        // been built (right after page load, or when the live connection reconnects)
        // must not render it; the lazy load shows current data moments later.
        if (property_exists($this, 'table') && ! (new ReflectionProperty($this, 'table'))->isInitialized($this)) {
            $this->skipRender();

            return;
        }
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
