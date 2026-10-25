<?php

namespace App\Filament\Pages;

use App\Models\CourseOffering;
use App\Models\FormSubmission;
use App\Services\EvaluationCopies;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;

class IndividualEvaluationCopies extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.individual-evaluation-copies';

    protected static ?string $title = 'Individual Evaluation Copies';

    protected static ?string $navigationGroup = 'Evaluation Reports';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationIcon = 'heroicon-o-printer';

    public static function canAccess(): bool
    {
        return auth()->user() && filament()->getTenant() && app(EvaluationCopies::class)->allowed(auth()->user(), filament()->getTenant());
    }

    private function preview(array $ids)
    {
        $url = app(EvaluationCopies::class)->previewUrl(auth()->user(), filament()->getTenant(), $ids);

        return view('reports.preview-frame', ['url' => $url, 'title' => 'Submitted Annex A / B Copies', 'hint' => 'One completed copy per response. Identity is hidden by default. Use the identity option only when authorized. Print / Save PDF prints this selection only.']);
    }

    public function table(Table $table): Table
    {
        return $table->query(app(EvaluationCopies::class)->query(filament()->getTenant())->with(['form', 'courseOffering.evaluatee', 'courseOffering.course']))
            ->columns([
                Tables\Columns\TextColumn::make('courseOffering.evaluatee.name')->label('Faculty')->searchable(),
                Tables\Columns\TextColumn::make('form.name')->label('Instrument')->wrap(),
                Tables\Columns\TextColumn::make('courseOffering.course.code')->label('Course'),
                Tables\Columns\TextColumn::make('respondent')->label('Anonymous Evaluator')->state(function (FormSubmission $record) {
                    return $record->form->isSupervisorInstrument() ? 'Supervisor' : 'Student '.$record->newQuery()->where('form_id', $record->form_id)->where('course_offering_id', $record->course_offering_id)->where('id', '<=', $record->id)->count();
                }),
            ])->filters([
                Tables\Filters\SelectFilter::make('form_id')->label('Instrument')->options(fn () => filament()->getTenant()->forms()->whereIn('settings->annex', ['A', 'B'])->pluck('name', 'forms.id')),
                Tables\Filters\SelectFilter::make('course_offering_id')->label('Evaluation Assignment')->options(fn () => CourseOffering::whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', filament()->getTenant()->id))->with(['evaluatee', 'course'])->get()->mapWithKeys(fn ($a) => [$a->id => $a->evaluatee->name.' · '.$a->course?->code.' · '.$a->section])),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(['default' => 1, 'md' => 2])
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
            ])->defaultSort('id')->emptyStateHeading('No submitted Annex A/B evaluations');
    }
}
