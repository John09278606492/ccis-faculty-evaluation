<?php

namespace App\Filament\Actions;

use App\Models\Department;
use App\Services\DepartmentInstructors;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Database\Eloquent\Model;

class AssignDepartmentInstructors
{
    public static function make(bool $table = false, ?Department $department = null): Action|TableAction
    {
        $resolve = fn (?Model $record): Department => $department ?? $record;
        $action = $table ? TableAction::make('assignInstructors') : Action::make('assignInstructors');

        return $action->label('Add instructors')->icon('heroicon-o-user-plus')
            ->visible(fn (?Model $record) => app(DepartmentInstructors::class)->allowed(auth()->user(), filament()->getTenant(), $resolve($record)))
            ->modalHeading(fn (?Model $record) => 'Add instructors to '.$resolve($record)->code)
            ->modalDescription('Select instructors whose main department is this college. Only accounts without a home department are listed.')
            ->modalWidth('3xl')->modalSubmitActionLabel('Assign to department')
            ->form([CheckboxList::make('ids')->label('Instructors')->required()->searchable()->bulkToggleable()->columns(['default' => 1, 'md' => 2])
                ->options(fn (?Model $record) => app(DepartmentInstructors::class)->options(auth()->user(), filament()->getTenant(), $resolve($record)))
                ->helperText('Search by name or employee ID. Teaching classes, supervisor assignments and previous responses are kept.')])
            ->action(function (?Model $record, array $data) use ($resolve) {
                $record = $resolve($record);
                $count = app(DepartmentInstructors::class)->assign(auth()->user(), filament()->getTenant(), $record, $data['ids']);
                Notification::make()->success()->title($count.' instructors assigned to '.$record->code)->send();
            });
    }
}
