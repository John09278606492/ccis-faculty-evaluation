<?php

namespace App\Filament\Pages;

use App\Models\BackgroundTask;
use App\Services\BackgroundTasks;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;

class BackgroundProcessing extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.background-processing';

    protected static ?string $slug = 'background-tasks';

    protected static ?string $title = 'Background Processing';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    public static function canAccess(): bool
    {
        $actor = auth()->user();
        $campus = filament()->getTenant();

        return $actor && ! $actor->isStudent() && $campus && $actor->canAccessTenant($campus);
    }

    public function table(Table $table): Table
    {
        return $table->query(BackgroundTask::where('user_id', auth()->id())->where('campus_id', filament()->getTenant()->id))
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Operation')->searchable()->description(fn ($record) => $record->id),
                Tables\Columns\TextColumn::make('id')->label('Reference')->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'completed' => 'success', 'failed' => 'danger', 'processing' => 'warning', default => 'gray'
                }),
                Tables\Columns\TextColumn::make('created_at')->label('Queued')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('completed_at')->label('Finished')->dateTime()->placeholder('In progress'),
            ])->filters([
                Tables\Filters\SelectFilter::make('status')->options(['queued' => 'Queued', 'processing' => 'Processing', 'completed' => 'Completed', 'failed' => 'Failed']),
            ], layout: FiltersLayout::AboveContent)->actions([
                Tables\Actions\Action::make('view')->label('Details / Review')->icon('heroicon-o-eye')->modalWidth('6xl')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(function (BackgroundTask $record) {
                        app(BackgroundTasks::class)->authorizeTask($record, auth()->user(), filament()->getTenant());

                        return view('filament.partials.background-task-details', ['task' => $record]);
                    }),
                Tables\Actions\Action::make('confirmImport')->label(fn ($record) => match ($record->kind) {
                    'roster-review' => 'Confirm Assignment', 'section-review' => 'Confirm Section Students', default => 'Confirm Import'
                })->icon('heroicon-o-check-circle')->requiresConfirmation()
                    ->modalDescription(fn ($record) => match ($record->kind) {
                        'roster-review' => 'Add the reviewed students to this teaching class and save their sections for this academic period. Masterlist identities and permissions are checked again. Existing evaluators and other sections are kept.',
                        'section-review' => 'Add the reviewed students to this section. Existing memberships in other sections and teaching-class evaluator lists are preserved. Identities and permissions are checked again.',
                        default => 'Import the reviewed data in the background. Permissions, row validation and changes since review are checked again before saving.'
                    })
                    ->visible(fn ($record) => $record->status === 'completed' && in_array($record->kind, ['student-review', 'course-review', 'roster-review', 'section-review'], true)
                        && empty($record->result['confirmed_task_id']) && ($record->result['review']['error_count'] ?? 0) === 0)
                    ->action(function (BackgroundTask $record) {
                        app(BackgroundTasks::class)->confirm($record, auth()->user(), filament()->getTenant());
                        Notification::make()->success()->title(match ($record->kind) {
                            'roster-review' => 'Roster assignment queued', 'section-review' => 'Section students queued', default => 'Import queued in the background'
                        })->body('You can continue working. Completion will appear in your notification bell.')->send();
                    }),
                Tables\Actions\Action::make('download')->label('Download')->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn ($record) => $record->status === 'completed' && $record->file_path && $record->completed_at->gt(now()->subDays(7)))
                    ->url(fn ($record) => route('background.download', $record)),
            ])->defaultSort('created_at', 'desc')->poll('10s')->emptyStateHeading('No background operations yet');
    }
}
