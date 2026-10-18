<?php

namespace App\Filament\Widgets;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Models\User;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestUsersWidget extends BaseWidget
{
    use HasWidgetShield;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Latest Registered Users';

    public static function canView(): bool
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return true;
        }

        $permissionName = static::getPermissionName();

        return $user->can($permissionName);
    }

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();

        $query = $tenant
            ? User::whereHas('campuses', fn ($q) => $q->where('campuses.id', $tenant->id))->latest()
            : User::latest();

        return $table
            ->query($query)
            ->defaultPaginationPageOption(5)
            ->striped()
            ->columns([
                Tables\Columns\ImageColumn::make('avatar_url')
                    ->label('Avatar')
                    ->circular()
                    ->defaultImageUrl(fn (User $record) => app(InitialsAvatarProvider::class)->get($record)),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin' => 'danger',
                        'panel_user' => 'gray',
                        'student' => 'info',
                        default => 'primary',
                    }),
                Tables\Columns\IconColumn::make('is_student')
                    ->label('Student')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_instructor')
                    ->label('Instructor')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Registered')
                    ->dateTime()
                    ->sortable()
                    ->since(),
            ]);
    }
}
