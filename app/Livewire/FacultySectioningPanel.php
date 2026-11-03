<?php

namespace App\Livewire;

use App\Models\BackgroundTask;
use App\Models\Campus;
use App\Services\BackgroundTasks;
use App\Services\FacultySectioning;
use App\Services\TeachingClassRosterImports;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Livewire\Attributes\Locked;
use Livewire\Component;

class FacultySectioningPanel extends Component implements HasForms
{
    use InteractsWithForms;

    #[Locked]
    public int $anchorId;

    #[Locked]
    public int $campusId;

    #[Locked]
    public ?string $operationId = null;

    public ?array $data = [];

    public string $operationStatus = '';

    public array $operationResult = [];

    public ?string $operationError = null;

    private $cachedAnchor = null;

    private $cachedStudents = null;

    private function campus(): Campus
    {
        return Campus::findOrFail($this->campusId);
    }

    private function anchor()
    {
        return $this->cachedAnchor ??= app(FacultySectioning::class)->anchor(auth()->user(), $this->campus(), $this->anchorId);
    }

    private function students()
    {
        return $this->cachedStudents ??= app(FacultySectioning::class)->candidates(auth()->user(), $this->campus(), $this->anchor());
    }

    public function mount(int $anchorId, int $campusId): void
    {
        $this->anchorId = $anchorId;
        $this->campusId = $campusId;
        $anchor = $this->anchor();
        $section = app(FacultySectioning::class)->sections(auth()->user(), $this->campus(), $anchor, $anchor->user_id)
            ->first(fn ($s) => TeachingClassRosterImports::section($s->name) === TeachingClassRosterImports::section($anchor->section ?? ''));
        $this->form->fill(['faculty_id' => $anchor->user_id, 'section_id' => $section?->id, 'mode' => 'pick', 'student_ids' => [], 'include_sectioned' => false, 'confirmed' => false]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->disabled(fn () => $this->operationId !== null)->schema([
            Forms\Components\Select::make('faculty_id')->label('Instructor')->required()->live()
                ->options(fn () => app(FacultySectioning::class)->group(auth()->user(), $this->campus(), $this->anchor())->unique('user_id')->mapWithKeys(fn ($c) => [$c->user_id => $c->evaluatee?->name]))
                ->afterStateUpdated(fn (Forms\Set $set) => $set('section_id', null)),
            Forms\Components\Select::make('section_id')->label('Section')->required()->searchable()->live()
                ->options(fn (Forms\Get $get) => app(FacultySectioning::class)->sections(auth()->user(), $this->campus(), $this->anchor(), (int) $get('faculty_id'))->pluck('name', 'id'))
                ->createOptionForm([Forms\Components\TextInput::make('name')->label('Section name')->placeholder('BSCRIM 4A')->required()->maxLength(20)])
                ->createOptionUsing(fn (array $data) => app(FacultySectioning::class)->createSection(auth()->user(), $this->campus(), $this->anchorId, $data['name']))
                ->helperText('Sections are filtered to this instructor. Use + to create one here.'),
            Forms\Components\Radio::make('mode')->hiddenLabel()->options(fn () => ['pick' => 'Choose enrolled students', ...(auth()->user()->can('import_academic_students') ? ['file' => 'Upload section ID list'] : [])])->default('pick')->inline()->live(),
            Forms\Components\Toggle::make('include_sectioned')->label('Include students already in sections')->default(false)->live()->visible(fn (Forms\Get $get) => $get('mode') === 'pick'),
            Forms\Components\CheckboxList::make('student_ids')->label('Enrolled students')->required(fn (Forms\Get $get) => $get('mode') === 'pick')
                ->helperText(fn () => $this->students()->isEmpty() ? 'No matching subject enrolments were found in the masterlist for this academic period. Check the student subject data before assigning this class.' : $this->students()->count().' students are enrolled in this subject. Already sectioned students are hidden unless you enable the switch above.')
                ->visible(fn (Forms\Get $get) => $get('mode') === 'pick')->searchable()->bulkToggleable()->columns(1)
                ->options(fn (Forms\Get $get) => $this->students()->filter(fn ($s) => $get('include_sectioned') || $s->studentSectionMemberships->isEmpty())
                    ->mapWithKeys(fn ($s) => [$s->id => $s->student_id.' - '.$s->name.($s->studentSectionMemberships->isNotEmpty() ? ' ('.$s->studentSectionMemberships->pluck('section')->join(', ').')' : '')]))
                ->extraAttributes(['style' => 'max-height:320px;overflow:auto;']),
            Forms\Components\FileUpload::make('file')->label('Section student list')->visible(fn (Forms\Get $get) => $get('mode') === 'file')
                ->required(fn (Forms\Get $get) => $get('mode') === 'file')->disk('local')->storeFiles(false)->maxSize(5120)
                ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                ->helperText('CSV or Excel with ID No. Names and Sex are optional. Every ID must exist in the masterlist and be enrolled in this subject. Invalid lists make no changes.'),
            Forms\Components\Placeholder::make('template')->hiddenLabel()->visible(fn (Forms\Get $get) => $get('mode') === 'file' && $get('section_id'))
                ->content(fn (Forms\Get $get) => view('filament.partials.sectioning-template', ['sectionId' => $get('section_id')])),
            Forms\Components\Checkbox::make('confirmed')->label('These students attend this instructor’s section.')->accepted(),
        ]);
    }

    public function assign(): void
    {
        if ($this->operationId) {
            return;
        }
        $data = $this->form->getState();
        $payload = ['anchor_id' => $this->anchorId, 'faculty_id' => (int) $data['faculty_id'], 'section_id' => (int) $data['section_id']];
        $tasks = app(BackgroundTasks::class);
        if ($data['mode'] === 'file') {
            $file = is_array($data['file']) ? reset($data['file']) : $data['file'];
            $task = $tasks->upload(auth()->user(), $this->campus(), $file, 'sectioning-import', 'Validate and assign subject section', $payload);
        } else {
            $task = $tasks->enqueue(auth()->user(), $this->campus(), 'sectioning-import', 'Assign enrolled students to subject section', [...$payload, 'student_ids' => $data['student_ids']]);
        }
        $this->operationId = $task->id;
        $this->operationStatus = $task->status;
    }

    public function refreshOperation(): void
    {
        if (! $this->operationId) {
            return;
        }
        $task = BackgroundTask::where('user_id', auth()->id())->where('campus_id', $this->campusId)->where('kind', 'sectioning-import')->findOrFail($this->operationId);
        app(BackgroundTasks::class)->authorizeTask($task, auth()->user(), $this->campus());
        $this->operationStatus = $task->status;
        $this->operationResult = $task->result ?? [];
        $this->operationError = $task->error;
    }

    public function anotherSection(): void
    {
        $this->refreshOperation();
        if (in_array($this->operationStatus, ['queued', 'processing'], true)) {
            return;
        }
        $facultyId = $this->data['faculty_id'] ?? $this->anchor()->user_id;
        $this->operationId = null;
        $this->operationStatus = '';
        $this->operationResult = [];
        $this->operationError = null;
        $this->form->fill(['faculty_id' => $facultyId, 'section_id' => null, 'mode' => 'pick', 'student_ids' => [], 'include_sectioned' => false, 'confirmed' => false]);
    }

    public function render()
    {
        return view('livewire.faculty-sectioning-panel', ['anchor' => $this->anchor(),
            'group' => app(FacultySectioning::class)->group(auth()->user(), $this->campus(), $this->anchor())]);
    }
}
