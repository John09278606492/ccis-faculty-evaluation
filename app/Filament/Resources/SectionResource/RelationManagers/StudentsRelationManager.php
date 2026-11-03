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
        return $table->description('Upload this section’s list using IDs from Students.')
            ->emptyStateHeading('No students in this section yet')
            ->emptyStateDescription('Upload a student list or add students individually.')->columns([
                Tables\Columns\TextColumn::make('student_id')->label('ID No.')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Student')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('studentDepartment.code')->label('Department')->badge(),
            ])->headerActions([
                Tables\Actions\Action::make('uploadStudents')->label('Upload Students')->icon('heroicon-o-document-arrow-up')
                    ->extraAttributes(['data-guide' => 'section-upload-action'])
                    ->visible(fn () => $this->canManage() && auth()->user()->can('import_academic_students') && $this->getOwnerRecord()->is_active)
                    ->modalSubmitActionLabel('Check File')->form([
                        Forms\Components\Placeholder::make('uploadTour')->hiddenLabel()->content(fn () => view('components.guided-help', ['context' => 'section-upload', 'compact' => true])),
                        Forms\Components\Placeholder::make('guide')->label('Student list')->extraAttributes(['data-guide' => 'section-upload-instructions'])->content(fn () => 'Section: '.$this->getOwnerRecord()->name.'. IDs must already exist in Students. Names and sex are optional; supplied values must match.'),
                        Forms\Components\FileUpload::make('file')->label('Student File')->disk('local')->storeFiles(false)->maxSize(5120)->required()
                            ->extraFieldWrapperAttributes(['data-guide' => 'section-file'])
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                            ->helperText('CSV or Excel, up to 5 MB. Review the matched students before confirming.'),
                    ])->action(function (array $data) {
                        $file = is_array($data['file']) ? reset($data['file']) : $data['file'];
                        app(BackgroundTasks::class)->upload(auth()->user(), filament()->getTenant(), $file, 'section-review', 'Review students for '.$this->getOwnerRecord()->name, ['section_id' => $this->getOwnerRecord()->id]);
                        Notification::make()->success()->title('Section student review queued')->body('We will notify you when the file is ready to review.')->send();
                    }),
                Tables\Actions\Action::make('exampleCsv')->label('CSV Template')->color('gray')->icon('heroicon-o-arrow-down-tray')
                    ->url(fn () => route('sections.template', $this->getOwnerRecord()))->extraAttributes(['download' => 'section-students.csv', 'data-guide' => 'section-template']),
                Tables\Actions\Action::make('reviewUploads')->label('Review Uploads')->icon('heroicon-o-check-circle')->color('gray')
                    ->extraAttributes(['data-guide' => 'section-review-action'])
                    ->visible(fn () => $this->canManage() && auth()->user()->can('import_academic_students'))
                    ->url(fn () => BackgroundProcessing::getUrl()),
                Tables\Actions\ActionGroup::make([Tables\Actions\Action::make('addStudents')->label('Add Students')->color('gray')->icon('heroicon-o-user-plus')
                    ->visible(fn () => $this->canManage() && $this->getOwnerRecord()->is_active)->form([
                        Forms\Components\Select::make('ids')->label('Students')->multiple()->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search) => app(AcademicSections::class)->eligible(auth()->user(), filament()->getTenant(), $this->getOwnerRecord())
                                ->where(fn ($q) => $q->where('student_id', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%'))->limit(50)->get()->mapWithKeys(fn ($s) => [$s->id => $s->student_id.' · '.$s->name]))
                            ->getOptionLabelsUsing(fn (array $values) => app(AcademicSections::class)->eligible(auth()->user(), filament()->getTenant(), $this->getOwnerRecord())->whereKey($values)->get()->mapWithKeys(fn ($s) => [$s->id => $s->student_id.' · '.$s->name])),
                    ])->action(function (array $data) {
                        $count = app(AcademicSections::class)->add(auth()->user(), filament()->getTenant(), $this->getOwnerRecord(), $data['ids']);
                        Notification::make()->success()->title($count.' students added to section')->send();
                    })])->label('More')->icon('heroicon-o-ellipsis-horizontal')->button()->color('gray'),
            ])->actions([
                Tables\Actions\Action::make('remove')->label('Remove from Section')->icon('heroicon-o-user-minus')->color('danger')->requiresConfirmation()
                    ->modalDescription('Remove only this section membership. Existing teaching-class assignments and submitted evaluations are preserved.')
                    ->visible(fn () => $this->canManage())->action(fn (User $record) => app(AcademicSections::class)->remove(auth()->user(), filament()->getTenant(), $this->getOwnerRecord(), [$record->id])),
            ]);
    }
}
