<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Services\BackgroundTasks;
use App\Services\InstructorOfferingImports;
use Illuminate\Http\Request;

class InstructorOfferingTemplateController extends Controller
{
    public function __invoke(Request $request, Campus $campus)
    {
        $imports = app(InstructorOfferingImports::class);
        $imports->authorize($request->user(), $campus);
        $data = $request->validate([
            'semester_id' => ['required', 'integer'],
            'source_campus_id' => ['nullable', 'string', 'max:50'],
        ]);
        $rows = $imports->exampleRows($campus, (int) $data['semester_id'], $data['source_campus_id'] ?? '6');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                app(BackgroundTasks::class)->row($out, $row);
            }
            fclose($out);
        }, 'instructor-offerings-example.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
