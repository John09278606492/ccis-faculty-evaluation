<?php

namespace App\Filament\Resources\CourseOfferingResource\RelationManagers;

use App\Models\User;
use App\Services\StudentPins;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class StudentsRelationManager extends RelationManager
{
    protected static string $relationship = 'students';

    protected static ?string $title = 'Evaluators';

    // Use actual database column for record title
    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Accepted header names for the ID-number column, same convention as
     * StudentImporter so instructors can reuse the same class-list file here.
     */
    protected static array $validStudentIdColumns = [
        'student_id',
        'studentid',
        'student id',
        'stud_id',
        'studid',
        'id_number',
        'idnumber',
    ];

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->label('Evaluator')
                    ->options(function () {
                        $campusId = filament()->getTenant()?->id;

                        return User::whereHas('campuses', fn ($q) => $q->where('campus_id', $campusId))
                            ->where('is_student', true)
                            ->get()
                            ->mapWithKeys(fn ($u) => [$u->id => "{$u->student_id} - {$u->full_name}"]);
                    })
                    ->required()
                    ->searchable(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('student_id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->sortable()
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('year_level')
                    ->badge(),

                Tables\Columns\IconColumn::make('pin_issued_at')
                    ->label('PIN')
                    ->boolean()
                    ->getStateUsing(fn (User $record) => filled($record->pin_issued_at))
                    ->tooltip(fn (User $record) => $record->pin_issued_at ? 'PIN issued '.$record->pin_issued_at->diffForHumans() : 'No PIN yet: this student cannot sign in'),

                Tables\Columns\TextColumn::make('pivot.enrolled_at')
                    ->label('Enrolled At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ], layout: FiltersLayout::AboveContent)
            ->headerActions([
                // Single student attach
                Tables\Actions\AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(function () {
                        $campusId = filament()->getTenant()?->id;

                        return User::whereHas('campuses', fn ($q) => $q->where('campus_id', $campusId))
                            ->where('is_student', true);
                    })
                    ->recordTitle(fn (User $record) => "{$record->student_id} - {$record->full_name}")
                    ->recordSelectSearchColumns(['student_id', 'name', 'email', 'first_name', 'last_name']),

                // Bulk attach students
                Tables\Actions\Action::make('bulkAttach')
                    ->label('Bulk Add Evaluators')
                    ->icon('heroicon-o-users')
                    ->color('success')
                    ->form([
                        Forms\Components\Select::make('student_ids')
                            ->label('Select Evaluators')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(function () {
                                $campusId = filament()->getTenant()?->id;
                                $courseOfferingId = $this->ownerRecord->id;

                                // Get students in campus that are NOT already enrolled in this course offering
                                return User::whereHas('campuses', fn ($q) => $q->where('campus_id', $campusId))
                                    ->where('is_student', true)
                                    ->whereDoesntHave('enrolledCourseOfferings', fn ($q) => $q->where('course_offering_id', $courseOfferingId))
                                    ->get()
                                    ->mapWithKeys(fn ($u) => [$u->id => "{$u->student_id} - {$u->name}"]);
                            })
                            ->required()
                            ->helperText('Select people to assign as evaluators'),
                    ])
                    ->action(function (array $data) {
                        $studentIds = $data['student_ids'];
                        $courseOffering = $this->ownerRecord;

                        DB::transaction(function () use ($studentIds, $courseOffering) {
                            // Prepare data with enrolled_at timestamp
                            $attachData = [];
                            foreach ($studentIds as $studentId) {
                                $attachData[$studentId] = ['enrolled_at' => Carbon::now()];
                            }

                            // Attach without detaching existing students
                            $courseOffering->students()->syncWithoutDetaching($attachData);
                        });

                        $count = count($studentIds);
                        Notification::make()
                            ->title("Successfully added {$count} ".($count === 1 ? 'evaluator' : 'evaluators'))
                            ->success()
                            ->send();
                    }),

                // Bulk attach by uploading a CSV/Excel of ID numbers — any ID that
                // doesn't match an existing student is skipped, not an error.
                Tables\Actions\Action::make('bulkAttachCsv')
                    ->label('Bulk Add via ID List')
                    ->icon('heroicon-o-document-arrow-up')
                    ->color('gray')
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label('ID Number File (CSV or Excel)')
                            ->required()
                            ->disk('local')
                            ->directory('temp-imports')
                            ->acceptedFileTypes([
                                'text/csv',
                                'text/plain',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ])
                            ->helperText('Needs a column named "student_id" (or "id_number"). Any ID that doesn\'t match an existing student is skipped — the rest are still attached.'),
                    ])
                    ->action(fn (array $data) => $this->attachFromIdFile($data['file'])),

                // Students sign in with ID number + PIN. PINs are hashed, so the class list
                // with PINs can only be downloaded at the moment they are issued.
                Tables\Actions\Action::make('signInPins')
                    ->label('Sign-in PINs')
                    ->icon('heroicon-o-key')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->can('update', $this->ownerRecord))
                    ->modalHeading('Issue sign-in PINs')
                    ->modalDescription('Students sign in with their ID number and this PIN. You get a CSV of the new PINs to print or share; PINs cannot be shown again later.')
                    ->modalSubmitActionLabel('Issue and download')
                    ->form([
                        Forms\Components\Radio::make('scope')
                            ->label('Issue PINs for')
                            ->options([
                                'missing' => 'Students who have not been given a PIN yet',
                                'all' => 'Every student in this class (replaces existing PINs)',
                            ])
                            ->default('missing')
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $students = $this->ownerRecord->students()
                            ->where('is_student', true)
                            ->when($data['scope'] === 'missing', fn ($q) => $q->whereNull('pin_issued_at'))
                            ->get();

                        if ($students->isEmpty()) {
                            Notification::make()->title('Every student in this class already has a PIN')->info()->send();

                            return null;
                        }

                        $issued = StudentPins::issue($students);
                        $offering = $this->ownerRecord->loadMissing('course');

                        return StudentPins::download($issued, str('pins-'.($offering->course?->code ?? 'class').'-'.$offering->section)->slug().'.csv');
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('resetPin')
                    ->label('New PIN')
                    ->icon('heroicon-o-key')
                    ->color('gray')
                    ->visible(fn (User $record) => $record->is_student && auth()->user()?->can('update', $this->ownerRecord))
                    ->requiresConfirmation()
                    ->modalDescription(fn (User $record) => "Give {$record->full_name} a new PIN? Their old PIN stops working.")
                    ->action(function (User $record) {
                        $pin = StudentPins::issue(collect([$record]))->first()['pin'];

                        Notification::make()
                            ->title("New PIN for {$record->full_name}: {$pin}")
                            ->body('Write it down now. It will not be shown again.')
                            ->success()
                            ->persistent()
                            ->send();
                    }),
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Reads the uploaded file, matches each ID number against an existing
     * student (by student_id) in the current campus, attaches the matches to
     * this course offering, and silently skips any ID that doesn't match —
     * processed synchronously (no queue worker) so it works the same whether
     * a queue is running or not.
     */
    protected function attachFromIdFile(string $storedPath): void
    {
        $fullPath = Storage::disk('local')->path($storedPath);

        try {
            $rows = IOFactory::load($fullPath)->getActiveSheet()->toArray(null, true, true, false);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($storedPath);

            Notification::make()
                ->title('Could not read the file')
                ->body('Make sure it\'s a valid CSV or Excel file.')
                ->danger()
                ->send();

            return;
        }

        Storage::disk('local')->delete($storedPath);

        if (empty($rows)) {
            Notification::make()->title('The file is empty')->warning()->send();

            return;
        }

        $header = array_map(fn ($cell) => strtolower(trim((string) $cell)), array_shift($rows));
        $columnIndex = null;

        foreach ($header as $index => $name) {
            if (in_array($name, static::$validStudentIdColumns, true)) {
                $columnIndex = $index;
                break;
            }
        }

        if ($columnIndex === null) {
            Notification::make()
                ->title('No ID number column found')
                ->body('The file must have a column named "student_id" or "id_number".')
                ->danger()
                ->send();

            return;
        }

        $idValues = collect($rows)
            ->map(fn ($row) => trim((string) ($row[$columnIndex] ?? '')))
            ->filter()
            ->unique()
            ->values();

        if ($idValues->isEmpty()) {
            Notification::make()->title('No ID numbers found in the file')->warning()->send();

            return;
        }

        $campusId = filament()->getTenant()?->id;

        $matched = User::whereHas('campuses', fn ($q) => $q->where('campus_id', $campusId))
            ->where('is_student', true)
            ->whereIn('student_id', $idValues)
            ->get();

        $matchedIds = $matched->pluck('student_id')->map(fn ($v) => (string) $v)->all();
        $notFound = $idValues->diff($matchedIds)->values();

        if ($matched->isEmpty()) {
            Notification::make()
                ->title('No matching students found')
                ->body("{$notFound->count()} ID(s) in the file did not match any student.")
                ->warning()
                ->send();

            return;
        }

        $courseOffering = $this->ownerRecord;

        DB::transaction(function () use ($matched, $courseOffering) {
            $attachData = [];
            foreach ($matched as $student) {
                $attachData[$student->id] = ['enrolled_at' => Carbon::now()];
            }

            $courseOffering->students()->syncWithoutDetaching($attachData);
        });

        $attachedCount = $matched->count();
        $notFoundCount = $notFound->count();

        $body = null;
        if ($notFoundCount > 0) {
            $preview = $notFound->take(10)->implode(', ');
            $body = "{$notFoundCount} ID(s) didn't match any student and were skipped: {$preview}"
                .($notFoundCount > 10 ? ', …' : '');
        }

        Notification::make()
            ->title("Added {$attachedCount} ".($attachedCount === 1 ? 'evaluator' : 'evaluators').' from the file')
            ->body($body)
            ->success()
            ->send();
    }
}
