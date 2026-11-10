<?php

namespace App\Filament\Resources\AcknowledgementFormResource\RelationManagers;

use App\Filament\Forms\Components\SignaturePad;
use App\Models\AcknowledgementRecipient;
use App\Services\AcknowledgementForms;
use App\Support\SignatureStrokes;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    protected static ?string $title = 'Students & signatures';

    private ?bool $canManage = null;

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $counts = $ownerRecord->recipients()->toBase()->selectRaw('count(signed_at) as signed, count(*) as total')->first();

        return "{$counts->signed} / {$counts->total}";
    }

    /** Asked once per request instead of once per action on every row. */
    private function canManage(): bool
    {
        return $this->canManage ??= auth()->user()->can('update', $this->getOwnerRecord());
    }

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    private function wholeClassToggle(): Forms\Components\Toggle
    {
        return Forms\Components\Toggle::make('whole_class')
            ->label("Also remove from this class's other receipts")
            ->default(true);
    }

    /** @param  array<int, int>  $userIds */
    private function removeAndNotify(array $userIds, bool $wholeClass): void
    {
        $rows = app(AcknowledgementForms::class)->removeStudents($this->getOwnerRecord(), $userIds, $wholeClass);
        $students = count($userIds);
        Notification::make()
            ->title("{$students} ".str('student')->plural($students).' removed'.($wholeClass ? " ({$rows} ".str('receipt')->plural($rows).')' : ''))
            ->success()
            ->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('student_name')
            // Signature drawings are only read when one is opened, so the list stays light for large classes.
            ->modifyQueryUsing(fn (Builder $query) => $query->select(array_map(fn ($column) => "acknowledgement_recipients.{$column}", AcknowledgementRecipient::LIST_COLUMNS))->with('signer:id,name'))
            ->defaultSort('student_name')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->poll('20s')
            ->columns([
                Tables\Columns\TextColumn::make('student_name')->label('Name of student')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('signature_status')
                    ->label('Status')
                    ->state(fn (AcknowledgementRecipient $record) => $record->signed_at ? 'Signed' : 'Waiting')
                    ->badge()
                    ->color(fn (string $state) => $state === 'Signed' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('signed_at')->label('Signed on')->dateTime('M j, Y g:ia')->placeholder('—')->sortable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('signed_for')
                    ->visibleFrom('md')
                    ->label('Captured by')
                    ->state(fn (AcknowledgementRecipient $record) => match (true) {
                        ! $record->signed_at => null,
                        $record->signed_by === $record->user_id => 'Student',
                        default => $record->signer?->name ?? 'Staff',
                    })
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('signed')
                    ->placeholder('All students')
                    ->trueLabel('Signed')
                    ->falseLabel('Waiting')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('signed_at'),
                        false: fn (Builder $query) => $query->whereNull('signed_at'),
                    ),
            ], layout: FiltersLayout::AboveContent)
            ->headerActions([
                Tables\Actions\Action::make('remindWaiting')
                    ->label('Remind waiting students')
                    ->icon('heroicon-o-bell-alert')
                    ->color('gray')
                    ->visible(fn () => $this->canManage() && $this->getOwnerRecord()->isOpen())
                    ->requiresConfirmation()
                    ->modalDescription('Every student who has not signed gets a reminder in their notification bell.')
                    ->action(function () {
                        abort_unless($this->canManage(), 403);
                        $sent = app(AcknowledgementForms::class)->notifyWaiting($this->getOwnerRecord(), 'Reminder: please sign this form');
                        Notification::make()->title($sent ? "Reminder sent to {$sent} ".str('student')->plural($sent) : 'Everyone has already signed')->success()->send();
                    }),
                Tables\Actions\Action::make('removedStudents')
                    ->label(fn () => 'Removed students ('.$this->getOwnerRecord()->removedRecipients()->count().')')
                    ->icon('heroicon-o-user-plus')
                    ->color('gray')
                    ->visible(fn () => $this->canManage() && $this->getOwnerRecord()->removedRecipients()->count() > 0)
                    ->modalHeading('Removed students')
                    ->modalDescription('These students are not on this receipt or its printout. Tick the ones to add back.')
                    ->modalSubmitActionLabel('Add back')
                    ->form([
                        Forms\Components\CheckboxList::make('students')
                            ->hiddenLabel()
                            ->options(fn () => $this->getOwnerRecord()->removedRecipients()->orderBy('student_name')->pluck('student_name', 'user_id')->all())
                            ->bulkToggleable()
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        abort_unless($this->canManage(), 403);
                        $count = app(AcknowledgementForms::class)->restoreStudents($this->getOwnerRecord(), array_map('intval', $data['students']));
                        Notification::make()->title("{$count} ".str('student')->plural($count).' added back')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('removeSelected')
                    ->label('Remove from receipt')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->visible(fn () => $this->canManage())
                    ->requiresConfirmation()
                    ->modalHeading('Remove the selected students?')
                    ->modalDescription('For students who stopped attending. They leave the list and the printout, and stop seeing this receipt. You can add them back later.')
                    ->form([$this->wholeClassToggle()])
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Support\Collection $records, array $data) {
                        abort_unless($this->canManage(), 403);
                        $this->removeAndNotify($records->pluck('user_id')->all(), (bool) $data['whole_class']);
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('viewSignature')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn (AcknowledgementRecipient $record) => $record->signed_at !== null)
                    ->modalHeading(fn (AcknowledgementRecipient $record) => $record->student_name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->form([
                        Forms\Components\Placeholder::make('signature')
                            ->hiddenLabel()
                            ->content(fn (AcknowledgementRecipient $record) => SignatureStrokes::svg($record->newQuery()->whereKey($record->id)->value('signature_path'), $record->student_name)),
                    ]),
                Tables\Actions\Action::make('signFor')
                    ->label('Sign here')
                    ->icon('heroicon-o-pencil')
                    ->visible(fn (AcknowledgementRecipient $record) => $record->signed_at === null && $this->canManage())
                    ->modalHeading(fn (AcknowledgementRecipient $record) => 'Signature of '.$record->student_name)
                    ->modalDescription('Hand this device to the student. For a student signing in person.')
                    ->modalSubmitActionLabel('Save signature')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->form([SignaturePad::make('signature')->hiddenLabel()])
                    ->action(function (AcknowledgementRecipient $record, array $data) {
                        abort_unless($this->canManage(), 403);
                        app(AcknowledgementForms::class)->signForStudent($record, $data['signature'], auth()->user(), request()->ip());
                        Notification::make()->title('Signature saved')->success()->send();
                    }),
                Tables\Actions\Action::make('clearSignature')
                    ->label('Clear')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The student will be able to sign this form again. This cannot be undone.')
                    ->visible(fn (AcknowledgementRecipient $record) => $record->signed_at !== null && $this->canManage())
                    ->action(function (AcknowledgementRecipient $record) {
                        abort_unless($this->canManage(), 403);
                        app(AcknowledgementForms::class)->clear($record);
                        Notification::make()->title('Signature cleared')->success()->send();
                    }),
                Tables\Actions\Action::make('remove')
                    ->label('Remove')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->visible(fn () => $this->canManage())
                    ->requiresConfirmation()
                    ->modalHeading(fn (AcknowledgementRecipient $record) => 'Remove '.$record->student_name.'?')
                    ->modalDescription('For a student who stopped attending. They leave the list and the printout, and stop seeing this receipt. You can add them back later.')
                    ->form([$this->wholeClassToggle()])
                    ->action(function (AcknowledgementRecipient $record, array $data) {
                        abort_unless($this->canManage(), 403);
                        $this->removeAndNotify([$record->user_id], (bool) $data['whole_class']);
                    }),
            ])
            ->emptyStateHeading('No students on this receipt')
            ->emptyStateDescription('Enroll students in the class, then open this receipt again.');
    }
}
