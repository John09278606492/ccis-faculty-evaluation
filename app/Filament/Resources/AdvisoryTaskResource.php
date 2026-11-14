<?php

namespace App\Filament\Resources;

use App\Models\AdvisoryTask;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AdvisoryTaskResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = AdvisoryTask::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Academic Advising';

    protected static ?string $navigationLabel = 'Advising Tasks';

    protected static ?int $navigationSort = 2;

    protected static bool $isScopedToTenant = false;

    public static function getPermissionPrefixes(): array
    {
        return ['view', 'view_any', 'create', 'update', 'delete'];
    }

    public static function classOptions(): array
    {
        return AdvisoryClassResource::getEloquentQuery()->get()->mapWithKeys(fn ($class) => [
            $class->id => $class->name.' — '.$class->section?->semester?->name.' ('.$class->section?->semester?->schoolYear?->name.')',
        ])->all();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('advisory_class_id', AdvisoryClassResource::getEloquentQuery()->select('advisory_classes.id')->reorder())
            ->with(['advisoryClass.section', 'advisingSemester.schoolYear'])->withCount(['submissions', 'submissions as submitted_count' => fn ($q) => $q->whereIn('status', ['submitted', 'approved'])]);
    }

    public static function semesterOptions(): array
    {
        return DB::table('semesters as semester')->join('school_years as year', 'year.id', '=', 'semester.school_year_id')
            ->where('year.campus_id', filament()->getTenant()?->id)->orderByDesc('year.name')->orderBy('semester.start_date')
            ->get(['semester.id', 'semester.name', 'year.name as year'])
            ->mapWithKeys(fn ($row) => [$row->id => $row->name.' — '.$row->year])->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('advisory_class_id')->label('Advisory section')->options(fn () => static::classOptions())->searchable()->required()->disabledOn('edit'),
            Forms\Components\Select::make('advising_semester_id')->label('Advising for')->options(fn () => static::semesterOptions())->searchable()->required()
                ->in(fn () => array_keys(static::semesterOptions()))
                ->helperText('The semester students are preparing to enroll in, for example Second Semester. Their student records and section membership stay unchanged. Add a missing term under Academic Management → School Years.'),
            Forms\Components\TextInput::make('title')->required()->maxLength(255),
            Forms\Components\Textarea::make('instructions')->rows(4)->maxLength(20000)->columnSpanFull(),
            Forms\Components\TagsInput::make('requirements')->label('Requirements')->helperText('Enter each required screenshot or document, then press Enter.')->nestedRecursiveRules(['string', 'max:255'])->required()->columnSpanFull(),
            Forms\Components\CheckboxList::make('accepted_types')->label('File types students can upload')
                ->options(array_map(fn ($type) => $type['label'], AdvisoryTask::FILE_TYPES))
                ->default(AdvisoryTask::DEFAULT_FILE_TYPES)
                ->afterStateHydrated(fn (Forms\Components\CheckboxList $component, ?AdvisoryTask $record) => $record && ! $record->accepted_types ? $component->state($record->fileTypes()) : null)
                ->required()->columns(2)->columnSpanFull()
                ->helperText('Students can only pick files of the checked types. Each file can be up to 10 MB.'),
            Forms\Components\DateTimePicker::make('due_at')->label('Due date')->timezone('Asia/Manila')->seconds(false)->required(),
            Forms\Components\Toggle::make('allow_late')->label('Accept submissions after the deadline')->default(false),
            Forms\Components\TextInput::make('consequence')->label('Notice to students')->default(AdvisoryTask::DEFAULT_CONSEQUENCE)->maxLength(255)->columnSpanFull()
                ->helperText('Shown to students. Enrollment decisions remain with the adviser; this module does not change enrollment automatically.'),
            Forms\Components\Select::make('status')->options(AdvisoryTask::STATUSES)->default('open')->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('title')->searchable()->wrap(),
            Tables\Columns\TextColumn::make('advisoryClass.section.name')->label('Section'),
            Tables\Columns\TextColumn::make('advising_term')->label('Advising for')->wrap(),
            Tables\Columns\TextColumn::make('due_at')->dateTime('M j, Y g:ia', timezone: 'Asia/Manila')->sortable(),
            Tables\Columns\TextColumn::make('progress')->state(fn ($record) => "{$record->submitted_count} / {$record->submissions_count}")->label('Submitted'),
            Tables\Columns\TextColumn::make('status')->badge(),
        ])->defaultSort('due_at')->filters([
            Tables\Filters\SelectFilter::make('advisory_class_id')->label('Section')->options(fn () => static::classOptions()),
            Tables\Filters\SelectFilter::make('status')->options(AdvisoryTask::STATUSES),
        ])
            ->recordUrl(fn (AdvisoryTask $record) => static::getUrl('submissions', ['record' => $record]))
            ->actions([
                Tables\Actions\Action::make('submissions')->label('Submissions')->icon('heroicon-o-inbox-arrow-down')->button()
                    ->url(fn (AdvisoryTask $record) => static::getUrl('submissions', ['record' => $record])),
                Tables\Actions\EditAction::make()->label('Edit task'),
            ]);
    }

    /** Submissions have their own pages, apart from the task settings. */
    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => AdvisoryTaskResource\Pages\ListAdvisoryTasks::route('/'),
            'create' => AdvisoryTaskResource\Pages\CreateAdvisoryTask::route('/create'),
            'edit' => AdvisoryTaskResource\Pages\EditAdvisoryTask::route('/{record}/edit'),
            'submissions' => AdvisoryTaskResource\Pages\ManageSubmissions::route('/{record}/submissions'),
            'review' => AdvisoryTaskResource\Pages\ReviewSubmission::route('/{record}/submissions/{submission}'),
        ];
    }
}
