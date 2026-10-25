<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\AssignedDataAccess;
use App\Services\UserAccessAdministration;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccessAssignments extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.access-assignments';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $title = 'Access & Assignments';

    public static function canAccess(): bool
    {
        $actor = auth()->user();
        $campus = filament()->getTenant();

        return $actor && $campus && Schema::hasTable('user_campus_access') && ! $actor->isStudent()
            && $actor->can('manage_access_assignments') && $actor->canAccessTenant($campus);
    }

    public function table(Table $table): Table
    {
        return $table->query(app(AssignedDataAccess::class)->apply(User::query(), auth()->user(), filament()->getTenant())->where('is_student', false)->with('roles'))
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('roles.name')->badge()->label('Roles / Allowed Actions'),
                Tables\Columns\TextColumn::make('effective_scope')->label('Effective Data Access')->state(function (User $record) {
                    $scope = app(AssignedDataAccess::class)->departmentIds($record, filament()->getTenant());
                    if ($scope === null) {
                        return 'All departments in this campus';
                    }

                    return $scope === [] ? 'No departments assigned' : DB::table('departments')->whereIn('id', $scope)->pluck('code')->implode(', ');
                }),
            ])->actions([
                Tables\Actions\Action::make('scope')->label('Set Data Access')->icon('heroicon-o-adjustments-horizontal')
                    ->visible(fn (User $record) => ! $record->isSuperAdmin() && (auth()->user()->isSuperAdmin() || $record->id !== auth()->id()))
                    ->fillForm(fn (User $record) => [
                        'scope' => DB::table('user_campus_access')->where('user_id', $record->id)->where('campus_id', filament()->getTenant()->id)->value('scope') ?? 'departments',
                        'departments' => DB::table('user_department_access')->where('user_id', $record->id)->whereIn('department_id', DB::table('departments')->select('id')->where('campus_id', filament()->getTenant()->id))->pluck('department_id')->all(),
                    ])
                    ->form([
                        Forms\Components\Select::make('scope')->label('Data scope')->required()->live()->options(function () {
                            $options = ['departments' => 'Assigned departments only'];
                            if (app(AssignedDataAccess::class)->departmentIds(auth()->user(), filament()->getTenant()) === null) {
                                $options['campus'] = 'All departments in this campus';
                            }

                            return $options;
                        }),
                        Forms\Components\Select::make('departments')->label('Additional department assignments')->multiple()->searchable()
                            ->visible(fn (Forms\Get $get) => $get('scope') === 'departments')
                            ->options(function () {
                                $ids = app(AssignedDataAccess::class)->departmentIds(auth()->user(), filament()->getTenant());

                                return DB::table('departments')->where('campus_id', filament()->getTenant()->id)->where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->pluck('name', 'id')->all();
                            })
                            ->helperText('Home department and configured supervisor/chairperson departments also remain assigned. Change those source assignments to remove that access.'),
                    ])->action(fn (User $record, array $data) => app(UserAccessAdministration::class)->setScope(auth()->user(), $record, filament()->getTenant(), $data['scope'], $data['departments'] ?? [])),
            ]);
    }
}
