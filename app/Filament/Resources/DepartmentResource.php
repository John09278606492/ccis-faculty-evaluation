<?php

namespace App\Filament\Resources;

use App\Filament\Actions\AssignDepartmentInstructors;
use App\Filament\Resources\DepartmentResource\Pages;
use App\Filament\Resources\DepartmentResource\RelationManagers\InstructorsRelationManager;
use App\Models\Department;
use App\Models\User;
use App\Services\AcademicPeriod;
use App\Services\DepartmentSupervisorAssignments;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;

class DepartmentResource extends Resource
{
    protected static ?string $model = Department::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Academic Management';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Department Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('code')
                            ->required()
                            ->maxLength(20)
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('campus_id', filament()->getTenant()?->id)),

                        Forms\Components\Textarea::make('description')
                            ->maxLength(1000)
                            ->columnSpanFull(),

                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                    ])->columns(2),
                Forms\Components\Section::make('Department Leadership')
                    ->description('Use the department supervisor to fill missing Annex B assignments in bulk. Individual Teaching Class supervisor selections are kept and can still be edited.')
                    ->schema([...array_map(fn ($field) => Forms\Components\Select::make($field)
                        ->label($field === 'supervisor_id' ? 'Dean / Department Head' : 'Chairperson')
                        ->options(fn () => User::where('is_student', false)->whereHas('roles')->whereHas('campuses', fn ($q) => $q->where('campuses.id', filament()->getTenant()?->id))->orderBy('name')->pluck('name', 'id'))
                        ->searchable()->preload()->placeholder('Not assigned')->live()
                        ->afterStateUpdated(function (Forms\Set $set, $state) use ($field) {
                            if ($field === 'supervisor_id') {
                                $set('fill_missing_supervisors', filled($state));
                            }
                        }), ['supervisor_id', 'chairperson_id']),
                        Forms\Components\Toggle::make('fill_missing_supervisors')->label('Fill missing class supervisors when saving')->default(false)->live()
                            ->visible(fn (Forms\Get $get) => filled($get('supervisor_id')) && app(DepartmentSupervisorAssignments::class)->allowed(auth()->user(), filament()->getTenant()))
                            ->helperText('Adds the selected Dean / Department Head only where no supervisor is assigned. Existing selections and additional supervisors are preserved.'),
                        Forms\Components\Select::make('supervisor_academic_period')->label('Academic period for bulk assignment')
                            ->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default(fn () => AcademicPeriod::current(filament()->getTenant()))
                            ->visible(fn (Forms\Get $get) => (bool) $get('fill_missing_supervisors'))->required(fn (Forms\Get $get) => (bool) $get('fill_missing_supervisors')),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('code')
                    ->searchable()
                    ->sortable()
                    ->badge(),

                Tables\Columns\TextColumn::make('supervisor.name')->label('Dean / Department Head')->placeholder('Not assigned'),
                Tables\Columns\TextColumn::make('chairperson.name')->label('Chairperson')->placeholder('Not assigned'),

                Tables\Columns\TextColumn::make('instructors_count')
                    ->counts('instructors')
                    ->label('Instructors')
                    ->sortable(),

                Tables\Columns\TextColumn::make('courses_count')
                    ->counts('courses')
                    ->label('Courses')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status'),
            ], layout: FiltersLayout::AboveContent)
            ->actions([
                AssignDepartmentInstructors::make(table: true),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            InstructorsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDepartments::route('/'),
            'create' => Pages\CreateDepartment::route('/create'),
            'edit' => Pages\EditDepartment::route('/{record}/edit'),
        ];
    }
}
