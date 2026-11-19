<?php

namespace App\Filament\Actions;

use App\Models\Department;
use App\Models\User;
use App\Services\DepartmentInstructors;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;

class TransferDepartmentInstructors
{
    private const KEPT = 'Teaching classes, supervisor assignments and submitted responses are kept.';

    public static function make(Department $department): Action
    {
        return Action::make('transferInstructor')->label('Transfer')->icon('heroicon-o-arrows-right-left')->color('gray')
            ->visible(fn () => self::allowed($department))
            ->modalHeading(fn (User $record) => 'Transfer '.$record->name.' out of '.$department->code)
            ->modalWidth('lg')->modalSubmitActionLabel('Transfer')
            ->form(self::form($department))
            ->action(fn (User $record, array $data) => self::run($department, [$record->id], (int) $data['destination']));
    }

    public static function bulk(Department $department): BulkAction
    {
        return BulkAction::make('transferInstructors')->label('Transfer selected')->icon('heroicon-o-arrows-right-left')
            ->visible(fn () => self::allowed($department))
            ->modalHeading('Transfer selected instructors out of '.$department->code)
            ->modalWidth('lg')->modalSubmitActionLabel('Transfer')
            ->form(self::form($department))
            ->action(fn (Collection $records, array $data) => self::run($department, $records->modelKeys(), (int) $data['destination']))
            ->deselectRecordsAfterCompletion();
    }

    public static function detach(Department $department): Action
    {
        return Action::make('detachInstructor')->label('Detach')->icon('heroicon-o-user-minus')->color('danger')
            ->visible(fn () => self::allowed($department))
            ->requiresConfirmation()->modalHeading(fn (User $record) => 'Detach '.$record->name.' from '.$department->code.'?')
            ->modalDescription(self::detachNote())->modalSubmitActionLabel('Detach')
            ->action(fn (User $record) => self::run($department, [$record->id], null));
    }

    public static function detachBulk(Department $department): BulkAction
    {
        return BulkAction::make('detachInstructors')->label('Detach selected')->icon('heroicon-o-user-minus')->color('danger')
            ->visible(fn () => self::allowed($department))
            ->requiresConfirmation()->modalHeading('Detach selected instructors from '.$department->code.'?')
            ->modalDescription(self::detachNote())->modalSubmitActionLabel('Detach')
            ->action(fn (Collection $records) => self::run($department, $records->modelKeys(), null))
            ->deselectRecordsAfterCompletion();
    }

    private static function allowed(Department $department): bool
    {
        return app(DepartmentInstructors::class)->canTransfer(auth()->user(), filament()->getTenant(), $department);
    }

    private static function detachNote(): string
    {
        return 'They will have no home department until their own college adds them with Add instructors. '.self::KEPT;
    }

    private static function form(Department $department): array
    {
        return [Select::make('destination')->label('New home department')->required()->searchable()
            ->options(fn () => app(DepartmentInstructors::class)->destinations(auth()->user(), filament()->getTenant(), $department))
            ->helperText(self::KEPT.' Only the college the instructor belongs to changes.')];
    }

    private static function run(Department $department, array $ids, ?int $destination): void
    {
        $count = app(DepartmentInstructors::class)->transfer(auth()->user(), filament()->getTenant(), $department, $ids, $destination);
        $target = $destination ? Department::withoutGlobalScope('assigned_data')->find($destination)?->code : null;
        Notification::make()->success()
            ->title($count.' '.str('instructor')->plural($count).($target ? ' transferred to '.$target : ' detached from '.$department->code))->send();
    }
}
