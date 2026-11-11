<?php

namespace App\Filament\Resources;

use App\Models\AdvisoryClass;
use App\Services\Advising;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdvisoryClassResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = AdvisoryClass::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Academic Advising';

    protected static ?string $navigationLabel = 'My Advisory Sections';

    protected static ?int $navigationSort = 1;

    protected static ?string $pluralModelLabel = 'advisory sections';

    protected static bool $isScopedToTenant = false;

    public static function getPermissionPrefixes(): array
    {
        return ['view', 'view_any', 'create', 'update', 'delete'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->inCampus(filament()->getTenant()?->id)
            ->when(! auth()->user()?->hasRole('super_admin'), fn ($q) => $q->where('adviser_id', auth()->id()))
            ->with([
                'section.semester' => fn ($q) => $q->withoutGlobalScope('assigned_data'),
                'section.semester.schoolYear' => fn ($q) => $q->withoutGlobalScope('assigned_data'),
                'adviser',
            ])->withCount(['students', 'tasks']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('school_year_id')->label('School year')
                ->options(fn () => DB::table('school_years')->where('campus_id', filament()->getTenant()?->id)->orderByDesc('name')->pluck('name', 'id')->all())
                ->afterStateHydrated(function (Forms\Components\Select $component, ?AdvisoryClass $record) {
                    if ($record) {
                        $component->state(DB::table('semesters')->where('id', $record->section->semester_id)->value('school_year_id'));
                    }
                })
                ->live()->required()->dehydrated(false)
                ->afterStateUpdated(function (Forms\Set $set) {
                    $set('semester_id', null);
                    $set('academic_section_id', null);
                }),
            Forms\Components\Select::make('semester_id')->label('Semester')
                ->options(fn (Forms\Get $get) => DB::table('semesters')->where('school_year_id', $get('school_year_id'))
                    ->whereIn('school_year_id', DB::table('school_years')->where('campus_id', filament()->getTenant()?->id)->select('id'))
                    ->orderBy('start_date')->pluck('name', 'id')->all())
                ->afterStateHydrated(function (Forms\Components\Select $component, ?AdvisoryClass $record) {
                    if ($record) {
                        $component->state($record->section->semester_id);
                    }
                })
                ->live()->required()->dehydrated(false)->disabled(fn (Forms\Get $get) => ! $get('school_year_id'))
                ->afterStateUpdated(fn (Forms\Set $set) => $set('academic_section_id', null)),
            Forms\Components\Select::make('academic_section_id')->label('Section')
                ->options(fn (Forms\Get $get, ?AdvisoryClass $record) => $get('semester_id')
                    ? app(Advising::class)->freeSections(filament()->getTenant()?->id, $record?->id, (int) $get('semester_id')) : [])
                ->disabled(fn (Forms\Get $get) => ! $get('semester_id'))
                ->searchable()->required()->unique(ignoreRecord: true)
                ->helperText('Choose an existing section in this semester. Its members become your advising students. To add a missing semester or section, use Academic Management → School Years or Sections.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('section.name')->label('Section')->searchable(),
            Tables\Columns\TextColumn::make('section.semester.name')->label('Semester'),
            Tables\Columns\TextColumn::make('section.semester.schoolYear.name')->label('School year'),
            Tables\Columns\TextColumn::make('adviser.name')->label('Adviser'),
            Tables\Columns\TextColumn::make('students_count')->label('Students'),
            Tables\Columns\TextColumn::make('tasks_count')->label('Tasks'),
        ])->actions([
            Tables\Actions\EditAction::make()
                ->modalHeading('Edit advisory section')
                ->modalDescription('Change the school year, semester and section. If students have already submitted work, add a new advisory section for the new term instead.')
                ->using(function (AdvisoryClass $record, array $data, Tables\Actions\EditAction $action) {
                    try {
                        return app(Advising::class)->changeSection($record, (int) $data['academic_section_id'], auth()->user());
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages(collect($exception->errors())
                            ->mapWithKeys(fn ($messages, $key) => [$action->getLivewire()->getMountedTableActionForm()->getStatePath().'.'.$key => $messages])->all());
                    }
                }),
            Tables\Actions\Action::make('tasks')->label('Advising tasks')->icon('heroicon-o-clipboard-document-list')
                ->url(fn (AdvisoryClass $record) => AdvisoryTaskResource::getUrl('index', ['tableFilters' => ['advisory_class_id' => ['value' => $record->id]]])),
            Tables\Actions\DeleteAction::make()->label('Release section')
                ->visible(fn (AdvisoryClass $record) => $record->tasks_count === 0)
                ->before(fn (AdvisoryClass $record) => abort_if($record->tasks()->exists(), 422, 'Keep sections with tasks to preserve their submissions.')),
        ])->emptyStateHeading('No advisory sections yet')->emptyStateDescription('Add a section such as BSIT 3A to start assigning advising requirements.');
    }

    public static function getPages(): array
    {
        return ['index' => AdvisoryClassResource\Pages\ManageAdvisoryClasses::route('/')];
    }
}
