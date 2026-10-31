<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ReceivesClassroomUpdates;
use App\Models\ClassRecordSheet;
use App\Models\ClassRecordUpload;
use App\Models\CourseOffering;
use App\Services\BackgroundTasks;
use App\Services\ClassRecordSyncService;
use App\Services\WorkbookPreviewService;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ClassRecords extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;
    use ReceivesClassroomUpdates;

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static string $view = 'filament.pages.class-records';

    protected static ?string $navigationLabel = 'Class Records';

    protected static ?string $title = 'Class Records';

    protected static ?string $navigationGroup = 'Assessments';

    protected static ?int $navigationSort = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->heading('Your class record sheets')
            ->description('Upload → map each sheet → preview and sync final scores. Download the workbook when ready.')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('sheet_name')->label('Worksheet')->searchable()->weight('semibold')
                    ->description(fn (ClassRecordSheet $record) => $record->upload->original_filename),
                Tables\Columns\TextColumn::make('period')->badge()
                    ->formatStateUsing(fn (string $state) => ClassRecordUpload::PERIODS[$state] ?? $state),
                Tables\Columns\TextColumn::make('courseOffering.course.code')->label('Mapped course')
                    ->formatStateUsing(fn ($state, ClassRecordSheet $record) => $record->courseOffering?->display_name)
                    ->description(fn (ClassRecordSheet $record) => $record->courseOffering?->semester?->name.' '.$record->courseOffering?->semester?->schoolYear?->name)
                    ->placeholder('Choose a course'),
                Tables\Columns\TextColumn::make('student_id_header')->label('Student ID header')->placeholder('Choose a header'),
                Tables\Columns\TextColumn::make('mapping_status')->label('Setup')->badge()
                    ->state(fn (ClassRecordSheet $record) => $record->course_offering_id && filled($record->student_id_header) ? 'Mapped' : 'Needs mapping')
                    ->color(fn (string $state) => $state === 'Mapped' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('quiz_columns_count')->counts('quizColumns')->label('Linked assessments')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('period')->options(ClassRecordUpload::PERIODS),
                Tables\Filters\Filter::make('needs_mapping')->label('Needs mapping')
                    ->query(fn (Builder $query) => $query->where(fn ($q) => $q->whereNull('course_offering_id')->orWhere('student_id_header', ''))),
            ])
            ->headerActions([
                Tables\Actions\Action::make('upload')->label('Upload class record')->icon('heroicon-o-arrow-up-tray')
                    ->modalDescription('Use the official Excel template: numbered students in column A, names in B, a Student ID header and a HIGHEST POSSIBLE SCORE row. Each worksheet is mapped separately.')
                    ->form([
                        Forms\Components\Select::make('period')->label('Grading period')->options(ClassRecordUpload::PERIODS)->required(),
                        Forms\Components\FileUpload::make('file')->label('Excel workbook')->required()->disk('local')
                            ->storeFiles(false)->storeFileNamesIn('original_filename')->maxSize(20480)
                            ->helperText('XLSX or XLS, up to 20 MB. A separate copy is kept for every upload.')
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel']),
                    ])->action(fn (array $data) => $this->handleUpload($data)),
            ])
            ->actions([
                Tables\Actions\Action::make('previewWorkbook')->label('Preview workbook')->icon('heroicon-o-eye')
                    ->extraModalWindowAttributes(['class' => 'workbook-preview-modal'])
                    ->modalHeading('Workbook preview')->modalWidth('7xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->form([
                        Forms\Components\Select::make('preview_sheet')->label('Worksheet')
                            ->options(function (ClassRecordSheet $record) {
                                try {
                                    return app(WorkbookPreviewService::class)->sheetNames($record);
                                } catch (\Throwable $e) {
                                    return [$record->sheet_name => $record->sheet_name];
                                }
                            })->default(fn (ClassRecordSheet $record) => $record->sheet_name)->live(),
                        Forms\Components\TextInput::make('preview_first_row')->label('First preview row')->numeric()->minValue(1)->default(1)->live(onBlur: true)
                            ->helperText('Shows up to 100 rows at a time. Scroll sideways to see more columns.'),
                        Forms\Components\Placeholder::make('workbook_preview')->hiddenLabel()
                            ->content(fn (ClassRecordSheet $record, Forms\Get $get) => view('filament.partials.workbook-preview', [
                                'preview' => app(WorkbookPreviewService::class)->preview($record, worksheet: $get('preview_sheet'), firstRow: (int) $get('preview_first_row')),
                            ])),
                    ]),
                Tables\Actions\Action::make('map')->label('Map sheet')->icon('heroicon-o-link')
                    ->modalHeading(fn (ClassRecordSheet $record) => 'Map worksheet: '.$record->sheet_name)
                    ->modalSubmitActionLabel('Save mapping')
                    ->fillForm(fn (ClassRecordSheet $record) => $record->only(['course_offering_id', 'student_id_header']))
                    ->form([
                        Forms\Components\Select::make('course_offering_id')->label('Course and section')
                            ->options(fn (ClassRecordSheet $record) => $this->courseOfferingOptions($record))
                            ->searchable()->required()->live()->disabled(fn (ClassRecordSheet $record) => $record->quizColumns()->exists())
                            ->helperText('One sheet per course and grading period. After syncing scores, the course is locked to keep existing grades with their class.'),
                        Forms\Components\Select::make('student_id_header')->label('Student ID column')
                            ->options(function (ClassRecordSheet $record) {
                                try {
                                    return app(ClassRecordSyncService::class)->headers($record);
                                } catch (\Throwable $e) {
                                    return [];
                                }
                            })->searchable()->required()->live()
                            ->helperText('Choose the header from your workbook. Cell references help you identify the right column.'),
                        Forms\Components\Placeholder::make('preview')->label('Roster check')
                            ->content(fn (ClassRecordSheet $record, Forms\Get $get) => $this->mappingPreview($record, $get('course_offering_id'), $get('student_id_header'))),
                    ])
                    ->action(fn (ClassRecordSheet $record, array $data) => $this->saveMapping($record, $data)),
                Tables\Actions\Action::make('download')->label('Download workbook')->icon('heroicon-o-arrow-down-tray')->iconButton()->tooltip('Download workbook')
                    ->action(fn (ClassRecordSheet $record) => response()->download(Storage::disk('local')->path($record->upload->file_path), $record->upload->original_filename)),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('backup')->label('Download before last sync')->icon('heroicon-o-arrow-uturn-left')
                        ->visible(fn (ClassRecordSheet $record) => Storage::disk('local')->exists($record->upload->file_path.'.before-sync'))
                        ->action(fn (ClassRecordSheet $record) => response()->download(Storage::disk('local')->path($record->upload->file_path.'.before-sync'), 'before-sync-'.$record->upload->original_filename)),
                    Tables\Actions\DeleteAction::make()->label('Remove worksheet entry')
                        ->visible(fn (ClassRecordSheet $record) => ! $record->quizColumns()->exists())
                        ->modalDescription('This removes the worksheet from this list. The uploaded workbook is kept. Sheets with synced assessment columns cannot be removed.'),
                ]),
            ])
            ->emptyStateHeading('Start with your class record workbook')
            ->emptyStateDescription('Upload your Excel file, then use Map sheet to choose the class and Student ID column. Your formulas and other worksheets are preserved.')
            ->emptyStateIcon('heroicon-o-table-cells');
    }

    protected function getTableQuery(): Builder
    {
        return ClassRecordSheet::query()->with(['upload', 'courseOffering.course', 'courseOffering.semester.schoolYear'])
            ->where(function (Builder $query) {
                $query->whereHas('courseOffering.semester.schoolYear', fn ($q) => $q->where('campus_id', filament()->getTenant()?->id))
                    ->orWhere(fn ($q) => $q->whereNull('course_offering_id')->whereHas('upload', fn ($u) => $u->where('uploaded_by', auth()->id())));
            })
            ->when(! auth()->user()?->hasRole('super_admin'), fn ($q) => $q->whereHas('upload', fn ($u) => $u->where('uploaded_by', auth()->id())));
    }

    protected function courseOfferingOptions(ClassRecordSheet $record): array
    {
        $takenIds = ClassRecordSheet::where('period', $record->period)->where('id', '!=', $record->id)->whereNotNull('course_offering_id')->pluck('course_offering_id');

        return CourseOffering::query()
            ->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', filament()->getTenant()?->id))
            ->when(! auth()->user()?->hasRole('super_admin'), fn ($q) => $q->where('user_id', auth()->id()))
            ->whereNotIn('id', $takenIds)->with(['course', 'semester.schoolYear'])->get()
            ->mapWithKeys(fn (CourseOffering $co) => [$co->id => "{$co->display_name} — {$co->semester?->name} ({$co->semester?->schoolYear?->name})"])->all();
    }

    protected function mappingPreview(ClassRecordSheet $record, $offeringId, ?string $header): string
    {
        if (! $offeringId || blank($header)) {
            return 'Choose a course and Student ID column to check the roster before saving.';
        }
        if (! array_key_exists($offeringId, $this->courseOfferingOptions($record))) {
            return 'Choose an available course in this campus.';
        }
        try {
            $result = app(ClassRecordSyncService::class)->inspect($record, CourseOffering::findOrFail($offeringId), $header);

            return "{$result['matched']} of {$result['rows']} spreadsheet students match the course roster. "
                .count($result['unrecognized']).' unrecognized IDs; '.count($result['missing']).' enrolled students missing from the sheet. '
                .($result['unrecognized'] ? 'Check IDs: '.implode(', ', array_slice($result['unrecognized'], 0, 5)).'. ' : '')
                .'Sample IDs: '.implode(', ', $result['sample']);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    protected function saveMapping(ClassRecordSheet $record, array $data): void
    {
        $offeringId = $data['course_offering_id'] ?? $record->course_offering_id;
        if (! array_key_exists($offeringId, $this->courseOfferingOptions($record))
            || ($record->quizColumns()->exists() && (int) $offeringId !== (int) $record->course_offering_id)) {
            throw ValidationException::withMessages(['mountedTableActionsData.0.course_offering_id' => 'Choose an available course. A synced sheet must stay with its original course.']);
        }
        try {
            $result = app(ClassRecordSyncService::class)->inspect($record, CourseOffering::findOrFail($offeringId), $data['student_id_header']);
            if ($result['matched'] === 0) {
                throw new \RuntimeException('No student IDs match this course. Check the selected header, course roster and workbook IDs.');
            }
            $record->update(['course_offering_id' => $offeringId, 'student_id_header' => $data['student_id_header']]);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['mountedTableActionsData.0.student_id_header' => $e instanceof QueryException ? 'This course was just mapped to another sheet. Refresh and choose another course.' : $e->getMessage()]);
        }
        Notification::make()->title('Mapping saved')->body('Open the assessment and choose Sync to Class Record to preview final scores.')->success()->send();
    }

    protected function handleUpload(array $data): void
    {
        $file = is_array($data['file']) ? reset($data['file']) : $data['file'];
        app(BackgroundTasks::class)->upload(auth()->user(), filament()->getTenant(), $file, 'workbook-upload', 'Process class record workbook',
            ['period' => $data['period'], 'original_filename' => $file->getClientOriginalName()]);
    }
}
