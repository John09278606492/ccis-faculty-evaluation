<?php

namespace App\Services;

use App\Filament\Pages\BackgroundProcessing;
use App\Filament\Pages\EvaluationResults;
use App\Filament\Pages\IndividualEvaluationCopies;
use App\Filament\Pages\MyEvaluationResults;
use App\Filament\Pages\MyEvaluations;
use App\Filament\Pages\StudentEvaluationProgress;
use App\Filament\Pages\SupervisorEvaluations;
use App\Filament\Resources\AcademicStudentResource;
use App\Filament\Resources\EvaluationCycleResource;
use App\Filament\Resources\FormResource;
use App\Filament\Resources\SectionResource;
use App\Filament\Resources\TeachingClassResource;
use JibayMcs\FilamentTour\Tour\Step;
use JibayMcs\FilamentTour\Tour\Tour;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Explanations only: a tour never executes an import, publication or record change. */
class GuidedHelp
{
    public function forCurrentPage(): ?string
    {
        // Header actions are rendered again by live updates. Resolve the original
        // page instead of the shared Livewire update endpoint on those requests.
        $route = request()->route()?->getName();
        if (Livewire::isLivewireRequest()) {
            try {
                $route = app('router')->getRoutes()->match(Request::create(Livewire::originalUrl()))->getName();
            } catch (NotFoundHttpException) {
                return null;
            }
        }

        return $this->forRoute($route);
    }

    public function allowed(string $context): bool
    {
        $user = auth()->user();
        $campus = filament()->getTenant();
        if (! $user || ! $campus || ! $user->canAccessTenant($campus)) {
            return false;
        }

        return match ($context) {
            'workspace' => true,
            'master-list' => AcademicStudentResource::canViewAny(),
            'master-upload' => AcademicStudentResource::canViewAny() && $user->can('manage_academic_students') && $user->can('import_academic_students'),
            'sections', 'section-roster' => SectionResource::canViewAny(),
            'section-details' => SectionResource::canCreate(),
            'section-upload' => SectionResource::canCreate() && $user->can('import_academic_students'),
            'teaching-classes', 'class-details' => ! $user->isStudent() && TeachingClassResource::canViewAny(),
            'assign-evaluators' => ! $user->isStudent() && TeachingClassResource::canViewAny() && $user->can('manage_evaluators_course::offering'),
            'offering-upload' => app(InstructorOfferingImports::class)->allowed($user, $campus),
            'evaluation-setup' => EvaluationCycleResource::canViewAny(),
            'forms' => FormResource::canViewAny(),
            'background' => BackgroundProcessing::canAccess(),
            'results' => EvaluationResults::canAccess(),
            'progress' => StudentEvaluationProgress::canAccess(),
            'copies' => IndividualEvaluationCopies::canAccess(),
            'supervisor-tasks' => SupervisorEvaluations::canAccess(),
            'my-results' => MyEvaluationResults::canAccess(),
            'my-evaluations' => $user->isStudent() && MyEvaluations::canAccess(),
            default => false,
        };
    }

    public function forRoute(?string $route): ?string
    {
        $context = match (true) {
            str_ends_with($route ?? '', '.resources.academic-students.index') => 'master-list',
            str_contains($route ?? '', '.resources.sections.') => match (true) {
                str_ends_with($route, '.view') => 'section-roster',
                str_ends_with($route, '.create'), str_ends_with($route, '.edit') => 'section-details',
                default => 'sections',
            },
            str_contains($route ?? '', '.resources.teaching-classes.') => str_ends_with($route, '.index') ? 'teaching-classes' : 'class-details',
            str_contains($route ?? '', '.resources.evaluation-cycles.') => 'evaluation-setup',
            str_contains($route ?? '', '.resources.forms.') => 'forms',
            str_ends_with($route ?? '', '.pages.background-tasks') => 'background',
            str_ends_with($route ?? '', '.pages.evaluation-results') => 'results',
            str_ends_with($route ?? '', '.pages.student-evaluation-progress') => 'progress',
            str_ends_with($route ?? '', '.pages.individual-evaluation-copies') => 'copies',
            str_ends_with($route ?? '', '.pages.supervisor-evaluations') => 'supervisor-tasks',
            str_ends_with($route ?? '', '.pages.my-evaluation-results') => 'my-results',
            str_ends_with($route ?? '', '.pages.my-evaluations') => 'my-evaluations',
            str_ends_with($route ?? '', '.pages.dashboard') => 'workspace',
            default => null,
        };

        return $context && $this->allowed($context) ? $context : null;
    }

