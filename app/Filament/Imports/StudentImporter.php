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

class StudentImporter extends Importer
{
    use QueuesBulkFiles;

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
                ->rules(['required', 'max:50'])
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
                ->visible(fn () => auth()->user()?->isSuperAdmin() || auth()->user()?->can('assign_user_roles'))
                ->label('Assign Roles')
                ->multiple()
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
        return app(ScopedAccountImport::class)->resolve($this->import->user, $this->options, 'student', $this->data);
    }

    protected function beforeSave(): void
    {
        app(ScopedAccountImport::class)->prepare($this->import->user, $this->record, $this->options, 'student');
    }

    protected function afterSave(): void
    {
        app(ScopedAccountImport::class)->finish($this->import->user, $this->record, $this->options, 'student');
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
