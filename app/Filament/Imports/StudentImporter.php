<?php

namespace App\Filament\Imports;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class StudentImporter extends Importer
{
    protected static ?string $model = User::class;

    /**
     * Valid column names for student_id (lowercase)
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

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('first_name')
                ->requiredMapping()
                ->rules(['required', 'max:100'])
                ->guess(['first_name', 'firstname', 'first name', 'fname'])
                ->example('Juan'),
            ImportColumn::make('middle_name')
                ->rules(['nullable', 'max:100'])
                ->guess(['middle_name', 'middlename', 'middle name', 'mname'])
                ->example('Santos'),
            ImportColumn::make('last_name')
                ->requiredMapping()
                ->rules(['required', 'max:100'])
                ->guess(['last_name', 'lastname', 'last name', 'lname', 'surname'])
                ->example('Dela Cruz'),
            ImportColumn::make('email')
                ->requiredMapping()
                ->rules(['required', 'email', 'max:255'])
                ->guess(['email', 'email_address', 'e-mail'])
                ->example('juan.delacruz@email.com'),
            ImportColumn::make('student_id')
                ->label('Student ID (Must map to "student_id" column)')
                ->requiredMapping()
                ->rules(['required', 'integer'])
                ->guess(['student_id', 'studentid', 'student id', 'stud_id', 'studid', 'id_number', 'idnumber'])
                ->example('2024001234'),
        ];
    }

    public static function getOptionsFormComponents(): array
    {
        return [
            Placeholder::make('file_notice')
                ->label('📋 Required Excel Columns')
                ->content(fn () => new HtmlString(
                    '<div class="text-sm space-y-1">
                        <p class="font-bold text-danger-600">⚠️ Your Excel file MUST have these EXACT column headers:</p>
                        <ul class="list-disc list-inside text-gray-600">
                            <li><strong>first_name</strong></li>
                            <li>middle_name (optional)</li>
                            <li><strong>last_name</strong></li>
                            <li><strong>email</strong></li>
                            <li><strong>student_id</strong> ← REQUIRED FOR STUDENT IMPORT</li>
                        </ul>
                        <p class="text-danger-600 font-semibold mt-2">❌ If your file has "employee_id" instead of "student_id", you MUST use INSTRUCTOR import!</p>
                    </div>'
                ))
                ->columnSpanFull(),
            Select::make('department_id')
                ->label('Department')
                ->options(function () {
                    $campusId = filament()->getTenant()?->id;

                    return Department::where('campus_id', $campusId)
                        ->where('is_active', true)
                        ->pluck('name', 'id');
                })
                ->required()
                ->searchable(),
            Select::make('role_ids')
                ->label('Assign Roles')
                ->multiple()
                ->required()
                ->options(function () {
                    return static::getAvailableRoles();
                })
                ->helperText('Select roles to assign to all imported students'),
        ];
    }

    /**
     * Get available roles based on current user permissions.
     * Super admin can see all roles, others only see campus-specific roles.
     */
    protected static function getAvailableRoles(): array
    {
        $user = auth()->user();
        $campusId = filament()->getTenant()?->id;

        // Super admin can assign all roles except super_admin itself
        if ($user && $user->hasRole('super_admin')) {
            return Role::where('name', '!=', 'super_admin')
                ->pluck('name', 'id')
                ->toArray();
        }

        // Non-super admin can only assign campus-specific roles
        return Role::where('team_id', $campusId)
            ->where('name', '!=', 'super_admin')
            ->pluck('name', 'id')
            ->toArray();
    }

    /**
     * Override validation rules to add column name check
     */
    public function getValidationRules(): array
    {
        $rules = [];

        foreach ($this->getCachedColumns() as $column) {
            $columnName = $column->getName();

            if (blank($this->columnMap[$columnName] ?? null)) {
                continue;
            }

            $rules[$columnName] = $column->getDataValidationRules();
        }

        // Add custom validation for column mapping
        $mappedColumn = $this->columnMap['student_id'] ?? null;
        if ($mappedColumn) {
            $mappedColumnLower = strtolower(trim($mappedColumn));

            if (! in_array($mappedColumnLower, static::$validStudentIdColumns)) {
                // Add a rule that will always fail with our custom message
                $rules['_column_check'] = [
                    function ($attribute, $value, $fail) use ($mappedColumn) {
                        $fail("WRONG FILE: Column '{$mappedColumn}' is not a valid Student ID column. Use INSTRUCTOR import for 'employee_id' files.");
                    },
                ];
            }
        }

        return $rules;
    }

    public function resolveRecord(): ?User
    {
        $email = $this->data['email'] ?? null;
        $studentId = $this->data['student_id'] ?? null;

        // Check if student_id already exists for a DIFFERENT user
        $existingStudentId = User::where('student_id', $studentId)
            ->whereNotNull('student_id')
            ->first();

        if ($existingStudentId) {
            if ($existingStudentId->email === $email) {
                return $existingStudentId;
            }
            // Throw validation exception for duplicate
            throw ValidationException::withMessages([
                'student_id' => "Student ID {$studentId} is already assigned to {$existingStudentId->email}.",
            ]);
        }

        // Check if email already exists
        $existingEmail = User::where('email', $email)->first();

        if ($existingEmail) {
            if ($existingEmail->is_instructor) {
                throw ValidationException::withMessages([
                    'email' => "Email {$email} is already registered as an instructor.",
                ]);
            }

            return $existingEmail;
        }

        return new User;
    }

    protected function beforeSave(): void
    {
        if (! $this->record->password) {
            $this->record->password = Hash::make('password123');
        }

        $this->record->is_student = true;
        $this->record->is_instructor = false;
        $this->record->instructor_department_id = null;
        $this->record->employee_id = null;
        $this->record->position = null;
    }

    protected function afterSave(): void
    {
        // Use campus_id from options (passed from ListUsers to work in queue jobs)
        $campusId = $this->options['campus_id'] ?? null;
        $roleIds = $this->options['role_ids'] ?? [];

        DB::transaction(function () use ($campusId, $roleIds) {
            // Attach to campus
            if ($campusId && ! $this->record->campuses()->where('campus_id', $campusId)->exists()) {
                $this->record->campuses()->attach($campusId);
            }

            // Assign roles if selected
            if (! empty($roleIds)) {
                $roles = Role::whereIn('id', $roleIds)->pluck('name')->toArray();
                $this->record->syncRoles($roles);
            }
        });
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Student import completed: '.number_format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed.';
        }

        return $body;
    }
}
