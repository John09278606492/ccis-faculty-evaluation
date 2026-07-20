<?php

namespace App\Filament\Resources;

use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use BezhanSalleh\FilamentShield\Forms\ShieldSelectAllToggle;
use App\Filament\Resources\RoleResource\Pages;
use BezhanSalleh\FilamentShield\Support\Utils;
use BezhanSalleh\FilamentShield\Traits\HasShieldFormComponents;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class RoleResource extends Resource implements HasShieldPermissions
{
    use HasShieldFormComponents;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $tenantOwnershipRelationshipName = null;

    public static function getPermissionPrefixes(): array
    {
        return [
            'view',
            'view_any',
            'create',
            'update',
            'delete',
            'delete_any',
        ];
    }

    /**
     * Hide super_admin role and global roles from non-super_admin users.
     * Filter roles by current campus for non-super_admin users.
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();

        $currentUser = auth()->user();
        if ($currentUser && !$currentUser->hasRole('super_admin')) {
            // Non-super_admin users can only see:
            // 1. Roles that belong to their current campus (not global roles)
            // 2. Exclude super_admin role
            $campusId = filament()->getTenant()?->id;
            $query->where('name', '!=', 'super_admin')
                ->where('team_id', $campusId);
        }

        return $query;
    }

    /**
     * update_role is required to edit any role; the super_admin role and
     * global (cross-campus) roles are additionally locked to super_admin
     * itself, regardless of who holds update_role, to stop privilege escalation.
     */
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        $currentUser = auth()->user();

        if (!$currentUser?->can('update_role')) {
            return false;
        }

        if ($currentUser->hasRole('super_admin')) {
            return true;
        }

        if ($record->name === 'super_admin' || is_null($record->team_id)) {
            return false;
        }

        return true;
    }

    /**
     * delete_role is required to delete any role; the super_admin role and
     * global (cross-campus) roles are additionally locked to super_admin
     * itself, regardless of who holds delete_role, to stop privilege escalation.
     */
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        $currentUser = auth()->user();

        if (!$currentUser?->can('delete_role')) {
            return false;
        }

        // The super_admin role itself is never deletable, even by super_admin.
        if ($record->name === 'super_admin') {
            return false;
        }

        if ($currentUser->hasRole('super_admin')) {
            return true;
        }

        if (is_null($record->team_id)) {
            return false;
        }

        return true;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make()
                    ->schema([
                        Forms\Components\Section::make()
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('filament-shield::filament-shield.field.name'))
                                    ->unique(ignoreRecord: true)
                                    ->required()
                                    ->maxLength(255)
                                    // Prevent editing super_admin role name
                                    ->disabled(fn($record) => $record?->name === 'super_admin')
                                    ->hint(fn($record) => $record?->name === 'super_admin' ? 'This role name is protected and cannot be changed.' : null)
                                    ->hintColor('warning'),

                                Forms\Components\TextInput::make('guard_name')
                                    ->label(__('filament-shield::filament-shield.field.guard_name'))
                                    ->default(Utils::getFilamentAuthGuard())
                                    ->nullable()
                                    ->maxLength(255)
                                    // Also disable guard editing for super_admin
                                    ->disabled(fn($record) => $record?->name === 'super_admin'),

                                Forms\Components\Select::make('team_id')
                                    ->label('Campus')
                                    ->placeholder(fn() => auth()->user()?->hasRole('super_admin') ? 'Global (all campuses)' : 'Select a campus')
                                    ->options(fn() => \App\Models\Campus::pluck('name', 'id'))
                                    // super_admin sees all campuses, others only see current campus
                                    ->default(fn() => auth()->user()?->hasRole('super_admin') ? null : filament()->getTenant()?->id)
                                    // Non-super_admin users MUST always assign a campus (can't create global roles)
                                    // super_admin can leave empty for global roles, but not for super_admin role itself
                                    ->required(fn() => !auth()->user()?->hasRole('super_admin'))
                                    // Hidden when editing super_admin role
                                    ->hidden(fn($record) => $record?->name === 'super_admin')
                                    // Non-super_admin users can only assign to current campus
                                    ->disabled(fn() => !auth()->user()?->hasRole('super_admin'))
                                    ->hint(fn() => auth()->user()?->hasRole('super_admin')
                                        ? 'Leave empty for a global role (available to all campuses)'
                                        : 'Role will be assigned to current campus'),
                                ShieldSelectAllToggle::make('select_all')
                                    ->onIcon('heroicon-s-shield-check')
                                    ->offIcon('heroicon-s-shield-exclamation')
                                    ->label(__('filament-shield::filament-shield.field.select_all.name'))
                                    ->helperText(fn(): HtmlString => new HtmlString(__('filament-shield::filament-shield.field.select_all.message')))
                                    ->dehydrated(fn(bool $state): bool => $state),

                            ])
                            ->columns([
                                'sm' => 2,
                                'lg' => 3,
                            ]),
                    ]),
                static::getShieldFormComponents(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->weight('font-medium')
                    ->label(__('filament-shield::filament-shield.column.name'))
                    ->formatStateUsing(fn($state): string => Str::headline($state))
                    ->searchable(),
                Tables\Columns\TextColumn::make('guard_name')
                    ->badge()
                    ->color('warning')
                    ->label(__('filament-shield::filament-shield.column.guard_name')),
                Tables\Columns\TextColumn::make('campus.name')
                    ->label('Campus')
                    ->default('Global')
                    ->badge()
                    ->color(fn(mixed $state): string => $state === 'Global' ? 'gray' : 'primary')
                    ->searchable(),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->badge()
                    ->label(__('filament-shield::filament-shield.column.permissions'))
                    ->counts('permissions')
                    ->colors(['success']),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('filament-shield::filament-shield.column.updated_at'))
                    ->dateTime(),
            ])
            ->filters([
                //
            ], layout: FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    // Prevent deletion of super_admin role
                    ->hidden(fn($record) => $record->name === 'super_admin'),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    // Filter out super_admin role from bulk deletion
                    ->deselectRecordsAfterCompletion()
                    ->before(function ($records) {
                        return $records->filter(fn($record) => $record->name !== 'super_admin');
                    }),
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
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'view' => Pages\ViewRole::route('/{record}'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }

    public static function getCluster(): ?string
    {
        return Utils::getResourceCluster() ?? static::$cluster;
    }

    public static function getModel(): string
    {
        return Utils::getRoleModel();
    }

    public static function getModelLabel(): string
    {
        return __('filament-shield::filament-shield.resource.label.role');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-shield::filament-shield.resource.label.roles');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Utils::isResourceNavigationRegistered();
    }

    public static function getNavigationGroup(): ?string
    {
        return 'User Management';
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-shield::filament-shield.nav.role.label');
    }

    public static function getNavigationIcon(): string
    {
        return __('filament-shield::filament-shield.nav.role.icon');
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public static function getSlug(): string
    {
        return Utils::getResourceSlug();
    }

    public static function getNavigationBadge(): ?string
    {
        return Utils::isResourceNavigationBadgeEnabled()
            ? strval(static::getEloquentQuery()->count())
            : null;
    }

    public static function isScopedToTenant(): bool
    {
        // Don't scope roles to tenant - they are global
        return false;
    }

    public static function canGloballySearch(): bool
    {
        return Utils::isResourceGloballySearchable() && count(static::getGloballySearchableAttributes()) && static::canViewAny();
    }
}
