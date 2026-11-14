<?php

namespace App\Filament\Pages;

use App\Filament\Forms\Components\SignaturePad;
use App\Models\AcknowledgementForm;
use App\Models\AcknowledgementRecipient;
use App\Services\AcknowledgementForms;
use App\Support\SignatureStrokes;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

class MyAcknowledgements extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static string $view = 'filament.pages.my-acknowledgements';

    protected static ?string $navigationLabel = 'My Acknowledgements';

    protected static ?string $slug = 'my-acknowledgements';

    protected static ?string $title = 'My Acknowledgements';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationGroup = 'My Learning';

    /** The subject card the student tapped, so the list below shows only that subject. */
    #[Url]
    public ?int $subject = null;

    /** Memoized for this request: the button's label and visibility both need it. */
    protected ?EloquentCollection $signable = null;

    public function mount(): void
    {
        app(AcknowledgementForms::class)->addToOpenForms(Auth::user(), filament()->getTenant()?->id);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->is_student && static::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->is_student) {
            return null;
        }

        return app(AcknowledgementForms::class)->toSignCount(auth()->user(), filament()->getTenant()?->id) ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function getSubheading(): ?string
    {
        return 'Sign to confirm that you received a document from your instructor. Only you and your instructor can see your signature.';
    }

    /** Forms Sign all can apply to, narrowed to the subject card the student tapped. */
    protected function signableForms(): EloquentCollection
    {
        return $this->signable ??= app(AcknowledgementForms::class)
            ->signableForms(Auth::user(), filament()->getTenant()?->id)
            ->when($this->subject, fn ($query, $classId) => $query->where('course_offering_id', $classId))
            ->with('courseOffering.course')
            ->orderBy('date_received')
            ->get();
    }

    protected function signAllAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('signAll')
                ->label(fn () => 'Sign all ('.$this->signableForms()->count().')')
                ->button()
                ->icon('heroicon-o-pencil-square')
                ->size(ActionSize::Large)
                ->visible(fn () => $this->signableForms()->count() > 1)
                ->modalHeading('Sign these forms together')
                ->modalDescription('Draw once. Your signature is saved on each form you leave checked.')
                ->modalSubmitActionLabel('Save signature')
                ->modalWidth(MaxWidth::TwoExtraLarge)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->form([
                    Forms\Components\CheckboxList::make('forms')
                        ->label('Documents you received')
                        ->helperText('Uncheck anything you did not receive. You can sign it later.')
                        ->options(fn () => $this->signableForms()->mapWithKeys(fn (AcknowledgementForm $form) => [
                            $form->id => $form->document_received,
                        ])->all())
                        ->descriptions(fn () => $this->signableForms()->mapWithKeys(fn (AcknowledgementForm $form) => [
                            $form->id => "{$form->courseOffering?->course?->code} · {$form->year_section} · {$form->date_label}",
                        ])->all())
                        ->default(fn () => $this->signableForms()->modelKeys())
                        ->required()
                        ->validationMessages(['required' => 'Check at least one document to sign.']),
                    SignaturePad::make('signature')
                        ->label('Your signature')
                        ->helperText('By signing, you confirm that you received every document checked above.'),
                ])
                ->action(function (array $data) {
                    $forms = app(AcknowledgementForms::class);
                    $campusId = filament()->getTenant()?->id;
                    $signed = $forms->signManyAsStudent(Auth::user(), $campusId, $data['forms'], $data['signature'], request()->ip());
                    $skipped = count($data['forms']) - $signed;
                    $left = $forms->toSignCount(Auth::user(), $campusId);
                    $this->signable = null;

                    Notification::make()
                        ->title("Signature saved on {$signed} ".str('form')->plural($signed))
                        ->body(trim(($skipped ? "{$skipped} could not be signed because ".($skipped === 1 ? 'it is' : 'they are').' no longer open. ' : '').($left ? "{$left} more to sign." : 'You have no more forms to sign.')))
                        ->{$skipped ? 'warning' : 'success'}()
                        ->send();
                    $this->refreshSidebarCount();
                });
    }

    /** The sidebar badge sits outside this component, so it only shows the new count after a reload. */
    protected function refreshSidebarCount(): void
    {
        $this->redirect(static::getUrl(array_filter(['subject' => $this->subject])));
    }

    public function getSubjectSummary(): Collection
    {
        return app(AcknowledgementForms::class)->studentSummary(Auth::user(), filament()->getTenant()?->id);
    }

    public function toggleSubject(int $classId): void
    {
        $this->subject = $this->subject === $classId ? null : $classId;
        $this->signable = null;
        $this->resetPage($this->getTablePaginationPageName());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->headerActions([$this->signAllAction()])
            ->modifyQueryUsing(fn (Builder $query) => $query->when($this->subject, fn ($q, $classId) => $q->where('course_offering_id', $classId)))
            // Forms that still need the student come first.
            ->defaultSort(fn (Builder $query) => $query
                ->orderByRaw("case when signed_at is null and status = 'active' then 0 when signed_at is not null then 1 else 2 end")
                ->orderByDesc('date_received'))
            ->columns([
                Tables\Columns\TextColumn::make('document_received')->label('Document received')->searchable()->wrap()->weight('medium'),
                Tables\Columns\TextColumn::make('subject')
                    ->label('Subject')
                    ->state(fn (AcknowledgementForm $record) => $record->courseOffering?->course?->name)
                    ->description(fn (AcknowledgementForm $record) => $record->courseOffering?->course?->code)
                    ->wrap(),
                Tables\Columns\TextColumn::make('year_section')->label('Year & section')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('date_received')->date('F j, Y')->sortable(),
                Tables\Columns\TextColumn::make('signature_status')
                    ->label('Status')
                    ->state(fn (AcknowledgementForm $record) => $this->statusFor($record))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'Signed' => 'success',
                        'Closed' => 'gray',
                        default => 'warning',
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('sign')
                    ->label('Sign this form')
                    ->icon('heroicon-o-pencil')
                    ->button()
                    ->size(ActionSize::Large)
                    ->visible(fn (AcknowledgementForm $record) => $this->statusFor($record) === 'To sign')
                    ->modalHeading('Acknowledge this document')
                    ->modalDescription(fn (AcknowledgementForm $record) => "{$record->document_received} · {$record->year_section} · {$record->date_label}")
                    ->modalSubmitActionLabel('Save signature')
                    ->modalWidth(MaxWidth::TwoExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->form([
                        Forms\Components\Placeholder::make('student')
                            ->label('Name of student')
                            ->extraAttributes(['class' => 'fi-fo-placeholder-hide-short'])
                            ->content(fn () => app(AcknowledgementForms::class)->printedName(Auth::user())),
                        SignaturePad::make('signature')
                            ->label('Your signature')
                            ->helperText('By signing, you confirm that you received this document.'),
                    ])
                    ->action(function (AcknowledgementForm $record, array $data) {
                        $forms = app(AcknowledgementForms::class);
                        $saved = $forms->signAsStudent($record, Auth::user(), $data['signature'], request()->ip());
                        $left = $forms->toSignCount(Auth::user(), filament()->getTenant()?->id);

                        Notification::make()
                            ->title($saved ? 'Signature saved' : 'You have already signed this form')
                            ->body($left ? "{$left} more ".str('form')->plural($left).' to sign.' : 'You have no more forms to sign.')
                            ->{$saved ? 'success' : 'info'}()
                            ->send();
                        $this->refreshSidebarCount();
                    }),
                Tables\Actions\Action::make('viewMine')
                    ->label('View my signature')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn (AcknowledgementForm $record) => $record->signed_at !== null)
                    ->modalHeading(fn (AcknowledgementForm $record) => $record->document_received)
                    ->modalDescription(fn (AcknowledgementForm $record) => 'Signed '.Carbon::parse($record->signed_at)->format('F j, Y g:ia'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->form([
                        Forms\Components\Placeholder::make('signature')
                            ->hiddenLabel()
                            ->content(fn (AcknowledgementForm $record) => SignatureStrokes::svg(
                                AcknowledgementRecipient::where('acknowledgement_form_id', $record->id)->where('user_id', Auth::id())->value('signature_path'),
                                'Your signature',
                            )),
                    ]),
            ])
            ->emptyStateHeading('Nothing to sign')
            ->emptyStateDescription('Forms from your instructors will appear here.')
            ->emptyStateIcon('heroicon-o-document-check');
    }

    protected function getTableQuery(): Builder
    {
        return AcknowledgementForm::query()
            ->inCampus(filament()->getTenant()?->id)
            ->where('status', '!=', 'archived')
            ->whereIn('course_offering_id', DB::table('course_offering_student')->where('user_id', Auth::id())->select('course_offering_id'))
            // Receipts the instructor removed this student from.
            ->whereNotIn('id', AcknowledgementRecipient::where('user_id', Auth::id())->whereNotNull('removed_at')->select('acknowledgement_form_id'))
            ->with('courseOffering.course')
            ->addSelect(['signed_at' => AcknowledgementRecipient::select('signed_at')
                ->whereColumn('acknowledgement_form_id', 'acknowledgement_forms.id')
                ->where('user_id', Auth::id())
                ->limit(1)]);
    }

    protected function statusFor(AcknowledgementForm $form): string
    {
        return match (true) {
            $form->signed_at !== null => 'Signed',
            $form->isOpen() => 'To sign',
            default => 'Closed',
        };
    }
}
