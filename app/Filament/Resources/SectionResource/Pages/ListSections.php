<?php

namespace App\Filament\Resources\SectionResource\Pages;

use App\Filament\Concerns\ShowsEvaluationWorkflow;
use App\Filament\Pages\BackgroundProcessing;
use App\Filament\Resources\SectionResource;
use App\Services\AcademicPeriod;
use App\Services\AcademicSections;
use App\Services\BackgroundTasks;
use App\Services\TeachingClassRosterImports;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ListSections extends ListRecords
{
    use ShowsEvaluationWorkflow;

    protected static string $view = 'filament.resources.workflow-list';

    public function getSubheading(): ?string
    {
        return 'Create a section, then upload the students who belong to it.';
    }

    protected static string $resource = SectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bulkUploadStudents')->label('Upload Section Lists')->icon('heroicon-o-document-arrow-up')->color('gray')
                ->visible(fn () => SectionResource::canCreate() && auth()->user()->can('import_academic_students'))
                ->modalDescription('Each file starts with its section name (for example BS CRIM 2B), then an ID No. column. Every file is matched to its section and checked before anything is added.')
                ->modalSubmitActionLabel('Check Files')->form([
                    Forms\Components\Select::make('semester_id')->label('Academic Period')->required()
                        ->options(fn () => AcademicPeriod::options(filament()->getTenant()))->default(fn () => AcademicPeriod::current(filament()->getTenant())),
                    Forms\Components\FileUpload::make('files')->label('Section Student Lists')->multiple()->maxFiles(40)->disk('local')->storeFiles(false)->maxSize(5120)->required()
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->helperText('CSV or Excel, up to 5 MB each. Spacing and spelling like BS CRIM / BSCRIM / BSCRM are matched.'),
                ])->action(fn (array $data) => $this->queueSectionLists($data)),
            CreateAction::make()->label('Add Section'),
        ];
    }

    private function queueSectionLists(array $data): void
    {
        $actor = auth()->user();
        $campus = filament()->getTenant();
        $semesterId = AcademicPeriod::validate($campus, $data['semester_id']);
        $queued = $problems = [];
        foreach ((array) $data['files'] as $file) {
            $name = $file->getClientOriginalName();
            try {
                $extension = strtolower($file->getClientOriginalExtension());
                abort_unless(in_array($extension, ['csv', 'xlsx', 'xls'], true), 422, 'Use a CSV or Excel file.');
                $peek = app(TeachingClassRosterImports::class)->peek($file->getRealPath(), $extension);
                if (! $peek['title']) {
                    $problems[] = $name.': put the section name (for example BS CRIM 2B) in the first row, above ID No.';

                    continue;
                }
                $match = app(AcademicSections::class)->matchTitle($actor, $campus, $semesterId, $peek['title'], $peek['ids']);
                if (! $match['section']) {
                    $problems[] = $name.': '.$match['problem'];

                    continue;
                }
                app(BackgroundTasks::class)->upload($actor, $campus, $file, 'section-review', 'Review students for '.$match['section']->name, ['section_id' => $match['section']->id]);
                $queued[] = $peek['title'].' → '.$match['section']->name;
            } catch (Throwable $e) {
                $problems[] = $name.': '.match (true) {
                    $e instanceof ValidationException => collect($e->errors())->flatten()->first(), $e instanceof HttpException && $e->getMessage() !== '' => $e->getMessage(), default => 'the file could not be read.'
                };
            }
        }
        $lines = [...array_map(fn ($line) => '✓ '.e($line), $queued), ...array_map(fn ($line) => '⚠ '.e($line), $problems)];
        Notification::make()->{$problems ? 'warning' : 'success'}()
            ->title(count($queued).' of '.(count($queued) + count($problems)).' lists queued for review')
            ->body(new HtmlString(implode('<br>', $lines).($queued ? '<br>Open Review Uploads to confirm each section.' : '')))
            ->actions($queued ? [NotificationAction::make('review')->label('Review Uploads')->url(BackgroundProcessing::getUrl())] : [])
            ->persistent()->send();
    }
}
