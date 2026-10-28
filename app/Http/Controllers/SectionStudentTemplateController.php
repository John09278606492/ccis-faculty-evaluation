<?php

namespace App\Http\Controllers;

use App\Models\AcademicSection;
use App\Services\AcademicSections;
use App\Services\BackgroundTasks;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SectionStudentTemplateController extends Controller
{
    public function __invoke(Request $request, AcademicSection $section)
    {
        $section->loadMissing('department.campus');
        $section = app(AcademicSections::class)->section($request->user(), $section->department->campus, $section->id);

        return response()->streamDownload(function () use ($section) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $csv = app(BackgroundTasks::class);
            $csv->row($out, [$section->name]);
            $csv->row($out, ['ID No.']);
            $csv->row($out, ['SAMPLE-REPLACE-001']);
            $csv->row($out, ['SAMPLE-REPLACE-002']);
            fclose($out);
        }, (Str::slug($section->name) ?: 'section').'-student-list.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
