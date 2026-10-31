<?php

namespace App\Filament\Pages;

use App\Jobs\SendEvaluationReminderEmail;
use App\Models\EvaluationReminder;
use App\Models\User;
use App\Services\EvaluationReminders;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Every evaluation reminder email with its delivery state; super admins see the campus, others their own. */
class EmailLog extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.email-log';

    protected static ?string $navigationGroup = 'Faculty Evaluation';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationIcon = 'heroicon-o-envelope-open';

    protected static ?string $title = 'Email Log';

    public const STATUSES = ['queued' => 'Waiting to send', 'sent' => 'Sent', 'failed' => 'Failed', 'none' => 'Bell only'];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && filament()->getTenant() && ($user->isSuperAdmin() || StudentEvaluationProgress::canAccess());
    }

    private function seesEveryone(): bool
    {
        return auth()->user()->isSuperAdmin() || auth()->user()->hasRole('campus_admin');
    }

    private function scoped(): Builder
    {
        return EvaluationReminder::query()->where('campus_id', filament()->getTenant()->id)
            ->unless($this->seesEveryone(), fn ($q) => $q->where('sender_id', auth()->id()));
    }

    public function getSubheading(): ?string
    {
        $today = $this->scoped()->where('created_at', '>=', now()->startOfDay())->selectRaw('email_status, count(*) as total')->groupBy('email_status')->pluck('total', 'email_status');
        $stuck = $this->scoped()->where('email_status', 'queued')->where('created_at', '<', now()->subMinutes(15))->exists();

        return 'Today: '.($today['sent'] ?? 0).' sent · '.($today['queued'] ?? 0).' waiting · '.($today['failed'] ?? 0).' failed · '.($today['none'] ?? 0).' bell only.'
            .($stuck ? ' Some emails have been waiting over 15 minutes: check that the queue cron job is running on the server.' : '')
            .' "Sent" means the mail server accepted it; Gmail returns bounces to the sender\'s inbox.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retryAll')->label('Retry all failed')->icon('heroicon-o-arrow-path')->color('danger')
                ->visible(fn () => $this->scoped()->where('email_status', 'failed')->exists())
                ->requiresConfirmation()->modalDescription(fn () => $this->scoped()->where('email_status', 'failed')->count().' failed emails will be sent again.')
                ->action(fn () => $this->retry($this->scoped()->where('email_status', 'failed')->get())),
            Action::make('settings')->label('Email settings')->icon('heroicon-o-cog-6-tooth')->color('gray')
                ->visible(fn () => EmailReminderSettings::canAccess())->url(fn () => EmailReminderSettings::getUrl()),
        ];
    }

    /** A plain-language reason for common mail server errors; the full text stays in the tooltip and message view. */
    public static function friendlyError(?string $error): string
    {
        $e = mb_strtolower((string) $error);

        return match (true) {
            str_contains($e, '535') || str_contains($e, 'username and password') || str_contains($e, 'authenticat') => 'Wrong sender email or app password',
            str_contains($e, 'connection') || str_contains($e, 'timed out') || str_contains($e, 'could not be established') => 'Could not reach the mail server',
            str_contains($e, '550') || str_contains($e, 'does not exist') || str_contains($e, 'no such user') => 'Address not found',
            str_contains($e, 'limit') || str_contains($e, '421') || str_contains($e, '4.7.0') => 'Daily sending limit reached, retry tomorrow',
            str_contains($e, 'not set up') => 'Email reminders are not set up',
            default => (string) str((string) $error)->limit(40),
        };
    }

    private function retry(Collection $reminders): void
    {
        abort_unless(static::canAccess(), 403);
        if (! app(EvaluationReminders::class)->emailReady()) {
            Notification::make()->danger()->title('Email is not set up')->body('A super admin must save the sender email and app password in Email Reminders first.')->send();

            return;
        }
        $count = 0;
        foreach ($reminders as $reminder) {
            if ($reminder->email_status !== 'failed' || ! $reminder->email || ! $this->scoped()->whereKey($reminder->id)->exists()) {
                continue;
            }
            $reminder->update(['email_status' => 'queued', 'email_error' => null]);
            SendEvaluationReminderEmail::dispatch($reminder->id);
            $count++;
        }
        Notification::make()->success()->title($count.' '.str('email')->plural($count).' queued again')->body('They go out within a minute or two.')->send();
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => $this->scoped()->with(['student', 'sender']))
            ->defaultSort('id', 'desc')->poll('20s')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Sent on')->dateTime('M j, g:i A')->sortable()
                    ->description(fn (EvaluationReminder $r) => $r->created_at->diffForHumans()),
                Tables\Columns\TextColumn::make('student.name')->label('Student')
                    ->description(fn (EvaluationReminder $r) => $r->student?->student_id)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('student', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('student_id', 'like', "%{$search}%"))),
                Tables\Columns\TextColumn::make('email')->label('Email address')->placeholder('No email')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('email_status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'sent' => 'success', 'failed' => 'danger', 'queued' => 'warning', default => 'gray'
                    })
                    ->icon(fn (string $state) => match ($state) {
                        'sent' => 'heroicon-o-check-circle', 'failed' => 'heroicon-o-x-circle', 'queued' => 'heroicon-o-clock', default => 'heroicon-o-bell'
                    })
                    ->description(fn (EvaluationReminder $r) => $r->email_status === 'sent' && $r->emailed_at ? 'at '.$r->emailed_at->format('g:i A') : ($r->email_status === 'failed' ? self::friendlyError($r->email_error) : null))
                    ->wrap()
                    ->tooltip(fn (EvaluationReminder $r) => $r->email_status === 'failed' ? $r->email_error : null),
                Tables\Columns\TextColumn::make('subject')->label('Title')->limit(40)->tooltip(fn (EvaluationReminder $r) => $r->subject)->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('remaining')->label('Left')->alignCenter()->toggleable(),
                Tables\Columns\TextColumn::make('sender.name')->label('Sent by')->visible(fn () => $this->seesEveryone())->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('email_status')->label('Status')->options(self::STATUSES),
                Tables\Filters\SelectFilter::make('period')->label('When')->placeholder('Any time')
                    ->options(['today' => 'Today', 'week' => 'Last 7 days', 'month' => 'Last 30 days'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'today' => $query->where('created_at', '>=', now()->startOfDay()),
                        'week' => $query->where('created_at', '>=', now()->subDays(7)),
                        'month' => $query->where('created_at', '>=', now()->subDays(30)),
                        default => $query,
                    }),
                Tables\Filters\SelectFilter::make('sender_id')->label('Sent by')->visible(fn () => $this->seesEveryone())
                    ->options(fn () => User::whereIn('id', $this->scoped()->select('sender_id'))->orderBy('name')->pluck('name', 'id')),
            ])
            ->actions([
                Tables\Actions\Action::make('retry')->label('Retry')->icon('heroicon-o-arrow-path')->color('danger')
                    ->visible(fn (EvaluationReminder $r) => $r->email_status === 'failed')
                    ->iconButton()->tooltip('Send this email again')
                    ->action(fn (EvaluationReminder $r) => $this->retry(collect([$r]))),
                Tables\Actions\Action::make('view')->label('Message')->icon('heroicon-o-eye')->color('gray')->iconButton()->tooltip('See the message and any error')
                    ->modalHeading(fn (EvaluationReminder $r) => $r->subject)->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (EvaluationReminder $r) => view('filament.pages.email-log-message', ['reminder' => $r])),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('retrySelected')->label('Retry failed')->icon('heroicon-o-arrow-path')->color('danger')
                    ->deselectRecordsAfterCompletion()->action(fn (Collection $records) => $this->retry($records)),
            ])
            ->emptyStateHeading('No reminders yet')
            ->emptyStateDescription('Reminders sent from Student Progress with the Remind button appear here with their email status.')
            ->emptyStateIcon('heroicon-o-envelope');
    }
}
