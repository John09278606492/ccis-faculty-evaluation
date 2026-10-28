<?php

namespace App\Filament\Resources\EvaluationCycleResource\Pages;

use App\Filament\Concerns\ShowsEvaluationWorkflow;
use App\Filament\Resources\EvaluationCycleResource;
use App\Models\Department;
use App\Services\AcademicPeriod;
use App\Services\AssignedDataAccess;
use App\Services\BackgroundTasks;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListEvaluationCycles extends ListRecords
{
    use ShowsEvaluationWorkflow;

    protected static string $view = 'filament.resources.workflow-list';

    protected static string $resource = EvaluationCycleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prepareDepartments')->label('Prepare Department Evaluations')->icon('heroicon-o-sparkles')
                ->modalHeading('Prepare evaluations in bulk')->modalSubmitActionLabel('Prepare Drafts')
                ->modalDescription('Reuses each department’s previously configured Annex A/B versions and includes prepared classes automatically. Existing evaluations and individual supervisor choices are kept. New drafts stay closed until you review and open them.')
                ->form([
                    Forms\Components\Select::make('semester_id')->label('Academic Period')->required()->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default(fn () => AcademicPeriod::current(filament()->getTenant())),
                    Forms\Components\CheckboxList::make('department_ids')->label('Departments')->required()->bulkToggleable()
                        ->options(fn () => app(AssignedDataAccess::class)->apply(Department::query(), auth()->user(), filament()->getTenant())->where('is_active', true)->pluck('name', 'id'))
                        ->default(fn () => app(AssignedDataAccess::class)->apply(Department::query(), auth()->user(), filament()->getTenant())->where('is_active', true)->whereHas('courses')->pluck('id')->all()),
                ])->action(function (array $data) {
                    app(BackgroundTasks::class)->enqueue(auth()->user(), filament()->getTenant(), 'evaluation-prepare', 'Prepare department evaluation drafts', $data);
                    Notification::make()->success()->title('Draft preparation queued')->body('The drafts will be prepared in the background. The notification bell will show completion and departments needing form selection.')->send();
                }),
            CreateAction::make()->label('Create One Evaluation')->color('gray'),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Prepare department drafts in bulk, or create one evaluation. Prepared classes and configured forms can be included automatically.';
    }
}
