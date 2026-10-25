<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EvaluationReportResource\Pages;
use App\Models\User;
use App\Services\ReportService;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Compatibility routes only: one Faculty Results workspace owns reporting. */
class EvaluationReportResource extends Resource
{
    protected static ?string $model = User::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $slug = 'reports/evaluations';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $campus = filament()->getTenant();

        return $user && $campus && ! $user->isStudent() && $user->canAccessTenant($campus) && $user->can('page_EvaluationResults');
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        return static::canAccess() && static::getEloquentQuery()->whereKey($record->id)->exists();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return User::whereIn('id', app(ReportService::class)->offerings(['campus_id' => filament()->getTenant()?->id])->select('user_id'));
    }

    public static function table(Table $table): Table
    {
        return $table;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListEvaluationReports::route('/'), 'view' => Pages\ViewEvaluationReport::route('/{record}')];
    }
}
