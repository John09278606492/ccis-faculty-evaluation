<?php

namespace App\Filament\Concerns;

use App\Services\AcademicPeriod;
use App\Services\DepartmentSupervisorAssignments;
use Filament\Notifications\Notification;

trait QueuesDepartmentSupervisors
{
    protected bool $fillMissingSupervisors = false;

    protected ?int $supervisorAcademicPeriod = null;

    protected function prepareDepartmentSupervisorBulk(array $data): array
    {
        $this->fillMissingSupervisors = ! empty($data['fill_missing_supervisors']) && ! empty($data['supervisor_id']);
        $this->supervisorAcademicPeriod = isset($data['supervisor_academic_period']) ? (int) $data['supervisor_academic_period'] : null;
        if ($this->fillMissingSupervisors) {
            abort_unless(app(DepartmentSupervisorAssignments::class)->allowed(auth()->user(), filament()->getTenant()), 403);
            AcademicPeriod::validate(filament()->getTenant(), (int) $this->supervisorAcademicPeriod);
        }
        unset($data['fill_missing_supervisors'], $data['supervisor_academic_period']);

        return $data;
    }

    protected function queueDepartmentSupervisorBulk(): void
    {
        if (! $this->fillMissingSupervisors) {
            return;
        }
        app(DepartmentSupervisorAssignments::class)->enqueue(auth()->user(), filament()->getTenant(), $this->record->id, $this->supervisorAcademicPeriod);
        $this->fillMissingSupervisors = false;
        $this->data['fill_missing_supervisors'] = false;
        Notification::make()->success()->title('Supervisor assignments queued')->body('Missing class supervisors will be filled in the background. Existing selections are kept. The bell will notify you when finished.')->send();
    }
}
