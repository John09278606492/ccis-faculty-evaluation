<?php

namespace App\Http\Controllers;

use App\Models\CourseOffering;
use App\Services\EvaluationTabularReports;
use App\Support\EvaluationType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EvaluationTabularReportController extends Controller
{
    public function __invoke(Request $request, CourseOffering $offering, string $kind, EvaluationTabularReports $reports)
    {
        abort_unless(in_array($kind, ['scores', 'tally'], true), 404);
        $filters = $request->validate(['department' => ['nullable', 'integer'], 'form' => ['nullable', 'integer'],
            'cycle' => ['nullable', 'integer'], 'instrument' => ['nullable', Rule::in(array_keys(EvaluationType::LABELS))]]);

        return response()->view('reports.evaluation-tabular', ['kind' => $kind, 'offering' => $offering,
            'reports' => $reports->build($request->user(), $offering, $filters)])
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
