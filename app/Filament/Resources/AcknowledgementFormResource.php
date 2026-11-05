<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AcknowledgementFormResource\Pages;
use App\Filament\Resources\AcknowledgementFormResource\RelationManagers\RecipientsRelationManager;
use App\Models\AcknowledgementForm;
use App\Models\CourseOffering;
use App\Services\AcknowledgementForms;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AcknowledgementFormResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = AcknowledgementForm::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationGroup = 'Assessments';

    protected static ?string $slug = 'acknowledgement-receipts';

    protected static ?string $modelLabel = 'acknowledgement receipt';

    protected static ?string $pluralModelLabel = 'acknowledgement receipts';

    protected static ?string $navigationLabel = 'Acknowledgement Receipts';

    protected static ?int $navigationSort = 5;

    // We scope manually via the course offering -> semester -> school year -> campus chain.
    protected static bool $isScopedToTenant = false;

    public static function getPermissionPrefixes(): array
    {
        return ['view', 'view_any', 'create', 'update', 'delete'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->inCampus(filament()->getTenant()?->id)
            ->when(! auth()->user()?->hasRole('super_admin'), fn (Builder $query) => $query->whereHas('courseOffering', fn ($q) => $q->where('user_id', auth()->id())));
    }

    /** Classes the signed-in instructor may issue a receipt for, in the current campus. */
    public static function classOptions(): array
    {
        return CourseOffering::query()
            ->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', filament()->getTenant()?->id))
            ->when(! auth()->user()?->hasRole('super_admin'), fn ($q) => $q->where('user_id', auth()->id()))
            ->whereHas('students')
            ->with(['course', 'semester.schoolYear'])
            ->get()
            ->mapWithKeys(fn (CourseOffering $class) => [$class->id => "{$class->display_name} — {$class->semester?->name} ({$class->semester?->schoolYear?->name})"])
            ->all();
    }

    public static function form(Form $form): Form
    {
        $defaults = config('acknowledgements.defaults');

        return $form->schema([
            Forms\Components\Section::make('Document given to the class')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('course_offering_id')
                        ->label('Class')
                        ->helperText('Every student enrolled in this class is listed on the receipt automatically.')
                        ->options(fn () => static::classOptions())
                        ->searchable()
                        ->required()
                        ->live()
                        ->disabledOn('edit')
                        ->columnSpanFull()
                        ->afterStateUpdated(function (?string $state, Forms\Set $set) {
                            $class = $state ? CourseOffering::with(['semester.schoolYear', 'instructor', 'course'])->find($state) : null;
                            if (! $class) {
                                return;
                            }
                            foreach (app(AcknowledgementForms::class)->defaultsFor($class) as $field => $value) {
                                $set($field, $value);
                            }
                            $set('document_received', "{$class->course?->name} Syllabus");
                        }),
                    Forms\Components\TextInput::make('document_received')
                        ->required()
                        ->maxLength(255)
                        ->helperText('For example: the course name followed by Syllabus, Midterm Exam or Final Exam.'),
                    Forms\Components\DatePicker::make('date_received')
                        ->default(now())
                        ->native(false)
                        ->displayFormat('F j, Y')
                        ->required(),
                    Forms\Components\Select::make('status')
                        ->options(AcknowledgementForm::STATUSES)
                        ->default('active')
                        ->selectablePlaceholder(false)
                        ->required()
                        ->columnSpanFull(),
                ]),
            Forms\Components\Section::make('Lines printed on the receipt')
                ->description('Filled in from the class. Change them only if the printed form should read differently.')
                ->columns(2)
                ->collapsible()
                ->schema([
                    Forms\Components\TextInput::make('document_title')->default($defaults['document_title'])->required()->maxLength(255),
                    Forms\Components\TextInput::make('year_section')->label('Year & section')->required()->maxLength(255),
                    Forms\Components\TextInput::make('college_name')->label('College line')->default($defaults['college_name'])->required()->maxLength(255),
                    Forms\Components\TextInput::make('department_name')->label('Department line')->default($defaults['department_name'])->required()->maxLength(255),
                    Forms\Components\TextInput::make('term_label')->label('Semester / A.Y. line')->required()->maxLength(255),
                    Forms\Components\TextInput::make('instructor_name')->required()->maxLength(255),
                    Forms\Components\TextInput::make('instructor_designation')->label('Designation')->default($defaults['instructor_designation'])->required()->maxLength(255),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('courseOffering:id,user_id')
                ->withCount(['recipients', 'recipients as signed_count' => fn ($q) => $q->whereNotNull('signed_at')]))
            ->defaultSort('date_received', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('document_received')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('year_section')->label('Section')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('date_received')->date('M j, Y')->sortable(),
                Tables\Columns\TextColumn::make('signed')
                    ->label('Signed')
                    ->state(fn (AcknowledgementForm $record) => "{$record->signed_count} / {$record->recipients_count}")
                    ->badge()
                    ->color(fn (AcknowledgementForm $record) => $record->recipients_count > 0 && $record->signed_count === $record->recipients_count ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'closed' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(fn () => array_map(fn ($label) => strtok($label, ':'), AcknowledgementForm::STATUSES)),
            ], layout: FiltersLayout::AboveContent)
            ->recordUrl(fn (AcknowledgementForm $record) => static::getUrl('edit', ['record' => $record, 'activeRelationManager' => 0]))
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Open')
                    ->url(fn (AcknowledgementForm $record) => static::getUrl('edit', ['record' => $record, 'activeRelationManager' => 0])),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('preview')
                        ->label('Preview & print')
                        ->icon('heroicon-o-document-magnifying-glass')
                        ->url(fn (AcknowledgementForm $record) => static::getUrl('preview', ['record' => $record])),
                    static::copyToClassesAction(),
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn (AcknowledgementForm $record) => $record->signed_count === 0 && auth()->user()->can('delete', $record))
                        ->modalDescription('Only forms without signatures can be deleted. Archive a signed form instead.'),
                ])->tooltip('More actions'),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('printSelected')
                    ->label('Print selected')
                    ->icon('heroicon-o-printer')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records, $livewire) {
                        $livewire->js('window.open('.json_encode(route('acknowledgements.print', ['forms' => $records->modelKeys()])).', "_blank")');
                    }),
                Tables\Actions\BulkAction::make('setStatus')
                    ->label('Set status')
                    ->icon('heroicon-o-lock-closed')
                    ->form([
                        Forms\Components\Select::make('status')->options(AcknowledgementForm::STATUSES)->selectablePlaceholder(false)->default('closed')->required(),
                    ])
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records, array $data) {
                        $allowed = $records->filter(fn (AcknowledgementForm $form) => auth()->user()->can('update', $form))->modelKeys();
                        AcknowledgementForm::whereKey($allowed)->update(['status' => $data['status']]);
                        Notification::make()->title(count($allowed).' receipt(s) updated')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No acknowledgement receipts yet')
            ->emptyStateDescription('Create one for each document you give a class, such as a syllabus or an exam.')
            ->emptyStateIcon('heroicon-o-document-check');
    }

    /** Issues the same document to the instructor's other classes in one step. */
    protected static function copyToClassesAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('copyToClasses')
            ->label('Copy to other classes')
            ->icon('heroicon-o-document-duplicate')
            ->visible(fn () => auth()->user()->can('create', AcknowledgementForm::class))
            ->modalHeading('Copy to other classes')
            ->modalDescription('Each class gets its own receipt with its own students, section and term. A class that already has this document is skipped.')
            ->modalSubmitActionLabel('Create receipts')
            ->form([
                Forms\Components\TextInput::make('document_received')
                    ->default(fn (AcknowledgementForm $record) => $record->document_received)
                    ->helperText('Change this if the other classes take a different subject.')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('classes')
                    ->options(fn (AcknowledgementForm $record) => collect(static::classOptions())->except($record->course_offering_id)->all())
                    ->multiple()
                    ->searchable()
                    ->required(),
            ])
            ->action(function (AcknowledgementForm $record, array $data) {
                $allowed = static::classOptions();
                $forms = app(AcknowledgementForms::class);
                $created = 0;
                $classes = CourseOffering::with(['semester.schoolYear', 'instructor'])->findMany($data['classes']);
                abort_unless($classes->count() === count($data['classes']) && $classes->every(fn ($class) => array_key_exists($class->id, $allowed)), 403);
                foreach ($classes as $class) {
                    $created += $forms->issueTo($record, $class, $data['document_received'], auth()->user()) ? 1 : 0;
                }
                $skipped = $classes->count() - $created;

                Notification::make()
                    ->title("{$created} ".str('receipt')->plural($created).' created')
                    ->body($skipped ? "{$skipped} skipped: those classes already have this document." : 'Students were notified.')
                    ->success()
                    ->send();
            });
    }

    public static function getRelations(): array
    {
        return [RecipientsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAcknowledgementForms::route('/'),
            'create' => Pages\CreateAcknowledgementForm::route('/create'),
            'edit' => Pages\EditAcknowledgementForm::route('/{record}/edit'),
            'preview' => Pages\PreviewAcknowledgementForm::route('/{record}/preview'),
        ];
    }
}
