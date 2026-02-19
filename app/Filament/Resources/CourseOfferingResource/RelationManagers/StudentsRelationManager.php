<?php

namespace App\Filament\Resources\CourseOfferingResource\RelationManagers;

use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StudentsRelationManager extends RelationManager
{
    protected static string $relationship = 'students';

    protected static ?string $title = 'Enrolled Students';

    // Use actual database column for record title
    protected static ?string $recordTitleAttribute = 'name';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->label('Student')
                    ->options(function () {
                        $campusId = filament()->getTenant()?->id;
                        return User::whereHas('campuses', fn($q) => $q->where('campus_id', $campusId))
                            ->where('is_student', true)
                            ->get()
                            ->mapWithKeys(fn($u) => [$u->id => "{$u->student_id} - {$u->full_name}"]);
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
                    ->label('Student ID')
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

                Tables\Columns\TextColumn::make('pivot.enrolled_at')
                    ->label('Enrolled At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // Single student attach
                Tables\Actions\AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(function () {
                        $campusId = filament()->getTenant()?->id;
                        return User::whereHas('campuses', fn($q) => $q->where('campus_id', $campusId))
                            ->where('is_student', true);
                    })
                    ->recordTitle(fn(User $record) => "{$record->student_id} - {$record->full_name}")
                    ->recordSelectSearchColumns(['student_id', 'name', 'email', 'first_name', 'last_name']),

                // Bulk attach students
                Tables\Actions\Action::make('bulkAttach')
                    ->label('Bulk Attach Students')
                    ->icon('heroicon-o-users')
                    ->color('success')
                    ->form([
                        Forms\Components\Select::make('student_ids')
                            ->label('Select Students')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(function () {
                                $campusId = filament()->getTenant()?->id;
                                $courseOfferingId = $this->ownerRecord->id;

                                // Get students in campus that are NOT already enrolled in this course offering
                                return User::whereHas('campuses', fn($q) => $q->where('campus_id', $campusId))
                                    ->where('is_student', true)
                                    ->whereDoesntHave('enrolledCourseOfferings', fn($q) => $q->where('course_offering_id', $courseOfferingId))
                                    ->get()
                                    ->mapWithKeys(fn($u) => [$u->id => "{$u->student_id} - {$u->name}"]);
                            })
                            ->required()
                            ->helperText('Select multiple students to enroll in this course offering'),
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
                            ->title("Successfully enrolled {$count} " . ($count === 1 ? 'student' : 'students'))
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make(),
                ]),
            ]);
    }
}
