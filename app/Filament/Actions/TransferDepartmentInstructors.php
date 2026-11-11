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
    private const NONE = 'none';

    public static function make(Department $department): Action
    {
        return Action::make('transferInstructor')->label('Transfer')->icon('heroicon-o-arrows-right-left')->color('gray')
            ->visible(fn () => self::allowed($department))
            ->modalHeading(fn (User $record) => 'Transfer '.$record->name.' out of '.$department->code)
            ->modalWidth('lg')->modalSubmitActionLabel('Transfer')
            ->form(self::form($department))
            ->action(fn (User $record, array $data) => self::run($department, [$record->id], $data['destination']));
    }

    public static function bulk(Department $department): BulkAction
    {
        return BulkAction::make('transferInstructors')->label('Transfer selected')->icon('heroicon-o-arrows-right-left')
            ->visible(fn () => self::allowed($department))
            ->modalHeading('Transfer selected instructors out of '.$department->code)
            ->modalWidth('lg')->modalSubmitActionLabel('Transfer')
            ->form(self::form($department))
            ->action(fn (Collection $records, array $data) => self::run($department, $records->modelKeys(), $data['destination']))
            ->deselectRecordsAfterCompletion();
    }

    private static function allowed(Department $department): bool
    {
        return app(DepartmentInstructors::class)->canTransfer(auth()->user(), filament()->getTenant(), $department);
    }

    private static function form(Department $department): array
    {
        return [Select::make('destination')->label('New home department')->required()->searchable()
            ->options(fn () => [self::NONE => 'No home department (remove from '.$department->code.')']
                + app(DepartmentInstructors::class)->destinations(auth()->user(), filament()->getTenant(), $department))
            ->helperText('Teaching classes, supervisor assignments and submitted responses are kept. Only the college the instructor belongs to changes.')];
    }

    private static function run(Department $department, array $ids, string|int $destination): void
    {
        $destination = $destination === self::NONE ? null : (int) $destination;
        $count = app(DepartmentInstructors::class)->transfer(auth()->user(), filament()->getTenant(), $department, $ids, $destination);
        $target = $destination ? Department::withoutGlobalScope('assigned_data')->find($destination)?->code : null;
        Notification::make()->success()
            ->title($count.' '.str('instructor')->plural($count).($target ? ' transferred to '.$target : ' removed from '.$department->code))->send();
    }
}
