<?php

namespace App\Livewire;

use App\Models\Department;
use App\Models\SchoolYear;
use App\Services\AcademicPeriod;
use App\Services\AssignedDataAccess;
use Livewire\Attributes\Locked;
use Livewire\Component;

class WorkspaceContext extends Component
{
    public ?string $semesterId = null;

    #[Locked]
    public string $returnUrl = '';

    public function mount(): void
    {
        $campus = filament()->getTenant();
        $this->semesterId = $campus ? (string) AcademicPeriod::current($campus) : null;
        $this->returnUrl = request()->url();
    }

    public function updatedSemesterId($value): void
    {
        $campus = filament()->getTenant();
        abort_unless($campus && auth()->user()->canAccessTenant($campus), 403);
        AcademicPeriod::select($campus, (int) $value);
        $this->redirect($this->returnUrl, navigate: true);
    }

    public function render()
    {
        $campus = filament()->getTenant();
        if ($campus && (! $this->semesterId || ! array_key_exists($this->semesterId, AcademicPeriod::options($campus)))) {
            $this->semesterId = (string) AcademicPeriod::current($campus);
        }
        $departments = $campus && ! auth()->user()->isStudent()
            ? app(AssignedDataAccess::class)->apply(Department::query(), auth()->user(), $campus)->pluck('code')->implode(', ') : '';

        return view('livewire.workspace-context', ['campus' => $campus, 'departments' => $departments,
            'hasActiveYear' => $campus ? SchoolYear::hasActiveForCampus($campus->id) : true,
            'periods' => $campus ? AcademicPeriod::options($campus) : []]);
    }
}
