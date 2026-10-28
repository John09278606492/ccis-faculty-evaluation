<?php

namespace App\Http\Controllers;

use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\User;
use App\Services\AssignedDataAccess;
use App\Services\EvaluationResultsRelease;
use App\Services\EvaluationScoring;
use App\Services\FacultySupervisorEvaluators;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class FacultyReportController extends Controller
{
    private function scope(Request $request, CourseOffering $offering): array
    {
        $offering->loadMissing('semester.schoolYear.campus', 'evaluatee.instructorDepartment', 'course');
        $campus = $offering->campus;
        $viewer = $request->user();
        $canRead = $viewer->can('page_EvaluationResults')
            || ($viewer->id === $offering->user_id && $viewer->can('page_MyEvaluationResults'));
        abort_unless(! $viewer->is_student && $canRead && $campus && $viewer->canAccessTenant($campus), 403);

        abort_unless(app(AssignedDataAccess::class)->apply(CourseOffering::query(), $request->user(), $campus)->whereKey($offering->id)->exists(), 403);
        abort_unless(EvaluationResultsRelease::canSeeScores($request->user(), $offering), 403);

        return ['campus_id' => $campus->id, 'user_id' => $offering->user_id, 'semester_id' => $offering->semester_id];
    }

    public function show(Request $request, CourseOffering $offering)
    {
        $scope = $this->scope($request, $offering);
        $canEdit = $request->user()->can('page_EvaluationResults') && $request->user()->can('update', $offering);
        $filters = $request->validate(['department' => ['nullable', 'integer'], 'form' => ['nullable', 'integer'], 'cycle' => ['nullable', 'integer']]);
        $context = ['campus_id' => $scope['campus_id'], 'semester_id' => $scope['semester_id'], 'department_id' => $filters['department'] ?? null,
            'form_id' => $filters['form'] ?? null, 'evaluation_cycle_id' => $filters['cycle'] ?? null];
        if ($context['department_id']) {
            abort_unless(app(AssignedDataAccess::class)->apply(Department::query(), $request->user(), $offering->campus)->whereKey($context['department_id'])->exists(), 403);
        }
        $offerings = app(AssignedDataAccess::class)->apply(app(ReportService::class)->facultyReportOfferings($scope['user_id'], $context), $request->user(), $offering->campus)
            ->where('user_id', $scope['user_id'])->with(['evaluationForm.fields', 'formSubmissions', 'course.department', 'teachingDepartment', 'supervisorEvaluators'])->get();
        if ($request->user()->id === $offering->user_id) {
            $offerings = $offerings->filter(fn ($co) => EvaluationResultsRelease::isReleased($co));
        }
        $rows = collect();
        $setRows = collect();
        foreach ($offerings as $assignment) {
            $form = $assignment->evaluationForm;
            if (! $form) {
                continue;
            }
            $score = app(EvaluationScoring::class)->assignment($assignment, $assignment->formSubmissions);
            $comments = app(ReportService::class)->getAggregatedComments(collect([$assignment->id]));
            $rows->push(['assignment' => $assignment, 'form' => $form, 'instrument' => $score['instrument'],
                'comments' => array_merge($comments['overall_comments'], ...array_values($comments['field_comments'])), 'count' => $score['count'],
                'mean_total' => $score['mean_total'], 'mean_5' => $score['mean_5'], 'maximum' => $score['maximum'], 'percentage' => $score['percentage'],
                'items' => $score['fields']->map(fn ($field) => ['label' => $field['label'], 'section' => $field['section'], 'mean' => $field['native_avg']])]);
            if ($score['instrument'] === 'SET') {
                $submissions = $assignment->formSubmissions->where('form_id', $assignment->form_id);
                $groups = $submissions->groupBy(fn ($submission) => app(\App\Services\EvaluationSections::class)->label($submission, $assignment));
                if ($groups->isEmpty()) {
                    $groups = collect([$assignment->evaluation_section_label => collect()]);
                }
                foreach ($groups as $section => $responses) {
                    $sectionScore = app(EvaluationScoring::class)->assignment($assignment, $responses);
                    $setRows->push(['assignment' => $assignment, 'form' => $form, 'section' => $section,
                        'count' => $sectionScore['count'], 'percentage' => $sectionScore['percentage']]);
                }
            }
        }
        $instruments = app(EvaluationScoring::class)->instruments($rows);
        $ratings = collect(['SET', 'SEF'])->mapWithKeys(fn ($instrument) => [$instrument => $instruments[$instrument]['percentage'] ?? null]);
        $stored = DB::table('faculty_development_reports')->where($scope)->first();
        $periods = app(AssignedDataAccess::class)->apply(CourseOffering::query(), $request->user(), $offering->campus)->where('user_id', $scope['user_id'])
            ->whereHas('semester.schoolYear', fn ($query) => $query->where('campus_id', $scope['campus_id']))
            ->where('is_active', true)->with('semester.schoolYear')->orderByDesc('semester_id')->get()->unique('semester_id');

        $annex = $request->query('annex', 'C');
        abort_unless(in_array($annex, ['C', 'D'], true), 422);
        $staff = app(AssignedDataAccess::class)->apply(User::query(), $request->user(), $offering->campus)->where('is_student', false)->whereHas('roles')->whereHas('campuses', fn ($q) => $q->where('campuses.id', $scope['campus_id']))->orderBy('name')->get();
        $departmentIds = $offerings->map(fn ($assignment) => $assignment->teaching_department_id ?? $assignment->course?->department_id ?? $offering->evaluatee->instructor_department_id)->filter()->unique();
        $heads = Department::where('campus_id', $scope['campus_id'])->whereIn('id', $departmentIds)->with(['supervisor', 'chairperson'])->get();
        $additionalSupervisors = $offerings->flatMap(fn ($assignment) => $assignment->course_id
            ? $assignment->supervisorEvaluators : app(FacultySupervisorEvaluators::class)->forTask($assignment)->get())->unique('id');
        $staff = $staff->merge($additionalSupervisors)->unique('id')->sortBy('name')->values();
        $details = $stored ? json_decode($stored->details, true) : [];
        $primary = $heads->firstWhere('id', $offering->teaching_department_id ?? $offering->course?->department_id) ?? $heads->first();
        $primaryHead = $primary?->evaluationHeads()->first()?->id;
        foreach (['reviewed' => $primaryHead, 'supervisor' => $primaryHead, 'prepared' => $canEdit ? $request->user()->id : null] as $key => $id) {
            if (! array_key_exists($key.'_id', $details) && empty($details[$key.'_name']) && $id && $staff->contains('id', $id)) {
                $details[$key.'_id'] = $id;
                $details[$key.'_name'] = $staff->firstWhere('id', $id)->name;
            }
        }

        return response()->view($annex === 'C' ? 'reports.faculty-individual' : 'reports.faculty-summary', ['annex' => $annex, 'offering' => $offering, 'rows' => $rows, 'setRows' => $setRows, 'ratings' => $ratings, 'periods' => $periods, 'details' => $details, 'staff' => $staff, 'heads' => $heads, 'additionalSupervisors' => $additionalSupervisors, 'canEdit' => $canEdit])
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function save(Request $request, CourseOffering $offering)
    {
        $scope = $this->scope($request, $offering);
        abort_unless($request->user()->can('page_EvaluationResults'), 403);
        Gate::authorize('update', $offering);
        $staff = app(AssignedDataAccess::class)->apply(User::query(), $request->user(), $offering->campus)->where('is_student', false)->whereHas('roles')->whereHas('campuses', fn ($q) => $q->where('campuses.id', $scope['campus_id']))->get();
        $additional = app(AssignedDataAccess::class)->apply(CourseOffering::query(), $request->user(), $offering->campus)
            ->where('user_id', $scope['user_id'])->where('semester_id', $scope['semester_id'])->whereNotNull('course_id')->with('supervisorEvaluators')->get()->flatMap(fn ($class) => $class->supervisorEvaluators);
        $staff = $staff->merge($additional)->unique('id');
        $existing = DB::table('faculty_development_reports')->where($scope)->first();
        $previous = $existing ? json_decode($existing->details, true) : [];
        $details = $request->validate([
            'prepared_id' => ['nullable', Rule::in($staff->modelKeys())],
            'reviewed_id' => ['nullable', Rule::in($staff->modelKeys())],
            'supervisor_id' => ['nullable', Rule::in($staff->modelKeys())],
            'rank' => 'nullable|string|max:255', 'areas' => 'nullable|string|max:10000', 'activities' => 'nullable|string|max:10000', 'action_plan' => 'nullable|string|max:10000',
            'supervisor_name' => 'nullable|string|max:255', 'faculty_name' => 'nullable|string|max:255', 'supervisor_date' => 'nullable|date', 'faculty_date' => 'nullable|date',
            'prepared_name' => 'nullable|string|max:255', 'reviewed_name' => 'nullable|string|max:255', 'prepared_date' => 'nullable|date', 'reviewed_date' => 'nullable|date',
        ]);
        foreach (['prepared', 'reviewed', 'supervisor'] as $key) {
            if ($request->exists($key.'_id')) {
                $details[$key.'_name'] = $staff->firstWhere('id', $details[$key.'_id'] ?? null)?->name
                    ?? (empty($previous[$key.'_id']) ? ($previous[$key.'_name'] ?? null) : null);
            }
        }
        $annex = $request->validate(['annex' => 'nullable|in:C,D'])['annex'] ?? 'D';
        $details = array_merge($previous, $details);
        DB::table('faculty_development_reports')->updateOrInsert($scope, ['details' => json_encode($details), 'updated_at' => now(), 'created_at' => now()]);

        return redirect()->route('evaluation.report', ['offering' => $offering, 'annex' => $annex, ...$request->only(['department', 'form', 'cycle'])])->with('status', $annex === 'C' ? 'Report details saved.' : 'Development plan saved.');
    }
}
