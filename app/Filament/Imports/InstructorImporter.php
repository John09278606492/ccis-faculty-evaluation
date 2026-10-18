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

class InstructorImporter extends Importer
{
    protected static ?string $model = User::class;

    /**
     * Valid column names for employee_id (lowercase)
     */
    protected static array $validEmployeeIdColumns = [
        'employee_id',
        'employeeid',
        'employee id',
        'emp_id',
        'empid',
        'staff_id',
        'staffid',
    ];

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('first_name')
                ->requiredMapping()
                ->rules(['required', 'max:100'])
                ->guess(['first_name', 'firstname', 'first name', 'fname'])
                ->example('Maria'),
            ImportColumn::make('middle_name')
                ->rules(['nullable', 'max:100'])
                ->guess(['middle_name', 'middlename', 'middle name', 'mname'])
                ->example('Santos'),
            ImportColumn::make('last_name')
                ->requiredMapping()
                ->rules(['required', 'max:100'])
                ->guess(['last_name', 'lastname', 'last name', 'lname', 'surname'])
                ->example('Garcia'),
            ImportColumn::make('email')
                ->requiredMapping()
                ->rules(['required', 'email', 'max:255'])
                ->guess(['email', 'email_address', 'e-mail'])
                ->example('maria.garcia@school.edu'),
            ImportColumn::make('employee_id')
                ->label('Employee ID (Must map to "employee_id" column)')
                ->requiredMapping()
                ->rules(['required', 'integer'])
                ->guess(['employee_id', 'employeeid', 'employee id', 'emp_id', 'empid', 'staff_id', 'staffid'])
                ->example('1001'),
            ImportColumn::make('position')
                ->label('Position')
                ->rules(['nullable', 'max:100'])
                ->guess(['position', 'job_title', 'title', 'designation', 'rank'])
                ->example('Professor'),
            ImportColumn::make('phone')
                ->rules(['nullable', 'max:20'])
                ->guess(['phone', 'phone_number', 'contact', 'mobile', 'telephone'])
                ->example('09171234567'),
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
                            <li><strong>employee_id</strong> ← REQUIRED FOR INSTRUCTOR IMPORT</li>
                            <li>position (optional)</li>
                            <li>phone (optional)</li>
                        </ul>
                        <p class="text-danger-600 font-semibold mt-2">❌ If your file has "student_id" instead of "employee_id", you MUST use STUDENT import!</p>
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
                ->searchable()
                ->helperText('All imported instructors will be assigned to this department'),
            Select::make('role_ids')
                ->label('Assign Roles')
                ->multiple()
                ->required()
                ->options(function () {
                    return static::getAvailableRoles();
                })
                ->helperText('Select roles to assign to all imported instructors'),
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
        $mappedColumn = $this->columnMap['employee_id'] ?? null;
        if ($mappedColumn) {
            $mappedColumnLower = strtolower(trim($mappedColumn));

            if (! in_array($mappedColumnLower, static::$validEmployeeIdColumns)) {
                // Add a rule that will always fail with our custom message
                $rules['_column_check'] = [
                    function ($attribute, $value, $fail) use ($mappedColumn) {
                        $fail("WRONG FILE: Column '{$mappedColumn}' is not a valid Employee ID column. Use STUDENT import for 'student_id' files.");
                    },
                ];
            }
        }

        return $rules;
    }

    public function resolveRecord(): ?User
    {
        $email = $this->data['email'] ?? null;
        $employeeId = $this->data['employee_id'] ?? null;

        // Check if employee_id already exists for a DIFFERENT user
        $existingEmployeeId = User::where('employee_id', $employeeId)
            ->whereNotNull('employee_id')
            ->first();

        if ($existingEmployeeId) {
            if ($existingEmployeeId->email === $email) {
                return $existingEmployeeId;
            }
            // Throw validation exception for duplicate
            throw ValidationException::withMessages([
                'employee_id' => "Employee ID {$employeeId} is already assigned to {$existingEmployeeId->email}.",
            ]);
        }

        // Check if email already exists
        $existingEmail = User::where('email', $email)->first();

        if ($existingEmail) {
            if ($existingEmail->is_student) {
                throw ValidationException::withMessages([
                    'email' => "Email {$email} is already registered as a student.",
                ]);
            }

            return $existingEmail;
        }

        return new User;
    }

    protected function beforeSave(): void
    {
        $departmentId = $this->options['department_id'] ?? null;

        if (! $this->record->password) {
            // Shared starting password; the instructor must choose their own at first sign-in.
            $this->record->password = Hash::make('password123');
            $this->record->must_change_password = true;
        }

        $this->record->is_instructor = true;
        $this->record->is_student = false;
        $this->record->instructor_department_id = $departmentId;
        $this->record->student_id = null;
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
        $body = 'Instructor import completed: '.number_format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed.';
        }

        return $body;
    }
}
