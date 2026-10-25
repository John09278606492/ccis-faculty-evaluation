<?php

namespace App\Filament\Imports;

use App\Models\Department;
use App\Models\User;
use App\Services\ScopedAccountImport;
use App\Services\UserAccessAdministration;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Illuminate\Support\HtmlString;

class InstructorImporter extends Importer
{
    use QueuesBulkFiles;

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
                ->rules(['required', 'max:50'])
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
                ->visible(fn () => auth()->user()?->isSuperAdmin() || auth()->user()?->can('assign_user_roles'))
                ->label('Assign Roles')
                ->multiple()
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
        $actor = auth()->user();
        $campus = filament()->getTenant();

        return $actor && $campus ? app(UserAccessAdministration::class)->grantableRoles($actor, $campus)->pluck('name', 'id')->all() : [];
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
        return app(ScopedAccountImport::class)->resolve($this->import->user, $this->options, 'instructor', $this->data);
    }

    protected function beforeSave(): void
    {
        app(ScopedAccountImport::class)->prepare($this->import->user, $this->record, $this->options, 'instructor');
    }

    protected function afterSave(): void
    {
        app(ScopedAccountImport::class)->finish($this->import->user, $this->record, $this->options, 'instructor');
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
