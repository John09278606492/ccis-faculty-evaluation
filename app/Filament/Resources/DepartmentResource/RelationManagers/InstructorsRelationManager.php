<?php

namespace App\Filament\Resources\DepartmentResource\RelationManagers;

use App\Filament\Actions\AssignDepartmentInstructors;
use App\Filament\Resources\DepartmentResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InstructorsRelationManager extends RelationManager
{
    protected static string $relationship = 'instructors';

    protected static ?string $title = 'Home department instructors';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return DepartmentResource::canView($ownerRecord) && (auth()->user()->can('view_any_user') || auth()->user()->can('update_user'));
    }

    public function table(Table $table): Table
    {
        return $table->description('These instructors have this college as their main department, including faculty who also teach elsewhere.')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Instructor')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('employee_id')->label('Employee ID')->searchable()->placeholder('Not provided'),
                Tables\Columns\TextColumn::make('position')->label('Position')->placeholder('Not provided'),
            ])->headerActions([AssignDepartmentInstructors::make(table: true, department: $this->getOwnerRecord())])
            ->emptyStateHeading('No home department instructors yet')
            ->emptyStateDescription('Use Add instructors to select existing accounts together.');
    }
}
