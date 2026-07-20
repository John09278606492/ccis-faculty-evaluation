<?php

namespace App\Filament\Imports;

use App\Models\Department;
use App\Models\User;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Illuminate\Support\Facades\Hash;

class UserImporter extends Importer
{
    protected static ?string $model = User::class;

    public static function getColumns(): array
    {
        return [
            // Common fields for all profiles
            ImportColumn::make('first_name')
                ->requiredMapping()
                ->rules(['required', 'max:100']),
            ImportColumn::make('middle_name')
                ->rules(['nullable', 'max:100']),
            ImportColumn::make('last_name')
                ->requiredMapping()
                ->rules(['required', 'max:100']),
            ImportColumn::make('email')
                ->requiredMapping()
                ->rules(['required', 'email', 'max:255']),

            // Student-specific field
            ImportColumn::make('student_id')
                ->label('Student ID')
                ->rules(['nullable', 'integer']),

            // Instructor-specific fields
            ImportColumn::make('employee_id')
                ->label('Employee ID')
                ->rules(['nullable', 'integer']),
            ImportColumn::make('position')
                ->label('Position')
                ->rules(['nullable', 'max:100']),
            ImportColumn::make('phone')
                ->rules(['nullable', 'max:20']),
        ];
    }

    /**
     * Get the form components for the import options.
     * Profile type and department must be selected before importing.
     */
    public static function getOptionsFormComponents(): array
    {
        return [
            Select::make('profile_type')
                ->label('Profile Type')
                ->options([
                    'student' => 'Students',
                    'instructor' => 'Instructors',
                ])
                ->required()
                ->live()
                ->afterStateUpdated(fn (callable $set) => $set('department_id', null))
                ->helperText('Select the type of users being imported. This determines which columns are required.'),
            Select::make('department_id')
                ->label('Department')
                ->options(function () {
                    $campusId = filament()->getTenant()?->id;

                    return Department::where('campus_id', $campusId)
                        ->where('is_active', true)
                        ->pluck('name', 'id');
                })
                ->required()
                ->searchable()
                ->visible(fn (Get $get) => filled($get('profile_type')))
                ->helperText(fn (Get $get) => match ($get('profile_type')) {
                    'student' => 'Department for these students (for reference)',
                    'instructor' => 'Department these instructors will be assigned to',
                    default => 'Select a department',
                }),
        ];
    }

    /**
     * Validate the import based on profile type.
     */
    public function beforeValidate(): void
    {
        $profileType = $this->options['profile_type'] ?? null;

        // Make the appropriate ID required based on profile type
        if ($profileType === 'student') {
            // Validate student_id is present
            if (empty($this->data['student_id'])) {
                throw new \Exception('Student ID is required for student imports.');
            }
        } elseif ($profileType === 'instructor') {
            // Validate employee_id is present
            if (empty($this->data['employee_id'])) {
                throw new \Exception('Employee ID is required for instructor imports.');
            }
        }
    }

    public function resolveRecord(): ?User
    {
        // Check if user already exists by email
        $existingUser = User::where('email', $this->data['email'])->first();

        if ($existingUser) {
            return $existingUser; // Update existing user
        }

        return new User;
    }

    /**
     * Prepare the record before it is saved.
     */
    protected function beforeSave(): void
    {
        $profileType = $this->options['profile_type'] ?? null;
        $departmentId = $this->options['department_id'] ?? null;

        // Set common fields
        if (! $this->record->password) {
            // Set a default password for new users (they should reset it)
            $this->record->password = Hash::make('password123');
        }

        // Set profile-specific fields
        if ($profileType === 'student') {
            $this->record->is_student = true;
            $this->record->is_instructor = false;
            $this->record->instructor_department_id = null;
            $this->record->employee_id = null;
            $this->record->position = null;
            // student_id is mapped from Excel
        } elseif ($profileType === 'instructor') {
            $this->record->is_instructor = true;
            $this->record->is_student = false;
            $this->record->instructor_department_id = $departmentId;
            $this->record->student_id = null;
            // employee_id, position, phone are mapped from Excel
        }
    }

    /**
     * After saving, attach user to campus.
     */
    protected function afterSave(): void
    {
        $campusId = filament()->getTenant()?->id;

        if ($campusId && ! $this->record->campuses()->where('campus_id', $campusId)->exists()) {
            $this->record->campuses()->attach($campusId);
        }
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your user import has completed and '.number_format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }
}
