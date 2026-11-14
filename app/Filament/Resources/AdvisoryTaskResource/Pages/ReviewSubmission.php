<?php

namespace App\Filament\Resources\AdvisoryTaskResource\Pages;

use App\Filament\Resources\AdvisoryTaskResource;
use App\Models\AdvisorySubmission;
use App\Services\Advising;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Validation\ValidationException;

/** One student's files for an advising task, shown in full, with the adviser's decision. */
class ReviewSubmission extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = AdvisoryTaskResource::class;

    protected static string $view = 'filament.resources.advisory-task-resource.pages.review-submission';

    public AdvisorySubmission $entry;

    public ?array $data = [];

    public function mount(int|string $record, int|string $submission): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(auth()->user()->can('view', $this->record), 403);
        $this->entry = AdvisorySubmission::where('advisory_task_id', $this->record->id)->findOrFail($submission);
        $this->form->fill([
            'status' => in_array($this->entry->status, ['approved', 'returned'], true) ? $this->entry->status : 'approved',
            'feedback' => $this->entry->feedback,
        ]);
    }

    public function getTitle(): string
    {
        return $this->entry->student_name;
    }

    public function getSubheading(): ?string
    {
        return $this->record->title.' · '.$this->record->advisoryClass?->section?->name;
    }

    public function getBreadcrumbs(): array
    {
        return [
            AdvisoryTaskResource::getUrl() => 'Advising Tasks',
            ManageSubmissions::getUrl(['record' => $this->record]) => 'Student submissions',
            '' => $this->entry->student_name,
        ];
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Radio::make('status')->label('Status')
                ->options(['approved' => 'Approved: requirements complete', 'returned' => 'Returned: the student must fix and send again'])
                ->required()->live(),
            Forms\Components\Textarea::make('feedback')->label('Feedback to the student')
                ->helperText(fn (Forms\Get $get) => $get('status') === 'returned' ? 'Required. Say exactly what to fix, for example which course has no grade.' : 'Optional.')
                ->required(fn (Forms\Get $get) => $get('status') === 'returned')
                ->rows(3)->maxLength(5000),
        ]);
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('update', $this->record), 403);
        $data = $this->form->getState();
        try {
            app(Advising::class)->review($this->entry, $data['status'], $data['feedback'] ?? null, auth()->user());
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
        }
        $this->entry->refresh();
        Notification::make()->title('Status saved: '.AdvisorySubmission::STATUSES[$this->entry->status])->body('The student was notified.')->success()->send();

        if ($next = $this->nextToReview()) {
            $this->redirect(static::getUrl(['record' => $this->record, 'submission' => $next]));
        }
    }

    /** The next student, by name, whose files still wait for a decision. */
    public function nextToReview(): ?int
    {
        return AdvisorySubmission::where('advisory_task_id', $this->record->id)
            ->where('status', 'submitted')
            ->whereKeyNot($this->entry->id)
            ->orderByRaw('student_name > ? desc', [$this->entry->student_name])
            ->orderBy('student_name')
            ->value('id');
    }

    public function canReview(): bool
    {
        return $this->entry->submitted_at !== null && auth()->user()->can('update', $this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')->label('All submissions')->icon('heroicon-o-arrow-left')->color('gray')
                ->url(fn () => ManageSubmissions::getUrl(['record' => $this->record])),
            Actions\Action::make('next')->label('Next to review')->icon('heroicon-o-arrow-right')->color('gray')
                ->visible(fn () => $this->nextToReview() !== null)
                ->url(fn () => static::getUrl(['record' => $this->record, 'submission' => $this->nextToReview()])),
        ];
    }
}
