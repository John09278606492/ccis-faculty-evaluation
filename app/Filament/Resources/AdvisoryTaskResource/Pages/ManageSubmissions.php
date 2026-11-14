<?php

namespace App\Filament\Resources\AdvisoryTaskResource\Pages;

use App\Filament\Resources\AdvisoryTaskResource;
use App\Models\AdvisorySubmission;
use App\Services\Advising;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/** The students of one advising task and what they sent, on its own page apart from the task settings. */
class ManageSubmissions extends ManageRelatedRecords
{
    protected static string $resource = AdvisoryTaskResource::class;

    protected static string $relationship = 'submissions';

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    public static function getNavigationLabel(): string
    {
        return 'Student submissions';
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);
        app(Advising::class)->syncRoster($this->getRecord());
    }

    public function getTitle(): string
    {
        return 'Student submissions';
    }

    public function getSubheading(): ?string
    {
        $task = $this->getRecord();

        return $task->title.' · '.$task->advisoryClass?->section?->name.($task->due_at ? ' · Due '.$task->due_at->timezone('Asia/Manila')->format('M j, Y g:ia') : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('editTask')->label('Edit task')->icon('heroicon-o-pencil-square')->color('gray')
                ->url(fn () => AdvisoryTaskResource::getUrl('edit', ['record' => $this->getRecord()])),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('student_name')
            ->defaultSort(fn ($query) => $query
                ->orderByRaw("case status when 'submitted' then 0 when 'returned' then 1 when 'pending' then 2 else 3 end")
                ->orderBy('student_name'))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->recordUrl(fn (AdvisorySubmission $record) => $record->submitted_at
                ? ReviewSubmission::getUrl(['record' => $this->getRecord(), 'submission' => $record->id])
                : null)
            ->columns([
                Tables\Columns\TextColumn::make('student_name')->label('Student')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => AdvisorySubmission::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => AdvisorySubmission::COLORS[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('files_count')->label('Files')
                    ->state(fn (AdvisorySubmission $record) => count($record->files ?? []) ?: null)->placeholder('—'),
                Tables\Columns\TextColumn::make('submitted_at')->label('Submitted')->dateTime('M j, Y g:ia', timezone: 'Asia/Manila')->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('feedback')->wrap()->limit(80)->placeholder('—')->visibleFrom('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(AdvisorySubmission::STATUSES),
            ], layout: FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\Action::make('open')->label('Review')->icon('heroicon-o-eye')->button()
                    ->visible(fn (AdvisorySubmission $record) => $record->submitted_at !== null)
                    ->url(fn (AdvisorySubmission $record) => ReviewSubmission::getUrl(['record' => $this->getRecord(), 'submission' => $record->id])),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('setStatus')->label('Update status')->icon('heroicon-o-check-badge')
                    ->visible(fn () => auth()->user()->can('update', $this->getRecord()))
                    ->form([
                        Forms\Components\Select::make('status')->label('New status')
                            ->options(['approved' => 'Approved', 'returned' => 'Returned for changes'])->default('approved')->required()->live(),
                        Forms\Components\Textarea::make('feedback')->label('Feedback to the students')
                            ->required(fn (Forms\Get $get) => $get('status') === 'returned')->maxLength(5000),
                    ])
                    ->modalDescription('Only students who already submitted files are updated. Each one gets a notification.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records, array $data) {
                        $done = 0;
                        foreach ($records->whereNotNull('submitted_at') as $record) {
                            try {
                                app(Advising::class)->review($record, $data['status'], $data['feedback'] ?? null, auth()->user());
                                $done++;
                            } catch (ValidationException) {
                            }
                        }
                        $skipped = $records->count() - $done;
                        Notification::make()->title("{$done} ".str('student')->plural($done).' updated')
                            ->body($skipped ? "{$skipped} skipped because they have not submitted files yet." : null)
                            ->success()->send();
                    }),
            ])
            ->emptyStateHeading('No students in this section yet');
    }
}
