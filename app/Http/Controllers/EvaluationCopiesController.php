<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Services\EvaluationCopies;
use App\Services\EvaluationInstrument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class EvaluationCopiesController extends Controller
{
    public function __invoke(Request $request, string $token, EvaluationCopies $service)
    {
        $selection = Cache::get('evaluation-copies:'.$token);
        abort_unless($selection && $selection['user'] === $request->user()->id, 403, 'This selection expired. Reopen Print Copies.');
        $campus = Campus::findOrFail($selection['campus']);
        abort_unless($service->allowed($request->user(), $campus), 403);
        $reveal = $request->boolean('identity');
        abort_if($reveal && ! $request->user()->can('view_evaluator_identity_form'), 403);
        $submissions = $service->query($campus)->whereKey($selection['ids'])->with(['form.fields', 'courseOffering.teachingDepartment', 'courseOffering.evaluatee.instructorDepartment', 'courseOffering.course.department', 'courseOffering.semester.schoolYear'])->orderBy('id')->get();
        abort_unless($submissions->count() === count($selection['ids']), 403);
        $copies = $submissions->map(function ($submission) use ($service, $reveal) {
            $number = $submission->newQuery()->where('course_offering_id', $submission->course_offering_id)->where('form_id', $submission->form_id)->where('id', '<=', $submission->id)->count();

            $submission->setRelation('form', EvaluationInstrument::forSubmission($submission));

            return ['submission' => $submission, 'label' => $submission->form->isSupervisorInstrument() ? 'Supervisor' : 'Student '.$number, 'identity' => $reveal ? $service->identity($submission) : null];
        });

        $sheets = [];
        foreach ($copies as $copy) {
            $annex = $copy['submission']->form->isSupervisorInstrument() ? 'B' : 'A';
            $last = array_key_last($sheets);
            if ($annex === 'A' && $last !== null && $sheets[$last]['annex'] === 'A' && count($sheets[$last]['copies']) === 1) {
                $sheets[$last]['copies'][] = $copy;
            } else {
                $sheets[] = ['annex' => $annex, 'copies' => [$copy]];
            }
        }

        return response()->view('reports.evaluation-copies', ['copies' => $copies, 'sheets' => $sheets, 'reveal' => $reveal, 'canReveal' => $request->user()->can('view_evaluator_identity_form'), 'anonymousUrl' => $request->url().'?signature='.$request->query('signature')])->header('Cache-Control', 'private, no-store');
    }
}