    public function title(string $context): string
    {
        return match ($context) {
            'workspace' => 'Find your next step',
            'master-list', 'master-upload' => 'Upload the campus student master list',
            'sections', 'section-details', 'section-roster', 'section-upload' => 'Prepare students by section',
            'teaching-classes', 'class-details', 'assign-evaluators' => 'Assign the right evaluators',
            'offering-upload' => 'Import instructor offerings',
            'evaluation-setup' => 'Prepare and open an evaluation',
            'background' => 'Review uploads and confirm changes',
            'forms' => 'Choose and prepare evaluation forms',
            'progress' => 'Follow up on incomplete evaluations',
            'supervisor-tasks' => 'Complete your assigned Annex B evaluations',
            'copies' => 'Print selected individual responses',
            'my-evaluations' => 'Complete your assigned faculty evaluations',
            'my-results' => 'Read your released evaluation results',
            default => 'Review and print evaluation results',
        };
    }

    public function tour(string $context): Tour
    {
        abort_unless($this->allowed($context), 403);
        $steps = $this->steps($context);

        return Tour::make('campus-'.$context)
            // The upstream tenant route resolver assumes a slug. Our campus uses IDs.
            // A manual-only route also prevents tours from interrupting ongoing work.
            ->route('/__manual_campus_guide__')->disableEvents()
            ->colors('#21113d', '#21113d')
            ->nextButtonLabel('Next')->previousButtonLabel('Back')->doneButtonLabel('Finish guide')
            ->steps(...array_map(fn (array $step, int $i) => Step::make($step[0] ?? '[data-guided-help="'.$context.'"] button')
                ->title(($i + 1).' / '.count($steps).' · '.$step[1])->description($step[2]), $steps, array_keys($steps)));
    }

