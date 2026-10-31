<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class EvaluatorFileImport
{
    public function offering(User $actor, Campus $campus, int $id): CourseOffering
    {
        $offering = app(AssignedDataAccess::class)->apply(CourseOffering::query(), $actor, $campus)->findOrFail($id);
        abort_unless($offering->campus->id === $campus->id && $actor->can('manageEvaluators', $offering), 403);

        return $offering;
    }

    public function run(User $actor, Campus $campus, int $id, string $source): array
    {
        $offering = $this->offering($actor, $campus, $id);
        $reader = IOFactory::createReaderForFile(Storage::disk('local')->path($source));
        $reader->setReadDataOnly(true);
        $book = $reader->load(Storage::disk('local')->path($source));
        try {
            $sheet = $book->getActiveSheet();
            if ($sheet->getHighestDataRow() > 5001) {
                throw ValidationException::withMessages(['file' => 'Import up to 5,000 evaluator IDs per file.']);
            }
            $rows = $sheet->toArray(null, true, true, false);
        } finally {
            $book->disconnectWorksheets();
        }
        $header = array_map(fn ($v) => strtolower(trim((string) $v)), array_shift($rows) ?? []);
        $column = collect($header)->search(fn ($value) => in_array($value, ['student_id', 'studentid', 'student id', 'id_number', 'id number', 'idnumber'], true));
        if ($column === false) {
            throw ValidationException::withMessages(['file' => 'The file needs a student_id or id_number column.']);
        }
        $ids = collect($rows)->map(fn ($row) => mb_strtoupper(trim((string) ($row[$column] ?? ''))))->filter()->unique()->values();
        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['file' => 'The file has no student IDs.']);
        }
        $matched = collect();
        foreach ($ids->chunk(500) as $chunk) {
            $matched = $matched->merge(app(AcademicStudents::class)->students($actor, $campus)->whereIn('student_id', $chunk)->get(['users.id', 'student_id']));
        }
        app(AcademicStudents::class)->assign($actor, $offering, [], $matched->pluck('id')->all());
        $skipped = $ids->count() - $matched->count();

        return ['row_count' => $matched->count(), 'skipped' => $skipped, 'message' => $matched->count().' matched evaluators assigned; '.$skipped.' unmatched or inaccessible IDs skipped.'];
    }
}
