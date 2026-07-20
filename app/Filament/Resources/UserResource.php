<?php

namespace App\Filament\Resources;

use App\Models\User;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
    use Filament\Tables\Enums\FiltersLayout;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Forms\Components\Select;
use App\Filament\Exports\UserExporter;
use App\Filament\Imports\UserImporter;
use Filament\Forms\Components\Section;
use Filament\Support\Enums\FontWeight;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Actions\ExportAction;
use Filament\Tables\Actions\ImportAction;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Actions\ExportBulkAction;
use App\Filament\Resources\UserResource\Pages;
use STS\FilamentImpersonate\Tables\Actions\Impersonate;
use Filament\Infolists\Components\Section as InfolistSection;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'User Management';

    protected static ?int $navigationSort = 1;

    protected static ?string $tenantOwnershipRelationshipName = 'campuses';

    /**
     * super_admin can see all users regardless of campus.
     * Non-super_admin users cannot see super_admin accounts.
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $currentUser = auth()->user();

        // super_admin bypasses tenant scoping to see all users
        if ($currentUser?->hasRole('super_admin')) {
            return parent::getEloquentQuery()->withoutGlobalScopes();
        }

        // Non-super_admin users: apply tenant scoping and hide super_admin accounts
        $query = parent::getEloquentQuery();
        $query->whereDoesntHave('roles', fn($q) => $q->where('name', 'super_admin'));

        return $query;
    }

    /**
     * Check if there are multiple super_admins in the system.
     */
    protected static function hasMultipleSuperAdmins(): bool
    {
        return User::whereHas('roles', fn($q) => $q->where('name', 'super_admin'))->count() > 1;
    }

    /**
     * Check if the given user is a super_admin.
     */
    protected static function isSuperAdmin($record): bool
    {
        return $record && $record->hasRole('super_admin');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('User Information')
                    ->schema([
                        TextInput::make('first_name')
                            ->label('First Name')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('middle_name')
                            ->label('Middle Name')
                            ->maxLength(100)
                            ->placeholder('Optional'),
                        TextInput::make('last_name')
                            ->label('Last Name')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->password()
                            ->required(fn(string $operation): bool => $operation === 'create')
                            ->dehydrated(fn(?string $state): bool => filled($state))
                            ->maxLength(255),
                        Select::make('roles')
                            ->relationship(
                                'roles',
                                'name',
                                // Filter roles for non-super_admin users:
                                // 1. Hide super_admin role
                                // 2. Only show roles assigned to their current campus
                                function ($query) {
                                    if (auth()->user()?->hasRole('super_admin')) {
                                        return $query;
                                    }

                                    $campusId = filament()->getTenant()?->id;
                                    return $query->where('name', '!=', 'super_admin')
                                        ->where('team_id', $campusId);
                                }
                            )
                            ->multiple()
                            ->preload()
                            ->searchable()
                            // Disable role editing for super_admin accounts
                            ->disabled(function ($record) {
                                return $record && $record->hasRole('super_admin');
                            }),
                    ])->columns(2),

                Section::make('Student Profile')
                    ->description('Enable if this user is a student')
                    ->schema([
                        Toggle::make('is_student')
                            ->label('This is a student account')
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    // Disable instructor when student is enabled
                                    $set('is_instructor', false);
                                    $set('instructor_department_id', null);
                                    $set('employee_id', null);
                                    $set('position', null);
                                    $set('phone', null);
                                } else {
                                    // Clear student fields when toggled off
                                    $set('student_id', null);
                                }
                            }),
                        TextInput::make('student_id')
                            ->label('Student ID')
                            ->integer()
                            ->unique(table: 'users', column: 'student_id', ignoreRecord: true)
                            ->required(fn(callable $get) => $get('is_student'))
                            ->visible(fn(callable $get) => $get('is_student')),
                    ])->columns(2)
                    ->collapsible(),

                Section::make('Instructor Profile')
                    ->description('Fill these fields if this user is an instructor')
                    ->schema([
                        Toggle::make('is_instructor')
                            ->label('This is an instructor account')
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    // Disable student when instructor is enabled
                                    $set('is_student', false);
                                    $set('student_id', null);
                                } else {
                                    // Clear instructor fields when toggled off
                                    $set('instructor_department_id', null);
                                    $set('employee_id', null);
                                    $set('position', null);
                                    $set('phone', null);
                                }
                            }),
                        Select::make('instructor_department_id')
                            ->label('Department')
                            ->options(function () {
                                $campusId = filament()->getTenant()?->id;
                                return \App\Models\Department::where('campus_id', $campusId)
                                    ->where('is_active', true)
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->preload()
                            ->required(fn(callable $get) => $get('is_instructor'))
                            ->visible(fn(callable $get) => $get('is_instructor')),
                        TextInput::make('employee_id')
                            ->label('Employee ID')
                            ->integer()
                            ->unique(table: 'users', column: 'employee_id', ignoreRecord: true)
                            ->required(fn(callable $get) => $get('is_instructor'))
                            ->visible(fn(callable $get) => $get('is_instructor')),
                        TextInput::make('position')
                            ->label('Position')
                            ->placeholder('e.g., Professor, Assistant Professor')
                            ->maxLength(100)
                            ->visible(fn(callable $get) => $get('is_instructor')),
                        TextInput::make('phone')
                            ->label('Phone')
                            ->tel()
                            ->maxLength(20)
                            ->visible(fn(callable $get) => $get('is_instructor')),
                    ])->columns(2)
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\ImageColumn::make('avatar_url')
                        ->searchable()
                        ->circular()
                        ->grow(false)
                        ->getStateUsing(fn($record) => $record->avatar_url
                            ? $record->avatar_url
                            : "https://ui-avatars.com/api/?name=" . urlencode($record->name)),
                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('name')
                            ->searchable()
                            ->weight(FontWeight::Bold),
                        Tables\Columns\TextColumn::make('student_id')
                            ->label('Student ID')
                            ->searchable()
                            ->icon('heroicon-o-identification')
                            ->color('gray')
                            ->size('sm')
                            ->visible(fn($record) => $record?->is_student),
                    ]),
                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('roles.name')
                            ->searchable()
                            ->icon('heroicon-o-shield-check')
                            ->grow(false),
                        Tables\Columns\TextColumn::make('email')
                            ->icon('heroicon-m-envelope')
                            ->searchable()
                            ->grow(false),
                    ])->alignStart()->visibleFrom('lg')->space(1),
                    Tables\Columns\IconColumn::make('is_student')
                        ->boolean()
                        ->trueIcon('heroicon-o-academic-cap')
                        ->falseIcon('heroicon-o-minus')
                        ->trueColor('success')
                        ->falseColor('gray')
                        ->label('Student')
                        ->grow(false),
                    Tables\Columns\IconColumn::make('is_instructor')
                        ->boolean()
                        ->trueIcon('heroicon-o-briefcase')
                        ->falseIcon('heroicon-o-minus')
                        ->trueColor('warning')
                        ->falseColor('gray')
                        ->label('Instructor')
                        ->grow(false),
                    // Campus column - visible to all users
                    Tables\Columns\TextColumn::make('campuses.name')
                        ->label('Campuses')
                        ->badge()
                        ->color('info')
                        ->searchable(),
                ]),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload(),
                TernaryFilter::make('is_student')
                    ->label('Student Account')
                    ->trueLabel('Students Only')
                    ->falseLabel('Non-Students Only'),
                TernaryFilter::make('is_instructor')
                    ->label('Instructor Account')
                    ->trueLabel('Instructors Only')
                    ->falseLabel('Non-Instructors Only'),
            ], layout: FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Action::make('Set Role')
                    ->icon('heroicon-m-adjustments-vertical')
                    ->form([
                        Select::make('role')
                            ->relationship(
                                'roles',
                                'name',
                                // Filter roles for non-super_admin users:
                                // 1. Hide super_admin role
                                // 2. Only show roles assigned to their current campus
                                function ($query) {
                                    if (auth()->user()?->hasRole('super_admin')) {
                                        return $query;
                                    }

                                    $campusId = filament()->getTenant()?->id;
                                    return $query->where('name', '!=', 'super_admin')
                                        ->where('team_id', $campusId);
                                }
                            )
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->preload()
                            ->optionsLimit(10)
                            ->getOptionLabelFromRecordUsing(fn($record) => $record->name),
                    ])
                    // Custom actions don't inherit Policy checks automatically like
                    // EditAction/DeleteAction do, so update_user is checked explicitly.
                    ->visible(fn($record) => !static::isSuperAdmin($record) && (auth()->user()?->can('update_user') ?? false)),
                // Impersonate::make(),
                Tables\Actions\DeleteAction::make()
                    // Prevent deletion of super_admin accounts
                    ->hidden(fn($record) => static::isSuperAdmin($record)),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(UserExporter::class),
                ImportAction::make()
                    ->importer(UserImporter::class)
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        // Prevent bulk deletion of super_admin accounts
                        ->deselectRecordsAfterCompletion()
                        ->before(function ($records) {
                            // Filter out super_admin users from deletion
                            return $records->filter(fn($record) => !$record->hasRole('super_admin'));
                        }),
                ]),
                ExportBulkAction::make()
                    ->exporter(UserExporter::class)
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfolistSection::make('User Information')->schema([
                    TextEntry::make('name'),
                    TextEntry::make('email'),
                    TextEntry::make('roles.name')
                        ->label('Roles')
                        ->badge(),
                ])->columns(2),
                InfolistSection::make('Student Profile')
                    ->schema([
                        TextEntry::make('student_id')
                            ->label('Student ID'),
                        TextEntry::make('full_name')
                            ->label('Full Name'),
                    ])
                    ->columns(3)
                    ->visible(fn($record) => $record->is_student),
            ]);
    }
}
