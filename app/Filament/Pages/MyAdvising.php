<?php

namespace App\Filament\Pages;

use App\Models\AdvisorySubmission;
use App\Models\AdvisoryTask;
use App\Services\Advising;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class MyAdvising extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'My Learning';

    protected static ?string $title = 'My Advising';

    protected static ?string $slug = 'my-advising';

    protected static string $view = 'filament.pages.my-advising';

    public static function canAccess(): bool
    {
        return auth()->user()?->isStudent() && auth()->user()->can('page_MyAdvising');
    }

    public static function getNavigationBadge(): ?string
    {
        return static::canAccess() ? (string) (app(Advising::class)->toDoCount(auth()->user(), filament()->getTenant()?->id) ?: '') : null;
    }

    public function submission(AdvisoryTask $task): ?AdvisorySubmission
    {
        return $task->submissions->first();
    }

    public function table(Table $table): Table
    {
        return $table->query(app(Advising::class)->tasksFor(auth()->user(), filament()->getTenant()?->id)
            ->with(['advisoryClass.section', 'advisingSemester.schoolYear', 'submissions' => fn ($q) => $q->where('user_id', auth()->id())]))
            ->defaultSort('due_at')->columns([
                Tables\Columns\TextColumn::make('title')->wrap()->searchable(),
                Tables\Columns\TextColumn::make('advisoryClass.section.name')->label('Section'),
                Tables\Columns\TextColumn::make('advising_term')->label('Advising for')->wrap(),
                Tables\Columns\TextColumn::make('due_at')->dateTime('M j, Y g:ia', timezone: 'Asia/Manila'),
                Tables\Columns\TextColumn::make('submission_status')->label('Your status')->badge()
                    ->state(fn (AdvisoryTask $record) => $this->submission($record)?->status ?? 'pending')
                    ->formatStateUsing(fn ($state) => AdvisorySubmission::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => AdvisorySubmission::COLORS[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('status')->label('Task')->badge(),
            ])->actions([
                Tables\Actions\Action::make('details')->label('Requirements & feedback')->icon('heroicon-o-document-text')
                    ->modalContent(fn (AdvisoryTask $record) => view('filament.partials.advisory-details', ['task' => $record, 'submission' => $this->submission($record)]))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Tables\Actions\Action::make('submit')->label('Submit files')->icon('heroicon-o-arrow-up-tray')
                    ->visible(fn (AdvisoryTask $record) => $record->acceptsSubmissions() && $this->submission($record)?->status !== 'approved')
                    ->modalDescription(fn (AdvisoryTask $record) => 'Send your files for your adviser to review. Accepted: '.$record->fileTypesLabel().'. Sending again replaces your previous files.')
                    ->modalContent(fn (AdvisoryTask $record) => view('filament.partials.advisory-details', ['task' => $record, 'submission' => $this->submission($record)]))
                    ->stickyModalFooter()->form([
                        Forms\Components\FileUpload::make('files')->label('Your files')->disk(Advising::DISK)
                            ->helperText(fn (AdvisoryTask $record) => 'Accepted: '.$record->fileTypesLabel().'. Up to 10 files, 10 MB each.')
                            ->directory(fn (AdvisoryTask $record) => app(Advising::class)->folder($record, auth()->user()))
                            ->visibility('private')->multiple()->minFiles(1)->maxFiles(10)->maxSize(10240)
                            ->acceptedFileTypes(fn (AdvisoryTask $record) => $record->acceptedMimes())
                            ->required()->fetchFileInformation(false),
                        Forms\Components\Textarea::make('note')->label('Note for your adviser')->maxLength(5000),
                    ])->action(function (AdvisoryTask $record, array $data) {
                        abort_unless(static::canAccess(), 403);
                        abort_unless(app(Advising::class)->tasksFor(auth()->user(), filament()->getTenant()?->id)->whereKey($record->id)->exists(), 403);
                        app(Advising::class)->submit($record, auth()->user(), $data['files'], $data['note'] ?? null);
                        Notification::make()->title('Files submitted to your adviser')->success()->send();
                    }),
            ])->emptyStateHeading('No advising tasks yet')->emptyStateDescription('Requirements from your section adviser will appear here.');
    }
}