    private function steps(string $context): array
    {
        $table = '.fi-ta';
        $intro = [null, $this->title($context), 'Use Next and Back to explore. Close with the × button or Escape at any time. This guide only explains the screen; it never saves or submits anything.'];
        $finishUpload = [null, 'Review, confirm, then wait for completion', 'Uploading starts validation in the background. Open the notification bell or Uploads & Downloads, choose Review, correct any errors, then confirm. Wait for the import-complete notification before using the new students.'];

        $steps = match ($context) {
            'workspace' => [
                ['[data-guide="workspace"]', 'Check campus and academic period', 'All work follows your assigned campus and departments. Select the correct academic period before uploading or preparing evaluations.'],
                [null, 'Follow the evaluation workflow', auth()->user()->isStudent()
                    ? 'Open My Evaluations to see the faculty you are assigned to evaluate. Complete each required rating and submit once you have reviewed your answers.'
                    : 'Prepare Students → Sections → Teaching Classes → Evaluations → Results. Your menu shows only the work your permissions allow.'],
                ['.fi-topbar-database-notifications-btn', 'Check background notifications', 'Uploads are validated and imported in the background. A completed review still needs your confirmation; check the final completion notification too.'],
            ],
            'master-list' => [
                ['[data-guide="workspace"]', 'Select the academic period', 'Import enrolments into the correct semester and school year. Create the academic period first if none is available.'],
                ['[data-guide="master-template"]', 'Get an example if you need one', 'CSV Template shows the supported columns. The original campus Excel report is also accepted. Keep student IDs as text so leading zeros are preserved.'],
                ['[data-guide="master-upload-action"]', 'Open Upload Students', 'Choose a CSV or Excel file, check the academic period and allowed departments, then select Check File. Use Guide me inside the upload window for field-by-field help.'],
                $finishUpload,
                [null, 'Next: check teaching classes', 'Clear subject matches are assigned automatically after import. Open Teaching Classes and choose Section students for subjects with multiple instructors. Choose the instructor and section there; no back-and-forth navigation is needed.'],
            ],
            'master-upload' => [
                ['[data-guide="master-period"]', 'Check the academic period', 'Choose the semester this file belongs to. Students and subject enrolments will be validated for this period.'],
                ['[data-guide="master-departments"]', 'Check the department selection', 'Choose the existing departments you intend to import. Leave empty to include all allowed departments. New departments and courses are proposed for review only if your permissions allow their creation.'],
                ['[data-guide="master-file"]', 'Upload the original file unchanged', 'Accepted: CSV, XLS or XLSX, up to 5 MB. The original campus report is supported without filtering or rearranging its columns. Unrelated columns and worksheets are ignored. IDs and enrolled-subject blocks are read automatically; the example CSV is optional.'],
                [null, 'Check File', 'Close this guide, select the file and click Check File. Nothing is imported yet. You can continue working while validation checks identities, duplicates, departments and subjects.'],
                $finishUpload,
            ],
            'sections' => [
                ['.fi-header-actions', 'Create a saved section', 'Use Add Section. Choose the department and academic period, and enter a clear name such as BSIT 3A. Creating a section starts an empty student list.'],
                [$table, 'Open Students', 'Open the section’s student list. Download its CSV example and upload only the students who belong in that section.'],
                [null, 'Confirm the section review', 'Review the matched master-list names in Uploads & Downloads, then Confirm Import. A student may belong to more than one section, including irregular students.'],
                [null, 'Next: assign the saved section', 'Open Teaching Classes and choose Section students beside the subject. Select its instructor and saved section, then choose enrolled students or upload that section’s ID list in the same window.'],
            ],
            'section-details' => [
                ['.fi-main form', 'Choose the period, department and name', 'Use the academic period and department for this section. Include program, year and section in the name, for example BSIT 3A. A separate year-level field is not needed.'],
                [null, 'Save before uploading students', 'Create or save the section, then return to Sections and open Students. Download that section’s CSV and upload its student list.'],
                [null, 'Confirm the roster before using it', 'Review matched master-list identities in Uploads & Downloads and Confirm Import. Wait for completion, then assign the saved section under Teaching Classes.'],
            ],
            'section-roster' => [
                ['[data-guide="section-template"]', 'Download this section’s CSV', 'Only ID No. is required. Lastname, Firstname and Middlename are optional but must match the master list when provided. Sex is optional. Keep ID numbers as text.'],
                ['[data-guide="section-upload-action"]', 'Upload Students', 'Upload a CSV or Excel list for this section. If the file includes a section heading, it must match this section. Students must already exist in the confirmed master list.'],
                ['[data-guide="section-review-action"]', 'Review and confirm', 'Open the completed review, verify every matched ID and name, correct any errors and re-upload if needed, then Confirm Import. Wait for completion before assigning this section.'],
                [$table, 'Check the saved roster', 'Confirmed students appear here. Adding or removing a section membership does not automatically rewrite existing teaching-class evaluator assignments. Review those assignments separately.'],
            ],
            'section-upload' => [
                ['[data-guide="section-upload-instructions"]', 'Check which section you are uploading', 'This window belongs to the section you opened. The section heading in the file, if present, must match. Confirm the academic period on the section too.'],
                ['[data-guide="section-file"]', 'Upload the student IDs', 'CSV, XLS and XLSX are accepted, up to 5 MB. ID No. is required. Names and Sex are optional; supplied values must match the master list. Unknown IDs are validation errors, not new accounts.'],
                [null, 'Check File', 'Close the guide, choose the file and start the review. You can leave the page while it validates.'],
                $finishUpload,
            ],
            'teaching-classes', 'class-details' => [
                [$context === 'teaching-classes' ? '.fi-header-actions' : '.fi-main form', 'Import or add teaching classes', 'Use Import Offering File for the original campus Excel or CSV, or Add Teaching Class for one class. Clear enrolled-subject matches are assigned automatically by default.'],
                [$context === 'teaching-classes' ? '.fi-ta' : '[data-guide="match-enrolled-students"]', 'Section students here', 'Subjects with multiple instructors appear first and require sectioning. Choose Section students, select the instructor and section, then pick enrolled students or upload the section ID list. Use + to create a section in the same window.'],
                [null, 'Check the result in this window', 'Assignment runs in the background. Validation issues and completion appear in the same window and the notification bell. Choose Assign another section to continue. Single-instructor subjects can use Section pending; multi-instructor subjects require a confirmed section before evaluation.'],
                [null, 'Choose supervisors', 'Use Department Supervisor in the evaluation review to fill missing assignments in bulk. Existing choices are kept. Teaching Classes → Supervisors lets you replace or add one or more supervisors for an individual faculty member.'],
                [null, 'Next: Evaluations', 'Include the prepared teaching classes in an evaluation setup. Choose Annex A/B, then Review & Open. Dates are optional: leave Opens empty to start when opened, and Closes empty to close it manually.'],
            ],
            'offering-upload' => [
                ['[data-guide="offering-period"]', 'Choose the academic period', 'Only rows matching this semester and school year are reviewed. Historical rows are left out.'],
                ['[data-guide="offering-file"]', 'Upload the original offering file', 'Upload the original Excel or CSV export unchanged. Relevant columns and the selected academic period are detected automatically; extra columns, unrelated worksheets and repeated headings are ignored. The example CSV is optional.'],
                [null, 'Check faculty and section matches', 'Missing faculty can be prepared as profiles without sign-in details. Optional Matches links an existing faculty account or resolves a program or section name.'],
                [null, 'Review before importing', 'Choose Check File and continue working. Open the notification, inspect the review and select Import Ready Classes. Classes needing attention are excluded. Choose supervisors and open an evaluation afterward.'],
            ],
            'assign-evaluators' => [
                ['[data-guide="evaluator-sections"]', 'Choose confirmed sections', 'Select the sections this faculty actually teaches. Empty sections need their student lists uploaded and confirmed first.'],
                ['[data-guide="evaluator-exceptions"]', 'Optional individual exceptions', 'Enable this only to add irregular students or exclude students who do not attend. Students are not selected automatically.'],
                ['[data-guide="evaluator-supervisors"]', 'Choose Annex B evaluators', 'Select the supervisor or supervisors for this faculty. Deans and chairpersons must be selected explicitly.'],
                [null, 'Review changes, then save', 'Close the guide, finish your selection and click Next. Check additions, removals and supervisor names on Review changes before Save Evaluators. Submitted responses remain preserved.'],
            ],
            'evaluation-setup' => [
                ['.fi-main form', 'Include prepared classes automatically', 'Choose the department and academic period. Configured forms and an evaluation name are suggested. Include all prepared classes, select faculty once for all their classes, or use the optional searchable custom list. Dates are optional.'],
                ['.fi-header-actions', 'Prepare departments or resolve exceptions in bulk', 'Prepare Department Evaluations creates drafts for selected departments in the background. Inside a draft, Use Department Supervisor fills missing defaults. Resolve Missing Supervisors groups any remaining choices by faculty.'],
                [null, 'Review & Open before opening', 'Check the current class counts and any exclusions, then confirm opening. Automatic draft selection refreshes when saved or reviewed. After opening, use Add Newly Prepared Classes to preview and add later offerings in bulk. Existing responses are preserved.'],
                [null, 'Students and supervisors have different entry points', 'Students enter their student ID. Selected supervisors use their staff account and My Supervisor Tasks. No evaluation PIN or sign-on approval is needed.'],
                [null, 'Monitor and close', 'Student Progress shows completion; Results & Reports shows submitted ratings. A configured closing date is enforced automatically. With Closes empty, use Close Evaluation when finished. Existing results are kept.'],
            ],
            'background' => [
                [$table, 'Check operation status', 'Queued means waiting; Processing means work is running; Completed means this operation finished. A completed review still needs confirmation before an import begins.'],
                ['[data-guide="background-review"]', 'Open Review first', 'Check the matched students, warnings and proposed departments or courses. If validation errors appear, correct your source file and upload again.'],
                ['[data-guide="background-confirm"]', 'Confirm the reviewed changes', 'The confirmation action appears only when the review has no blocking errors and has not already been confirmed. It starts a separate background import.'],
                [null, 'Check the final import result', 'Wait for the import-complete notification or inspect the new operation. If an operation failed, open its details. Downloads are private and expire after seven days.'],
            ],
            'forms' => [[null, 'Use the approved templates', 'Annex A is the student instrument; Annex B is the supervisor instrument. Duplicate a template to customize a new form. Avoid changing forms already used for submitted evaluations.'], [null, 'Preview before opening evaluations', 'Check sections, criteria, rating labels and the overall comment field in Preview. Select the finished instruments in Evaluations.']],
            'results' => [[$table, 'Choose the correct faculty and filters', 'Select the academic period and evaluation setup first. Results include submitted evaluations; students who did not submit are not counted as zero ratings.'], [null, 'Choose a report in the preview', 'Use the report action to choose Scores, Tally or Annex C / D. SET and SEF stay separate; SEF comes from submitted supervisor Annex B evaluations.'], [null, 'Preview, then print', 'Check the report and signatories in the in-system preview before Print / Save PDF. Evaluator identities remain hidden unless a specifically authorized identity option is enabled.']],
            'my-results' => [[$table, 'Read released scores', 'Your results appear only after the evaluation closes and the response threshold is met. Use the assignment filter to narrow the list.'], [null, 'Open View Scores', 'View Scores opens an anonymous score breakdown. Student identities and private comments are not shown here.']],
            'my-evaluations' => [[$table, 'Find a pending evaluation', 'Check the faculty, course and academic period. Only evaluations assigned to you are listed. Completed means you have already submitted.'], [null, 'Rate, review and submit', 'Open a pending evaluation, answer every required criterion and add an overall comment if needed. Review your answers before submitting. You do not need a quiz PIN or sign-on approval.']],
            'progress' => [[$table, 'Filter by department, period and section', 'Use the section view to find incomplete groups, then inspect the students who still have remaining evaluations. Your campus and department permissions still apply.'], [null, 'Completion is different from ratings', 'Completed / assigned counts help follow-up. This page does not reveal student ratings or comments. A non-submission never becomes a zero rating.']],
            'copies' => [[$table, 'Filter and select submitted responses', 'Choose Annex A or B and the evaluation assignment. Select the responses you need, or print all filtered copies.'], [null, 'Keep identities hidden when possible', 'Copies use anonymous Student or Supervisor labels by default. Only authorized users can choose to reveal identity.'], [null, 'Check the preview before printing', 'Preview the chosen responses in the system modal, then use Print / Save PDF. The selection controls which copies are printed.']],
            'supervisor-tasks' => [[$table, 'Find your assigned faculty', 'Only faculty explicitly assigned to you appear. Check the academic period and status. Being a dean or chairperson alone does not create a task.'], [null, 'Complete Annex B', 'Use Complete Annex B when the evaluation is open. Rate every required criterion, add an overall comment if needed, review and submit. Your response becomes part of the SEF report.']],
            default => [],
        };

        return [$intro, ...$steps];
    }
}
