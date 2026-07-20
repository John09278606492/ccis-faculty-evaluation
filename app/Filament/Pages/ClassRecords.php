<?php

namespace App\Filament\Pages;

use App\Models\ClassRecordSheet;
use App\Models\ClassRecordUpload;
use App\Models\CourseOffering;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ClassRecords extends Page implements HasTable
{
    use InteractsWithTable;
    use HasPageShield;

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static string $view = 'filament.pages.class-records';

    protected static ?string $navigationLabel = 'Class Records';

    protected static ?string $title = 'Class Records';

    protected static ?string $navigationGroup = 'Quizzes';

    protected static ?int $navigationSort = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->heading('Class Record Sheets')
            ->description('Upload your official class record Excel file, then map each detected sheet to a course offering and the column header that holds student ID numbers. Once mapped, each quiz gets a "Sync to Class Record" button.')
            ->columns([
                Tables\Columns\TextColumn::make('upload.original_filename')
                    ->label('File'),

                Tables\Columns\TextColumn::make('period')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ClassRecordUpload::PERIODS[$state] ?? $state),

                Tables\Columns\TextColumn::make('sheet_name')
                    ->label('Sheet'),

                Tables\Columns\SelectColumn::make('course_offering_id')
                    ->label('Course Offering')
                    ->options(fn (ClassRecordSheet $record) => $this->courseOfferingOptions($record))
                    ->placeholder('— not mapped —'),

                Tables\Columns\TextInputColumn::make('student_id_header')
                    ->label('Student ID Column Header')
                    ->placeholder('e.g. Student ID'),

                Tables\Columns\TextColumn::make('quiz_columns_count')
                    ->counts('quizColumns')
                    ->label('Quizzes Synced'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('upload')
                    ->label('Upload Class Record')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->form([
                        Forms\Components\Select::make('period')
                            ->label('Grading Period')
                            ->options(ClassRecordUpload::PERIODS)
                            ->required(),

                        Forms\Components\FileUpload::make('file')
                            ->label('Class Record (Excel)')
                            ->required()
                            ->disk('local')
                            ->directory('class-records')
                            ->preserveFilenames()
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                            ]),
                    ])
                    ->action(fn (array $data) => $this->handleUpload($data)),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn (ClassRecordSheet $record) => response()->download(
                        Storage::disk('local')->path($record->upload->file_path),
                        $record->upload->original_filename
                    )),

                Tables\Actions\DeleteAction::make()
                    ->label('Remove Mapping'),
            ]);
    }

    protected function getTableQuery(): Builder
    {
        $query = ClassRecordSheet::query()->with(['upload', 'courseOffering']);

        if (!auth()->user()?->hasRole('super_admin')) {
            $query->whereHas('upload', fn ($q) => $q->where('uploaded_by', auth()->id()));
        }

        return $query;
    }

    /**
     * Course offerings this instructor can map a sheet to — excludes ones
     * already mapped to a different sheet for the same grading period (the
     * unique(course_offering_id, period) constraint would otherwise reject it).
     */
    protected function courseOfferingOptions(ClassRecordSheet $record): array
    {
        $campusId = filament()->getTenant()?->id;
        $isSuperAdmin = auth()->user()?->hasRole('super_admin');

        $takenIds = ClassRecordSheet::where('period', $record->period)
            ->where('id', '!=', $record->id)
            ->whereNotNull('course_offering_id')
            ->pluck('course_offering_id');

        return CourseOffering::query()
            ->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', $campusId))
            ->when(!$isSuperAdmin, fn ($q) => $q->where('user_id', auth()->id()))
            ->whereNotIn('id', $takenIds)
            ->with('course')
            ->get()
            ->mapWithKeys(fn (CourseOffering $co) => [$co->id => $co->display_name])
            ->toArray();
    }

    protected function handleUpload(array $data): void
    {
        $upload = ClassRecordUpload::create([
            'period' => $data['period'],
            'original_filename' => basename($data['file']),
            'file_path' => $data['file'],
            'uploaded_by' => auth()->id(),
        ]);

        $fullPath = Storage::disk('local')->path($data['file']);

        try {
            $spreadsheet = IOFactory::load($fullPath);
        } catch (\Throwable $e) {
            report($e);
            Storage::disk('local')->delete($data['file']);
            $upload->delete();

            Notification::make()
                ->title('Could not read that file')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $created = 0;
        foreach ($spreadsheet->getSheetNames() as $name) {
            // Skip known non-class-list sheets (e.g. the shared grade transmutation table).
            if (stripos($name, 'transmutation') !== false) {
                continue;
            }

            ClassRecordSheet::create([
                'class_record_upload_id' => $upload->id,
                'course_offering_id' => null,
                'sheet_name' => $name,
                'period' => $data['period'],
                'student_id_header' => '',
            ]);
            $created++;
        }

        Notification::make()
            ->title("Uploaded — {$created} sheet(s) detected")
            ->body('Map each sheet to a course offering and set its Student ID column header below.')
            ->success()
            ->send();
    }
}
