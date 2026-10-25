<?php

namespace App\Filament\Resources\SectionResource\RelationManagers;

use App\Filament\Pages\BackgroundProcessing;
use App\Filament\Resources\SectionResource;
use App\Models\User;
use App\Services\AcademicSections;
use App\Services\BackgroundTasks;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StudentsRelationManager extends RelationManager
{
    protected static string $relationship = 'students';

    protected static ?string $title = 'Students';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return SectionResource::canView($ownerRecord);
    }

    private function canManage(): bool
    {
        return SectionResource::canEdit($this->getOwnerRecord());
    }

    public function table(Table $table): Table
    {
        return $table->description('Upload this section’s student list, review it in Background Processing, then Confirm Section Students. The saved list can then be assigned to a faculty member in Teaching Classes.')
            ->emptyStateHeading('No students in this section yet')
            ->emptyStateDescription('Creating a section does not add students. Download the sample CSV, upload your list, then confirm its background review.')->columns([
                Tables\Columns\TextColumn::make('student_id')->label('ID No.')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Student')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('studentDepartment.code')->label('Department')->badge(),
            ])->headerActions([
                Tables\Actions\Action::make('exampleCsv')->label('Download Student List CSV')->icon('heroicon-o-arrow-down-tray')
                    ->url(fn () => route('sections.template', $this->getOwnerRecord()))->extraAttributes(['download' => 'section-students.csv']),
                Tables\Actions\Action::make('uploadStudents')->label('Upload Section Students')->icon('heroicon-o-document-arrow-up')
                    ->visible(fn () => $this->canManage() && auth()->user()->can('import_academic_students') && $this->getOwnerRecord()->is_active)
                    ->modalSubmitActionLabel('Start Background Review')->form([
                        Forms\Components\Placeholder::make('guide')->label('Student list')->content(fn () => 'Section: '.$this->getOwnerRecord()->name.'. Only the ID No. column is required; each ID must already be in the Student Master List, and the review shows the masterlist name for every ID so you can check it before confirming. Lastname, Firstname, Middlename and Sex columns are optional; if included, they must match the masterlist. A section heading above the columns is accepted. If the file contains a section heading/column, it must match this section. Validation checks the existing masterlist; no student accounts are created.'),
                        Forms\Components\FileUpload::make('file')->label('CSV or Excel student list')->disk('local')->storeFiles(false)->maxSize(5120)->required()
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                            ->helperText('Continue working after upload. Open the notification to review and confirm adding the matched students to this section.'),
                    ])->action(function (array $data) {
                        $file = is_array($data['file']) ? reset($data['file']) : $data['file'];
                        app(BackgroundTasks::class)->upload(auth()->user(), filament()->getTenant(), $file, 'section-review', 'Review students for '.$this->getOwnerRecord()->name, ['section_id' => $this->getOwnerRecord()->id]);
                        Notification::make()->success()->title('Section student review queued')->body('Review the notification when ready, then confirm. No teaching-class evaluator lists are changed.')->send();
                    }),
                Tables\Actions\Action::make('reviewUploads')->label('Review / Confirm Uploads')->icon('heroicon-o-check-circle')->color('gray')
                    ->visible(fn () => $this->canManage() && auth()->user()->can('import_academic_students'))
                    ->url(fn () => BackgroundProcessing::getUrl()),
                Tables\Actions\Action::make('addStudents')->label('Add Individual Students')->icon('heroicon-o-user-plus')
                    ->visible(fn () => $this->canManage() && $this->getOwnerRecord()->is_active)->form([
                        Forms\Components\Select::make('ids')->label('Existing masterlist students')->multiple()->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search) => app(AcademicSections::class)->eligible(auth()->user(), filament()->getTenant(), $this->getOwnerRecord())
                                ->where(fn ($q) => $q->where('student_id', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%'))->limit(50)->get()->mapWithKeys(fn ($s) => [$s->id => $s->student_id.' · '.$s->name]))
                            ->getOptionLabelsUsing(fn (array $values) => app(AcademicSections::class)->eligible(auth()->user(), filament()->getTenant(), $this->getOwnerRecord())->whereKey($values)->get()->mapWithKeys(fn ($s) => [$s->id => $s->student_id.' · '.$s->name])),
                    ])->action(function (array $data) {
                        $count = app(AcademicSections::class)->add(auth()->user(), filament()->getTenant(), $this->getOwnerRecord(), $data['ids']);
                        Notification::make()->success()->title($count.' students added to section')->send();
                    }),
            ])->actions([
                Tables\Actions\Action::make('remove')->label('Remove from Section')->icon('heroicon-o-user-minus')->color('danger')->requiresConfirmation()
                    ->modalDescription('Remove only this section membership. Existing teaching-class assignments and submitted evaluations are preserved.')
                    ->visible(fn () => $this->canManage())->action(fn (User $record) => app(AcademicSections::class)->remove(auth()->user(), filament()->getTenant(), $this->getOwnerRecord(), [$record->id])),
            ]);
    }
}
