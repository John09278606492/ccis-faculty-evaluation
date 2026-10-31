<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SectionResource\Pages;
use App\Filament\Resources\SectionResource\RelationManagers\StudentsRelationManager;
use App\Models\AcademicSection;
use App\Services\AcademicPeriod;
use App\Services\AcademicSections;
use App\Services\AcademicStudents;
use Filament\Actions\DeleteAction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

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

    protected static ?int $navigationSort = 5;

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
        return static::canEdit($record) && app(AcademicSections::class)->isEmpty($record);
    }

    public static function canDeleteAny(): bool
    {
        return static::canCreate();
    }

    public static function deleteAction(string $class): Tables\Actions\DeleteAction|DeleteAction
    {
        return $class::make()->modalHeading('Delete this empty section?')
            ->modalDescription('It has no students. Classes keep their own rosters and responses keep their recorded section.')
            ->using(fn (AcademicSection $record) => app(AcademicSections::class)->delete(auth()->user(), filament()->getTenant(), $record));
    }

    /** Folds a duplicate section (BSCRIM 2B) into the one kept (BSCRM 2B) after showing how their students compare. */
    public static function mergeAction(): Tables\Actions\Action
    {
        $sections = fn () => app(AcademicSections::class);

        return Tables\Actions\Action::make('merge')->label('Merge')->icon('heroicon-o-arrows-pointing-in')->color('gray')->iconButton()->tooltip('Merge into another section')
            ->visible(fn (AcademicSection $record) => static::canEdit($record))
            ->modalHeading(fn (AcademicSection $record) => 'Merge '.$record->name.' into another section')
            ->modalDescription('Its students move to the section you keep, classes that selected it now select the kept section, and it is deleted. Class rosters and evaluations are not changed.')
            ->modalSubmitActionLabel('Merge')->form([
                Forms\Components\Select::make('into')->label('Keep this section')->required()->live()
                    ->options(function (AcademicSection $record) use ($sections) {
                        return $sections()->mergeTargets(auth()->user(), filament()->getTenant(), $record)->orderBy('name')->get()
                            ->sortBy(fn ($s) => AcademicSections::sameName($s->name, $record->name) ? 0 : 1)
                            ->mapWithKeys(fn ($s) => [$s->id => $s->name.(AcademicSections::sameName($s->name, $record->name) ? ' (same name, different spelling)' : '')]);
                    })
                    ->default(fn (AcademicSection $record) => $sections()->mergeTargets(auth()->user(), filament()->getTenant(), $record)->get()
                        ->first(fn ($s) => AcademicSections::sameName($s->name, $record->name))?->id),
                Forms\Components\Placeholder::make('comparison')->label('Comparison')->visible(fn (Forms\Get $get) => filled($get('into')))
                    ->content(function (AcademicSection $record, Forms\Get $get) use ($sections) {
                        $into = $sections()->mergeTargets(auth()->user(), filament()->getTenant(), $record)->find($get('into'));
                        if (! $into) {
                            return '';
                        }
                        $ids = fn ($section) => $section->students()->pluck('student_id')->all();
                        [$from, $kept] = [$ids($record), $ids($into)];
                        $both = array_intersect($from, $kept);
                        $onlyFrom = array_diff($from, $kept);
                        $onlyKept = array_diff($kept, $from);
                        $list = fn ($values) => $values ? implode(', ', array_slice(array_values($values), 0, 60)).(count($values) > 60 ? ' …' : '') : 'none';

                        return new HtmlString(
                            '<p><strong>'.count($both).'</strong> students are in both. '.($onlyFrom || $onlyKept ? '' : '<strong>The two sections are identical.</strong>').'</p>'
                            .'<p class="mt-2">Only in '.e($record->name).' (will move): <strong>'.count($onlyFrom).'</strong><br><span class="text-gray-500">'.e($list($onlyFrom)).'</span></p>'
                            .'<p class="mt-2">Only in '.e($into->name).' (stay): <strong>'.count($onlyKept).'</strong><br><span class="text-gray-500">'.e($list($onlyKept)).'</span></p>');
                    }),
            ])->action(function (AcademicSection $record, array $data) use ($sections) {
                $into = AcademicSection::findOrFail($data['into']);
                $added = $sections()->merge(auth()->user(), filament()->getTenant(), $record, $into);
                Notification::make()->success()->title($record->name.' merged into '.$into->name)->body($added.' students were new to '.$into->name.'.')->send();
            });
    }

    public static function getEloquentQuery(): Builder
    {
        return app(AcademicSections::class)->query(auth()->user(), filament()->getTenant())->with(['department', 'semester.schoolYear'])->withCount('students');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Section Details')->schema([
                Forms\Components\Select::make('semester_id')->label('Academic Period')->required()->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default(fn () => AcademicPeriod::current(filament()->getTenant())),
                Forms\Components\Select::make('department_id')->label('Department')->required()->searchable()->options(fn () => app(AcademicStudents::class)->departments(auth()->user(), filament()->getTenant())->pluck('name', 'id')),
                Forms\Components\TextInput::make('name')->label('Section name')->placeholder('BSIT 3A')->required()->maxLength(50),
                Forms\Components\Toggle::make('is_active')->label('Active')->default(true)->helperText('Inactive sections cannot be assigned to new classes.'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
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
            Tables\Actions\ViewAction::make()->label('Students')->icon('heroicon-o-user-group')
                ->url(fn (AcademicSection $record) => static::getUrl('view', ['record' => $record, 'activeRelationManager' => '0'])),
            Tables\Actions\EditAction::make(),
            static::mergeAction(),
            static::deleteAction(Tables\Actions\DeleteAction::class)->iconButton(),
        ])->bulkActions([
            Tables\Actions\BulkAction::make('deleteEmpty')->label('Delete empty sections')->icon('heroicon-o-trash')->color('danger')
                ->visible(fn () => static::canDeleteAny())->requiresConfirmation()->modalHeading('Delete empty sections?')
                ->modalDescription('Only selected sections with no students are deleted. Others are skipped.')
                ->deselectRecordsAfterCompletion()
                ->action(function (Collection $records) {
                    $deleted = 0;
                    foreach ($records as $record) {
                        if (static::canDelete($record)) {
                            app(AcademicSections::class)->delete(auth()->user(), filament()->getTenant(), $record);
                            $deleted++;
                        }
                    }
                    Notification::make()->success()->title($deleted.' sections deleted')->body(($records->count() - $deleted).' skipped because they have students.')->send();
                }),
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
