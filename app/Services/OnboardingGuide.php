<?php

namespace App\Services;

use App\Filament\Pages\AccessAssignments;
use App\Filament\Pages\BackgroundProcessing;
use App\Filament\Pages\EvaluationResults;
use App\Filament\Pages\IndividualEvaluationCopies;
use App\Filament\Pages\MyEvaluationResults;
use App\Filament\Pages\MyEvaluations;
use App\Filament\Pages\StudentEvaluationProgress;
use App\Filament\Pages\SupervisorEvaluations;
use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\AcademicStudentResource;
use App\Filament\Resources\CampusResource;
use App\Filament\Resources\DepartmentResource;
use App\Filament\Resources\EvaluationCycleResource;
use App\Filament\Resources\FormResource;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\SchoolYearResource;
use App\Filament\Resources\SectionResource;
use App\Filament\Resources\TeachingClassResource;
use App\Filament\Resources\UserResource;

/** Learning instructions only. Completing a checklist never changes academic records. */
class OnboardingGuide
{
    public function forCurrentUser(): array
    {
        $user = auth()->user();
        $campus = filament()->getTenant();
        abort_unless($user && $campus && $user->canAccessTenant($campus), 403);
        $student = $user->isStudent();
        $audience = $student ? 'Student' : ($user->isSuperAdmin() ? 'Owner / super administrator'
            : ($user->hasAnyRole(['campus_admin', 'admin']) ? 'Administrator'
                : ($user->hasAnyRole(['chairperson', 'dean']) ? 'Department leader' : 'Faculty / staff')));
        $steps = [];
        $add = function (string $id, string $title, string $why, array $instructions, string $done, ?string $url = null, ?string $context = null) use (&$steps) {
            $steps[] = compact('id', 'title', 'why', 'instructions', 'done', 'url', 'context');
        };
        $resource = fn (string $class) => ! $student && $class::canViewAny();
        $page = fn (string $class) => $class::canAccess();
        $link = fn (string $class) => $class::getUrl(tenant: $campus);
        $index = fn (string $class) => $class::getUrl('index', tenant: $campus);

        $add('workspace', 'Know your workspace', 'Start with the correct campus and academic period.', [
            'Check the campus and Academic period at the top of the screen before working.',
            $student ? 'Your assigned evaluations appear in My Evaluations. If a teacher is missing, ask your coordinator to check your enrolment and class assignment.'
                : 'Menus follow your account permissions. An unavailable menu requires an administrator to review your access; it is not a step you must work around.',
            'Use Guide me on a task page for an on-screen walkthrough. You can close it and replay it at any time.',
        ], 'You can identify your campus, period and next task.', null, 'workspace');

        if (! $student) {
            $add('account', 'Protect your staff account', 'Use your own account so changes and drafts belong to you.', [
                'Open your account menu and My Profile. Check your name and school email.',
                'Change any temporary password. Keep your credentials private and sign out on shared computers.',
                'Save important changes before leaving a page. A saved draft is not a submitted or published record; reselect uploaded files when resuming a draft.',
            ], 'Your account details and password are ready.');
        }
        if ($resource(CampusResource::class)) {
            $add('campuses', 'Check campus setup', 'Owner setup comes before importing records.', [
                'Open Campuses and confirm the real campus is active. Avoid creating a second copy of the same campus.',
                'Select the campus you will manage. Staff, periods and imports must use that campus.',
            ], 'You are working in the intended campus.', $index(CampusResource::class));
        }
        if ($resource(SchoolYearResource::class)) {
            $add('period', 'Set the school year and semester', 'Every roster and evaluation belongs to an academic period.', [
                'Open School Years. Create or check the school year and its semesters.',
                'Select the intended semester using Academic period before uploading files.',
                'Use a new period for a new term; preserve previous periods and their evaluation history.',
            ], 'The intended semester appears in the Academic period selector.', $index(SchoolYearResource::class));
        }
        if ($resource(DepartmentResource::class)) {
            $add('departments', 'Set department leadership and home faculty', 'A home department and a teaching department can be different.', [
                'Open Departments. Check each college or department and its assigned Dean / Department Head and Chairperson.',
                'Use Add instructors to assign faculty to their correct home department in bulk. A GenEd instructor can still teach courses under another college.',
                'Assigned leaders with the relevant permissions can review their home faculty across colleges. Student Progress follows the supervised teaching-class rosters and their sections.',
                'Results visibility does not create Annex B tasks. Check supervisor selections in Teaching Classes separately; a missing supervisor remains pending.',
            ], 'Faculty home departments and leadership match the actual organization.', $index(DepartmentResource::class));
        }
        if ($resource(UserResource::class)) {
            $add('staff', 'Prepare staff accounts', 'Give each colleague the access needed for their work.', [
                'Open Staff Accounts. Search for an existing account before creating another.',
                'Create or import staff, check their names and emails, and assign the correct campus, home department and role.',
                'Instructor offering imports may create missing instructor profiles. Review those profiles before creating duplicates or distributing sign-in instructions.',
            ], 'Each staff member has one correctly configured account.', $index(UserResource::class));
        }
        if ($resource(RoleResource::class)) {
            $add('roles', 'Review roles and permissions', 'A role controls available actions; department and campus assignments control the records.', [
                'Open Roles & Permissions and check the existing roles before changing permissions.',
                'Keep owner-level access limited to the people who administer the whole system.',
                'Give instructors their own work and results access. Give leaders the reporting and progress permissions needed to supervise their departments.',
            ], 'Roles match responsibilities without granting unnecessary administration access.', $index(RoleResource::class));
        }
        if (! $student && $page(AccessAssignments::class)) {
            $add('access', 'Check campus and department access', 'Permissions alone do not grant access to every campus or department.', [
                'Open Access & Assignments. Confirm each administrator or coordinator is assigned to the correct campus and scope.',
                'If a colleague cannot see a menu or record, check their role, campus and department assignments together.',
            ], 'Staff can access their intended workspace.', $link(AccessAssignments::class));
        }
        if ($resource(AcademicStudentResource::class)) {
            $add('students', 'Upload the student master list', 'Import the source enrolments before assigning evaluators.', [
                'Select the correct academic period. Open Students and download the upload example if you need to check the format.',
                'Upload the original supported CSV or Excel file. Review the detected columns and mappings; unrelated columns do not need to become student fields.',
                'Select Check File, open the review in Uploads & Downloads, and resolve unknown IDs or validation errors.',
                'Confirm Import and wait for its completion notification. A successful file check alone has not finished the import.',
            ], 'The imported students and their enrolments appear in the selected period.', $index(AcademicStudentResource::class), 'master-list');
        }
        if ($resource(TeachingClassResource::class)) {
            $add('classes', 'Prepare teaching classes and section students', 'Connect the correct students to the correct instructor.', [
                'Import instructor offerings in Teaching Classes, or review the existing classes for the selected period.',
                'Use Match Enrolled Students for clear subject matches. When several instructors teach the same subject, sectioning is required before students can evaluate.',
                'Use Section students on the class page. Select the instructor and section, then choose enrolled students or upload the section ID list in the same window.',
                'Review the matched names and confirm. Do not guess a section when a source list conflicts or an ID is unknown.',
                'Check the assigned evaluator count. Students can evaluate without a section for a single-instructor subject, but confirm their report sections before final reporting.',
            ], 'Each prepared class has the intended instructor, evaluator roster and section configuration.', $index(TeachingClassResource::class), 'teaching-classes');
        }
        if ($resource(SectionResource::class)) {
            $add('sections', 'Maintain saved section lists', 'Saved sections let you reuse a confirmed roster.', [
                'Open Sections and choose the same semester and department as the student list.',
                'Create the section if needed, open Students, and upload its IDs using the example format.',
                'Review the master-list matches, confirm the import, and wait for completion.',
                'Changing a saved section does not automatically rewrite every existing teaching-class roster. Review affected class assignments after a correction.',
            ], 'The saved section contains the confirmed students.', $index(SectionResource::class), 'sections');
        }
        if ($resource(FormResource::class)) {
            $add('forms', 'Check Annex A and Annex B', 'Use the correct instrument for each evaluator type.', [
                'Annex A is Student Evaluation of Teachers (SET). Annex B is Supervisor Evaluation of Faculty (SEF).',
                'Preview the form, its criteria, rating scale and overall-comment requirement before use.',
                'Published criteria and scoring are protected. Duplicate as New Version when you need to change a published instrument, then select the intended version in the evaluation setup.',
            ], 'The correct forms are ready to select in Evaluations.', $index(FormResource::class), 'forms');
        }
        if ($resource(EvaluationCycleResource::class)) {
            $add('open', 'Review and open the evaluation', 'A draft prepares the setup; opening allows responses.', [
                'Open Evaluations. Create a named evaluation for the department and academic period, then select Annex A and Annex B.',
                'Choose all prepared classes or the intended faculty. Review the proposed classes and any readiness issues.',
                'In Teaching Classes, choose one or more supervisors for Annex B. Check bulk department defaults and individual selections; do not assume a title alone assigns a task.',
                'You may leave Opens empty to start when you open it, and Closes empty to close it manually later.',
                'Save the draft, review it, and select the explicit Open Evaluation action when ready. For late classes, review Add Newly Prepared Classes rather than assuming they were published.',
            ], 'The evaluation is open and the intended classes are included.', $index(EvaluationCycleResource::class), 'evaluation-setup');
        }
        if (! $student && $page(BackgroundProcessing::class)) {
            $add('uploads', 'Find upload reviews and downloads', 'An upload can have separate validation, confirmation and completion stages.', [
                'Open Uploads & Downloads or the notification bell after checking a file.',
                'Choose Review, inspect the proposed changes, fix errors and confirm only the correct import.',
                'Wait for Completed, then use the download action for generated exports. Check the task details if an operation fails.',
            ], 'You can find the final import result or exported file.', $link(BackgroundProcessing::class), 'background');
        }
        if (! $student && $page(StudentEvaluationProgress::class)) {
            $add('progress', 'Follow up by student or section', 'Progress shows completion, not private ratings or comments.', [
                'Open Student Progress. Choose the period and the department you supervise.',
                'Use By Section to find groups with remaining work, then open Students for that section. Use By Student to inspect individual completion.',
                'Your scope includes the assigned class rosters of supervised home-department faculty teaching in other colleges. It does not include every student in those colleges.',
                'A missing student needs an enrolment or evaluator-roster check. A missing response is pending, not a zero rating.',
            ], 'You can identify who still has assigned evaluations to complete.', $link(StudentEvaluationProgress::class), 'progress');
        }
        if (! $student && ($user->is_instructor || $user->hasAnyRole(['chairperson', 'dean']) || $page(SupervisorEvaluations::class))) {
            $add('supervise', 'Complete assigned Annex B tasks', 'Only explicitly assigned supervisor tasks appear.', [
                'Open My Supervisor Tasks. Check the faculty and academic period.',
                'Choose Complete Annex B, answer all required ratings and comments, review, and submit.',
                'If a faculty member is missing, ask the coordinator to check the class supervisor assignment. Seeing their results does not automatically assign you to evaluate them.',
            ], 'Your assigned Annex B task shows Completed. If no task is assigned, your coordinator can review the selection.',
                $page(SupervisorEvaluations::class) ? $link(SupervisorEvaluations::class) : null, 'supervisor-tasks');
        }
        if ($student && $page(MyEvaluations::class)) {
            $add('evaluate', 'Complete your student evaluations', 'Evaluate only the teachers assigned to you.', [
                'Open My Evaluations and check the teacher, subject and academic period.',
                'Open a pending evaluation. Answer every required rating and the required comment fields honestly.',
                'Review your answers before submitting. Check that the evaluation is marked Completed, then continue with the remaining teachers.',
                'If a teacher is missing or incorrect, contact your department coordinator. Do not use another student’s ID.',
            ], 'All intended evaluations show Completed.', $link(MyEvaluations::class), 'my-evaluations');
        }
        if (! $student && $page(MyEvaluationResults::class)) {
            $add('own-results', 'View your released results', 'Your personal results follow the release and minimum-response rules.', [
                'Open My Evaluation Results and choose the correct period.',
                'Results may remain pending until the evaluation closes and enough responses are received. Pending is not a zero score.',
                'Open View Scores for the available anonymous breakdown. Contact the coordinator if a teaching assignment is missing.',
            ], 'You understand when and where your own results become available.', $link(MyEvaluationResults::class), 'my-results');
        }
        if (! $student && $page(EvaluationResults::class)) {
            $add('reports', 'Review and print faculty results', 'Completion, student ratings and supervisor ratings measure different things.', [
                'Open Faculty Results and choose the period, faculty and relevant filters.',
                'SET comes from Annex A; SEF comes from submitted Annex B responses. Pending supervisors are not counted as zero ratings.',
                'Use View Reports to choose the report and preview it. Check scope, response counts and signatories before Print / Save PDF.',
                'The comments toggle can show placeholder responses such as N/A or None. It controls the report display; it does not change the submitted comments.',
            ], 'The preview shows the intended faculty, period and report.', $link(EvaluationResults::class), 'results');
        }
        if (! $student && $page(IndividualEvaluationCopies::class)) {
            $add('copies', 'Print anonymous individual copies', 'Individual copies preserve the submitted response without identifying its evaluator.', [
                'Open Individual Evaluation Copies and choose the instructor and filters.',
                'Select responses or Print All Filtered Copies. Check the selected-copy count in the preview.',
                'Evaluator identities remain concealed. Use Print / Save PDF inside the preview and check the paper instructions shown there.',
            ], 'Only the intended anonymous copies are selected for printing.', $link(IndividualEvaluationCopies::class), 'copies');
        }
        if ($resource(EvaluationCycleResource::class)) {
            $add('close', 'Close and preserve the evaluation', 'Finish the period without deleting its history.', [
                'Check Student Progress and any remaining supervisor tasks before closing.',
                'Open the evaluation and choose Close Evaluation when the collection period is over. A blank closing date requires this manual action.',
                'Review the final reports and preserve the records. Create the next term’s setup in its own academic period.',
            ], 'The evaluation no longer accepts responses and its reports remain available.', $index(EvaluationCycleResource::class));
        }

        if (! $student && $page(SystemHealth::class)) {
            $add('recovery', 'Know where to check system health', 'The owner should know how to investigate failed tasks and preserve records.', [
                'Open System Health & Recovery to review the available checks and recovery information.',
                'Keep a verified database and uploaded-file backup before a major import, update or server transfer. A GitHub code copy alone does not contain the live evaluation records.',
                'If a page fails, retain its error reference and review the system logs with the administrator. Avoid resetting the database or recreating existing records to fix an access issue.',
            ], 'You know who maintains backups and where to investigate system issues.', $link(SystemHealth::class));
        }

        return ['audience' => $audience, 'steps' => $steps, 'storage_key' => 'bisu-onboarding-v1-'.$user->id.'-'.$campus->id];
    }
}
