<?php

namespace App\Filament\Resources;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Filament\Resources\UserResource\Pages;
use App\Models\Department;
use App\Models\User;
use App\Services\BackgroundTasks;
use App\Services\StudentPins;
use App\Services\UserAccessAdministration;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use STS\FilamentImpersonate\Tables\Actions\Impersonate;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Staff Accounts';

    protected static ?int $navigationSort = 1;

    protected static ?string $tenantOwnershipRelationshipName = 'campuses';

    /**
     * super_admin can see all users regardless of campus.
     * Non-super_admin users cannot see super_admin accounts.
     */
    public static function getEloquentQuery(): Builder
    {
        $currentUser = auth()->user();

        // super_admin bypasses tenant scoping to see all users
        if ($currentUser?->hasRole('super_admin')) {
            return parent::getEloquentQuery()->withoutGlobalScopes()->whereNull('users.deleted_at')->where('is_student', false);
        }

        // Non-super_admin users: apply tenant scoping and hide super_admin accounts
        $query = parent::getEloquentQuery()->where('is_student', false);
        $query->whereDoesntHave('roles', fn ($q) => $q->where('name', 'super_admin'));

        return $query;
    }

    /**
     * Check if there are multiple super_admins in the system.
     */
    protected static function hasMultipleSuperAdmins(): bool
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->count() > 1;
    }

    /**
     * Check if the given user is a super_admin.
     */
    protected static function isSuperAdmin($record): bool
    {
        return $record && $record->hasRole('super_admin');
    }

    public static function canEdit(Model $record): bool
    {
        $actor = auth()->user();
        $campus = filament()->getTenant();

        return $actor && $campus && $actor->can('update_user')
            && app(UserAccessAdministration::class)->canManageAccount($actor, $record, $campus);
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
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->maxLength(255),
                        Select::make('roles')->relationship('roles', 'name', fn ($query) => $query
                            ->whereIn('roles.id', app(UserAccessAdministration::class)->grantableRoles(auth()->user(), filament()->getTenant())->select('roles.id')))
                            ->multiple()->preload()->searchable()
                            ->afterStateHydrated(function (Select $component, $state) {
                                if (! auth()->user()->isSuperAdmin()) {
                                    $allowed = app(UserAccessAdministration::class)->grantableRoles(auth()->user(), filament()->getTenant())->pluck('id')->map(fn ($id) => (string) $id)->all();
                                    $component->state(array_values(array_intersect(array_map('strval', (array) $state), $allowed)));
                                }
                            })
                            ->visible(fn ($record) => auth()->user()->isSuperAdmin() || (auth()->user()->can('assign_user_roles') && $record?->id !== auth()->id()))
                            ->saveRelationshipsUsing(function ($record, $state) {
                                app(UserAccessAdministration::class)->assignRoles(auth()->user(), $record, filament()->getTenant(), (array) $state);
                            }),
                    ])->columns(2),

                Section::make('Student Profile')->hidden()->dehydrated(false)
                    ->description('Enable if this user is a student')
                    ->schema([
                        Toggle::make('is_student')
                            ->label('This is a student account')
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    // Disable instructor when student is enabled
                                    $set('is_instructor', false);
                                    $set('roles', []);
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
                            ->maxLength(50)
                            ->unique(table: 'users', column: 'student_id', ignoreRecord: true)
                            ->required(fn (callable $get) => $get('is_student'))
                            ->visible(fn (callable $get) => $get('is_student')),
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
                                    $set('roles', []);
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

                                return Department::where('campus_id', $campusId)
                                    ->where('is_active', true)
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->preload()
                            ->required(fn (callable $get) => $get('is_instructor'))
                            ->visible(fn (callable $get) => $get('is_instructor')),
                        TextInput::make('employee_id')
                            ->label('Employee ID')
                            ->maxLength(50)
                            ->unique(table: 'users', column: 'employee_id', ignoreRecord: true)
                            ->required(fn (callable $get) => $get('is_instructor'))
                            ->visible(fn (callable $get) => $get('is_instructor')),
                        TextInput::make('position')
                            ->label('Position')
                            ->placeholder('e.g., Professor, Assistant Professor')
                            ->maxLength(100)
                            ->visible(fn (callable $get) => $get('is_instructor')),
                        TextInput::make('phone')
                            ->label('Phone')
                            ->tel()
                            ->maxLength(20)
                            ->visible(fn (callable $get) => $get('is_instructor')),
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
                        ->getStateUsing(fn ($record) => $record->avatar_url
                            ? $record->avatar_url
                            : app(InitialsAvatarProvider::class)->get($record)),
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
                            ->visible(fn ($record) => $record?->is_student),
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
                Action::make('issueTemporaryPassword')->label('Issue Temporary Password')->icon('heroicon-o-key')
                    ->visible(fn (User $record) => ! $record->isStudent() && ! $record->isSuperAdmin() && static::canEdit($record))
                    ->requiresConfirmation()->modalDescription('Issue a unique temporary password. Give it privately to this staff member. They must change it at first sign-in.')
                    ->action(function (User $record) {
                        abort_unless(static::canEdit($record), 403);
                        $password = Str::password(18);
                        $record->update(['password' => $password, 'must_change_password' => true]);
                        Notification::make()->title('Temporary password: '.$password)->body('For '.$record->name.'. Copy it now; it will not be displayed again.')->success()->persistent()->send();
                    }),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Action::make('newPin')
                    ->label('New PIN')
                    ->icon('heroicon-o-key')
                    ->visible(fn (User $record) => $record->is_student && auth()->user()?->can('update', $record))
                    ->requiresConfirmation()
                    ->modalDescription(fn (User $record) => "Give {$record->full_name} a new sign-in PIN? Their old PIN stops working.")
                    ->action(function (User $record) {
                        $pin = StudentPins::issue(collect([$record]))->first()['pin'];

                        Notification::make()
                            ->title("New PIN for {$record->full_name}: {$pin}")
                            ->body('Write it down now. It will not be shown again.')
                            ->success()
                            ->persistent()
                            ->send();
                    }),
                // Impersonate::make(),
                Tables\Actions\DeleteAction::make()
                    // Prevent deletion of super_admin accounts
                    ->hidden(fn ($record) => static::isSuperAdmin($record)),
            ])
            ->headerActions([
                Action::make('export')->label('Export CSV')->icon('heroicon-o-arrow-down-tray')
                    ->action(fn ($livewire) => app(BackgroundTasks::class)->enqueue(auth()->user(), filament()->getTenant(), 'staff-export', 'Export filtered staff accounts',
                        ['ids' => $livewire->getFilteredTableQuery()->pluck('users.id')->all()])),

            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        // Prevent bulk deletion of super_admin accounts
                        ->deselectRecordsAfterCompletion()
                        ->before(function ($records) {
                            // Filter out super_admin users from deletion
                            return $records->filter(fn ($record) => ! $record->hasRole('super_admin'));
                        }),
                ]),
                Tables\Actions\BulkAction::make('export')->label('Export Selected CSV')->icon('heroicon-o-arrow-down-tray')->fetchSelectedRecords(false)->deselectRecordsAfterCompletion()
                    ->action(fn ($records) => app(BackgroundTasks::class)->enqueue(auth()->user(), filament()->getTenant(), 'staff-export', 'Export selected staff accounts', ['ids' => $records->all()])),
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
                    ->visible(fn ($record) => $record->is_student),
            ]);
    }
}
