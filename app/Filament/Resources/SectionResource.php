<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SectionResource\Pages;
use App\Filament\Resources\SectionResource\RelationManagers\StudentsRelationManager;
use App\Models\AcademicSection;
use App\Services\AcademicPeriod;
use App\Services\AcademicSections;
use App\Services\AcademicStudents;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SectionResource extends Resource
{
    protected static ?string $model = AcademicSection::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $slug = 'sections';

    protected static ?string $navigationGroup = 'Academic Management';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationLabel = 'Sections';

    protected static ?string $modelLabel = 'Section';

    protected static ?string $pluralModelLabel = 'Sections';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        $actor = auth()->user();
        $campus = filament()->getTenant();

        return $actor && $campus && ! $actor->isStudent() && $actor->canAccessTenant($campus) && $actor->can('view_academic_students');
    }

    public static function canCreate(): bool
    {
        return static::canViewAny() && auth()->user()->can('manage_academic_students');
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny() && static::getEloquentQuery()->whereKey($record->id)->exists();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canCreate() && static::canView($record);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return app(AcademicSections::class)->query(auth()->user(), filament()->getTenant())->with(['department', 'semester.schoolYear'])->withCount('students');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Section')->description('Create the section, then upload its student list in the Students tab. Reuse the saved section in Teaching Classes.')->schema([
                Forms\Components\Select::make('semester_id')->label('Academic Period')->required()->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default(fn () => AcademicPeriod::current(filament()->getTenant())),
                Forms\Components\Select::make('department_id')->label('Department')->required()->searchable()->options(fn () => app(AcademicStudents::class)->departments(auth()->user(), filament()->getTenant())->pluck('name', 'id')),
                Forms\Components\TextInput::make('name')->label('Section name')->placeholder('BSIT 3A')->required()->maxLength(50)->helperText('Include the program, year and section in this name. No separate year-level field is needed.'),
                Forms\Components\Toggle::make('is_active')->label('Active')->default(true)->helperText('Inactive sections keep their students and history, but cannot be selected for new class assignments.'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->description('Upload students once per section. Teaching Classes select these saved sections and individual exceptions.')->columns([
            Tables\Columns\TextColumn::make('name')->label('Section')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('department.code')->label('Department')->badge(),
            Tables\Columns\TextColumn::make('semester.name')->label('Academic Period')->description(fn ($record) => $record->semester->schoolYear->name),
            Tables\Columns\TextColumn::make('students_count')->label('Students')->counts('students'),
            Tables\Columns\TextColumn::make('student_list_status')->label('Student list')->state(fn (AcademicSection $record) => $record->students_count ? 'Ready' : 'Upload list first')->badge()->color(fn ($state) => $state === 'Ready' ? 'success' : 'warning'),
            Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
        ])->filters([
            Tables\Filters\SelectFilter::make('semester_id')->label('Academic Period')->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default(fn () => AcademicPeriod::current(filament()->getTenant())),
            Tables\Filters\SelectFilter::make('department_id')->label('Department')->options(fn () => app(AcademicStudents::class)->departments(auth()->user(), filament()->getTenant())->pluck('name', 'id')),
            Tables\Filters\SelectFilter::make('is_active')->label('Status')->options(['1' => 'Active', '0' => 'Inactive'])->default('1'),
        ], layout: FiltersLayout::AboveContent)->filtersFormColumns(3)->actions([
            Tables\Actions\ViewAction::make()->label('Upload / Manage Students')->icon('heroicon-o-user-group')
                ->url(fn (AcademicSection $record) => static::getUrl('view', ['record' => $record, 'activeRelationManager' => '0'])),
            Tables\Actions\EditAction::make(),
        ])->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [StudentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSections::route('/'), 'create' => Pages\CreateSection::route('/create'), 'view' => Pages\ViewSection::route('/{record}'), 'edit' => Pages\EditSection::route('/{record}/edit')];
    }
}
