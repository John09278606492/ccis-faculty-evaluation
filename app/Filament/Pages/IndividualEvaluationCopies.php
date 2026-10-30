<?php

namespace App\Filament\Pages;

use App\Models\CourseOffering;
use App\Models\FormSubmission;
use App\Services\AcademicPeriod;
use App\Services\EvaluationCopies;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class IndividualEvaluationCopies extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.individual-evaluation-copies';
    protected static ?string $title = 'Individual Evaluation Copies';
    protected static ?string $navigationGroup = 'Evaluation Reports';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationIcon = 'heroicon-o-printer';

    #[Url]
    public ?int $faculty = null;

    #[Url]
    public ?string $period = null;

    public static function canAccess(): bool
    {
        return auth()->user() && filament()->getTenant() && app(EvaluationCopies::class)->allowed(auth()->user(), filament()->getTenant());
    }

    public function mount(?int $faculty = null): void
    {
        $this->faculty = $faculty ?? $this->faculty;
        if ($this->faculty) {
            abort_unless(app(EvaluationCopies::class)->faculties(filament()->getTenant(), auth()->user())->where('users.id', $this->faculty)->exists(), 404);
        }
    }

    public function getSubheading(): ?string
    {
        return $this->faculty
            ? app(EvaluationCopies::class)->faculties(filament()->getTenant(), auth()->user())->where('users.id', $this->faculty)->value('users.name')
            : 'Choose an instructor to view and print their submitted copies.';
    }

    protected function getHeaderActions(): array
    {
        return $this->faculty ? [Action::make('back')->label('All Instructors')->icon('heroicon-o-arrow-left')->color('gray')
            ->url(fn () => static::getUrl(['period' => $this->semester() ?? 'all']))] : [];
    }

    private function semester(): ?int
    {
        $value = $this->getTableFilterState('semester_id')['value'] ?? null;

        return filled($value) ? (int) $value : null;
    }

    private function responses()
    {
        return app(EvaluationCopies::class)->responses(filament()->getTenant(), auth()->user(), $this->faculty, $this->semester());
    }

    private function periodFilter(): Tables\Filters\SelectFilter
    {
        return Tables\Filters\SelectFilter::make('semester_id')->label('Academic Period')
            ->options(fn () => AcademicPeriod::options(filament()->getTenant()))
            ->default(fn () => $this->period === 'all' ? null : ($this->period ?? AcademicPeriod::current(filament()->getTenant())))
            // Apply before aggregation, so counts match the displayed period.
            ->query(fn (Builder $query) => $query);
    }

    private function preview(array $ids)
    {
        $url = app(EvaluationCopies::class)->previewUrl(auth()->user(), filament()->getTenant(), $ids);

        return view('reports.preview-frame', ['url' => $url, 'title' => 'Submitted Annex A / B Copies', 'hint' => 'Identity is hidden by default. Print / Save PDF prints only this selection.']);
    }

    public function table(Table $table): Table
    {
        if (! $this->faculty) {
            return $table->query(fn () => app(EvaluationCopies::class)->faculties(filament()->getTenant(), auth()->user(), $this->semester()))
                ->columns([
                    Tables\Columns\TextColumn::make('name')->label('Instructor')->searchable()->sortable()
                        ->description(fn ($record) => $record->trashed() ? 'Archived account' : null),
                    Tables\Columns\TextColumn::make('student_count')->label('Student Responses')->numeric()->sortable(),
                    Tables\Columns\TextColumn::make('supervisor_count')->label('Supervisor Responses')->numeric()->sortable(),
                    Tables\Columns\TextColumn::make('response_count')->label('Total Copies')->numeric()->sortable()->badge(),
                ])->filters([$this->periodFilter()], layout: FiltersLayout::AboveContent)
                ->actions([
                    Tables\Actions\Action::make('viewCopies')->label('View Copies')->icon('heroicon-o-document-duplicate')
                        ->url(fn ($record) => static::getUrl(['faculty' => $record->id, 'period' => $this->semester() ?? 'all'])),
                ])->recordUrl(fn ($record) => static::getUrl(['faculty' => $record->id, 'period' => $this->semester() ?? 'all']))
                ->defaultSort('name')->emptyStateHeading('No submitted evaluation copies');
        }

        return $table->query(fn () => $this->responses()->with(['form', 'courseOffering.course', 'courseOffering.semester.schoolYear']))
            ->columns([
                Tables\Columns\TextColumn::make('respondent')->label('Evaluator')->state(fn (FormSubmission $record) => $record->form->isSupervisorInstrument()
                    ? 'Supervisor' : 'Student '.$record->newQuery()->where('form_id', $record->form_id)->where('course_offering_id', $record->course_offering_id)->where('id', '<=', $record->id)->count()),
                Tables\Columns\TextColumn::make('form.name')->label('Instrument')
                    ->formatStateUsing(fn (FormSubmission $record) => $record->form->isSupervisorInstrument() ? 'Annex B · Supervisor' : 'Annex A · Student')
                    ->tooltip(fn (FormSubmission $record) => $record->form->name),
                Tables\Columns\TextColumn::make('courseOffering.course.code')->label('Subject')->placeholder('—'),
                Tables\Columns\TextColumn::make('response_section')->label('Section')
                    ->state(fn (FormSubmission $record) => data_get($record->data, '_evaluation_context.section') ?: $record->courseOffering?->section)
                    ->placeholder('—'),
            ])->filters([
                $this->periodFilter(),
                Tables\Filters\SelectFilter::make('form_id')->label('Instrument')->options(fn () => filament()->getTenant()->forms()
                    ->whereIn('forms.id', $this->responses()->select('form_submissions.form_id'))->pluck('name', 'forms.id')),
                Tables\Filters\SelectFilter::make('course_offering_id')->label('Teaching Class')->options(fn () => CourseOffering::whereIn('id', $this->responses()->select('form_submissions.course_offering_id'))
                    ->with('course')->get()->mapWithKeys(fn ($offering) => [$offering->id => ($offering->course?->code ?? 'Supervisor evaluation').' · '.$offering->section])),
            ], layout: FiltersLayout::AboveContent)->filtersFormColumns(['default' => 1, 'md' => 3])
            ->headerActions([
                Tables\Actions\Action::make('printAll')->label('Print All Filtered Copies')->icon('heroicon-o-printer')
                    ->visible(fn () => $this->getFilteredTableQuery()->exists())
                    ->modalHeading('Print All Filtered Copies')->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn () => $this->preview($this->getFilteredTableQuery()->pluck('form_submissions.id')->all())),
            ])->actions([
                Tables\Actions\Action::make('printCopy')->label('Print Copy')->icon('heroicon-o-printer')
                    ->modalHeading('Individual Evaluation Copy')->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn ($record) => $this->preview([$record->id])),
            ])->bulkActions([
                Tables\Actions\BulkAction::make('printSelected')->label('Print Selected Copies')->icon('heroicon-o-printer')
                    ->modalHeading('Print Selected Copies')->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn ($records) => $this->preview($records->modelKeys())),
            ])->defaultSort('id')->emptyStateHeading('No copies match these filters');
    }
}
