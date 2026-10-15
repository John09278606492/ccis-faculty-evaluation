<?php

namespace App\Services;

use App\Models\ClassRecordSheet;
use App\Models\CourseOffering;
use App\Models\Quiz;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ClassRecordSyncService
{
    /** Read actual headers so instructors do not have to guess their spelling. */
    public function headers(ClassRecordSheet $record): array
    {
        [$book, $sheet] = $this->open($record);
        try {
            $headers = [];
            $last = $this->highestScoreRow($sheet);
            for ($row = 1; $row < $last; $row++) {
                foreach ($sheet->getRowIterator($row, $row) as $sheetRow) {
                    $cells = $sheetRow->getCellIterator();
                    $cells->setIterateOnlyExistingCells(true);
                    foreach ($cells as $cell) {
                        $value = trim((string) $cell->getValue());
                        if ($value !== '' && ! str_starts_with($value, '=')) {
                            $headers[$value] = "{$value} ({$cell->getCoordinate()})";
                        }
                    }
                }
            }

            return $headers;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /** Preview identity matches without changing the workbook or mappings. */
    public function inspect(ClassRecordSheet $record, CourseOffering $offering, string $header): array
    {
        [$book, $sheet] = $this->open($record);
        try {
            [$idColumn] = $this->locateHeader($sheet, $header);
            $rows = $this->studentRows($sheet, $idColumn);
            $enrolled = $offering->students()->whereNotNull('student_id')->pluck('student_id')->map(fn ($id) => trim((string) $id))->all();
            $ids = array_values($rows);
            $duplicates = array_keys(array_filter(array_count_values($ids), fn ($count) => $count > 1));
            if ($duplicates !== []) {
                throw new \RuntimeException('Duplicate student IDs in this sheet: '.implode(', ', array_slice($duplicates, 0, 5)).'. Correct the workbook before mapping.');
            }

            return [
                'column' => $idColumn,
                'rows' => count($rows),
                'matched' => count(array_intersect($ids, $enrolled)),
                'unrecognized' => array_values(array_diff($ids, $enrolled)),
                'missing' => array_values(array_diff($enrolled, $ids)),
                'sample' => array_slice($rows, 0, 5, true),
            ];
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function preview(Quiz $quiz, ClassRecordSheet $record): array
    {
        if (! in_array($quiz->type, ['quiz', 'activity'], true)) {
            throw new \RuntimeException('This integration supports quizzes and activities. Export exam scores separately.');
        }
        if ((int) $record->course_offering_id !== (int) $quiz->course_offering_id || blank($record->student_id_header)) {
            throw new \RuntimeException('Map this sheet to the quiz course and select its Student ID header first.');
        }
        $result = $this->inspect($record, $quiz->courseOffering, $record->student_id_header);
        [$book, $sheet] = $this->open($record);
        try {
            [$idColumn] = $this->locateHeader($sheet, $record->student_id_header);
            $rows = $this->studentRows($sheet, $idColumn);
            [$start, $total, $headerRow, $groupRow] = $this->assessmentGroup($sheet, $quiz->type);
            $mapping = $record->quizColumns()->where('quiz_id', $quiz->id)->first();
            if ($mapping && (Coordinate::columnIndexFromString($mapping->column_letter) < Coordinate::columnIndexFromString($start)
                || Coordinate::columnIndexFromString($mapping->column_letter) >= Coordinate::columnIndexFromString($total))) {
                throw new \RuntimeException('The saved score column is outside the selected assessment group. Restore the workbook layout before syncing.');
            }
            $scores = $this->latestScores($quiz);
            $result['matched'] = count(array_intersect(array_values($rows), array_keys($scores)));
            $result['no_score_yet'] = array_values(array_diff(array_values($rows), array_keys($scores), $result['unrecognized']));
            $result['scores_missing_from_sheet'] = array_values(array_diff(array_keys($scores), array_values($rows)));
            $result['column'] = $mapping?->column_letter ?? $total;
            $result['new_column'] = ! $mapping;
            $result['header_row'] = $headerRow;
            $result['highest_score_row'] = $this->highestScoreRow($sheet);
            $openedAt = $quiz->available_from;
            $dateSource = $openedAt ? 'Scheduled opening date' : null;
            if (! $openedAt && ($firstStart = $quiz->attempts()->min('started_at'))) {
                $openedAt = Carbon::parse($firstStart, config('app.timezone'));
                $dateSource = 'Earliest student start';
            }
            $result['opened_date'] = $openedAt?->copy()->timezone(config('app.timezone'))->format('Y-m-d');
            $result['date_source'] = $dateSource;
            $result['date_row'] = $headerRow > $groupRow + 1 ? $headerRow - 1 : null;
            $result['date_cell'] = $result['date_row'] ? $result['column'].$result['date_row'] : null;
            $result['header_label'] = $quiz->title;
            if ($result['opened_date'] && ! $result['date_row']) {
                $result['header_label'] .= "\nOpened: ".$result['opened_date'];
            }
            $latest = $quiz->attempts()->orderByDesc('attempt_number')->get()->unique('user_id')->keyBy('user_id');
            $result['students'] = $quiz->courseOffering->students()->get()->map(function ($student) use ($latest, $rows, $scores, $result) {
                $id = trim((string) $student->student_id);
                $row = array_search($id, $rows, true);
                $attempt = $latest->get($student->id);
                $ready = $row !== false && array_key_exists($id, $scores);

                return [
                    'student_id' => $id, 'name' => $student->full_name,
                    'status' => $attempt?->status ?? 'not_started',
                    'score' => $attempt?->status === 'graded' ? $attempt->score : null,
                    'cell' => $row !== false ? $result['column'].$row : null,
                    'row' => $row !== false ? $row : null,
                    'ready' => $ready,
                    'reason' => $id === '' ? 'Missing student ID' : ($row === false ? 'Student ID not found in worksheet' : match ($attempt?->status) {
                        'graded' => $attempt->score !== null ? 'Ready to sync' : 'Grade has no score',
                        'submitted' => 'Needs manual grading',
                        'in_progress' => 'Attempt is still marked in progress',
                        default => 'No attempt yet',
                    }),
                ];
            })->all();
            $result['status_counts'] = collect($result['students'])->countBy('status')->all();
            $result['roster_count'] = count($result['students']);

            return $result;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /** Lock the entire upload: two sheets in one workbook must not overwrite each other. */
    public function sync(Quiz $quiz, ClassRecordSheet $record): array
    {
        return Cache::store('file')->lock('class-record-upload-'.$record->class_record_upload_id, 120)->block(10, function () use ($quiz, $record) {
            $record->refresh();
            $result = $this->preview($quiz, $record);
            if ($result['matched'] === 0) {
                throw new \RuntimeException('No fully graded scores match this sheet yet. Finish grading and check the Student ID mapping first.');
            }
            $upload = $record->upload;
            $path = Storage::disk('local')->path($upload->file_path);
            [$book, $sheet] = $this->open($record);
            $temp = tempnam(dirname($path), 'sync-');
            $backup = $upload->file_path.'.before-sync';
            $replaced = false;
            try {
                return DB::transaction(function () use ($quiz, $record, $result, $upload, $path, $book, $sheet, $temp, $backup, &$replaced) {
                    $this->applyScores($sheet, $quiz, $record, $result);
                    if ($result['new_column']) {
                        foreach ($record->quizColumns as $mapping) {
                            if (Coordinate::columnIndexFromString($mapping->column_letter) >= Coordinate::columnIndexFromString($result['column'])) {
                                $mapping->update(['column_letter' => $this->shift($mapping->column_letter, 1)]);
                            }
                        }
                        $record->quizColumns()->create(['quiz_id' => $quiz->id, 'column_letter' => $result['column']]);
                    }
                    $format = IOFactory::identify($path);
                    // Linked workbooks are not available to the server. Preserve formula
                    // expressions and let Excel recalculate them when the file is opened.
                    IOFactory::createWriter($book, $format)->setPreCalculateFormulas(false)->save($temp);
                    if ($format === 'Xlsx') {
                        app(WorkbookExternalLinks::class)->preserve($path, $temp);
                    }
                    if (! Storage::disk('local')->copy($upload->file_path, $backup)) {
                        throw new \RuntimeException('Could not back up the workbook. No scores were written.');
                    }
                    $replaced = true;
                    if (! copy($temp, $path)) {
                        Storage::disk('local')->copy($backup, $upload->file_path);
                        throw new \RuntimeException('Could not save the workbook. The previous file was restored.');
                    }

                    Quiz::whereKey($quiz->id)->update(['class_record_synced_at' => now(), 'class_record_sync_error' => null]);
                    ClassroomUpdates::course($quiz->course_offering_id, 'workbook', $quiz->id);

                    return $result;
                });
            } catch (\Throwable $exception) {
                // Restore only if this run reached the file replacement step.
                if ($replaced) {
                    Storage::disk('local')->copy($backup, $upload->file_path);
                }
                throw $exception;
            } finally {
                if (is_file($temp)) {
                    unlink($temp);
                }
                $book->disconnectWorksheets();
            }
        });
    }

    /** The same in-memory changes drive both the visual preview and the save. */
    public function applyScores(Worksheet $sheet, Quiz $quiz, ClassRecordSheet $record, array $plan): void
    {
        [$start, $total, $headerRow, $groupRow] = $this->assessmentGroup($sheet, $quiz->type);
        $column = $plan['column'];
        if ($plan['new_column']) {
            $sheet->insertNewColumnBefore($total);
            $total = $this->shift($total, 1);
        }
        [$idColumn] = $this->locateHeader($sheet, $record->student_id_header);
        $rows = $this->studentRows($sheet, $idColumn);
        $highestRow = $this->highestScoreRow($sheet);
        $sheet->setCellValueExplicit($column.$headerRow, $plan['header_label'], DataType::TYPE_STRING);
        if ($plan['opened_date'] && $plan['date_row']) {
            $dateCell = $column.$plan['date_row'];
            if ($sheet->getCell($dateCell)->isInMergeRange()) {
                throw new \RuntimeException('The opening date cell is merged. Unmerge '.$dateCell.' before syncing.');
            }
            $sheet->setCellValue($dateCell, Date::PHPToExcel(Carbon::parse($plan['opened_date'], config('app.timezone'))));
            $sheet->getStyle($dateCell)->getNumberFormat()->setFormatCode('mmm d, yyyy');
        }
        $sheet->getStyle($column.$headerRow)->getAlignment()->setWrapText(true);
        $sheet->setCellValue($column.$highestRow, $quiz->total_points);
        $last = $this->shift($total, -1);
        foreach ([$highestRow, ...array_keys($rows)] as $row) {
            $sheet->setCellValue($total.$row, "=SUM({$start}{$row}:{$last}{$row})");
        }
        $scores = $this->latestScores($quiz);
        foreach ($rows as $row => $id) {
            if (array_key_exists($id, $scores)) {
                $sheet->setCellValue($column.$row, $scores[$id]);
            }
        }
    }

    protected function latestScores(Quiz $quiz): array
    {
        $students = $quiz->courseOffering->students()->whereNotNull('student_id')->pluck('student_id', 'users.id');

        // Select latest first, then require a final grade. Never fall back to an older score.
        return $quiz->attempts()->whereIn('user_id', $students->keys())->orderByDesc('attempt_number')->get()
            ->unique('user_id')->filter(fn ($attempt) => $attempt->status === 'graded' && $attempt->score !== null)
            ->mapWithKeys(fn ($attempt) => [trim((string) $students[$attempt->user_id]) => $attempt->score])->all();
    }

    protected function open(ClassRecordSheet $record): array
    {
        $path = Storage::disk('local')->path($record->upload->file_path);
        if (! is_file($path)) {
            throw new \RuntimeException('The uploaded workbook is missing. Upload it again from Class Records.');
        }
        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName($record->sheet_name);
        if (! $sheet) {
            $book->disconnectWorksheets();
            throw new \RuntimeException('The mapped worksheet no longer exists in this workbook.');
        }

        return [$book, $sheet];
    }

    protected function locateHeader(Worksheet $sheet, string $header): array
    {
        if (blank($header)) {
            throw new \RuntimeException('Select a Student ID column header first.');
        }
        $matches = [];
        $highestRow = $this->highestScoreRow($sheet);
        for ($row = 1; $row < $highestRow; $row++) {
            for ($col = 1; $col <= Coordinate::columnIndexFromString($sheet->getHighestDataColumn()); $col++) {
                $letter = Coordinate::stringFromColumnIndex($col);
                if (mb_strtolower(trim((string) $sheet->getCell($letter.$row)->getValue())) === mb_strtolower(trim($header))) {
                    $matches[] = [$letter, $row];
                }
            }
        }
        if (count($matches) !== 1) {
            throw new \RuntimeException('The Student ID header must occur exactly once above the highest possible score row. Choose a unique header from the workbook.');
        }

        return $matches[0];
    }

    protected function highestScoreRow(Worksheet $sheet): int
    {
        for ($row = 1; $row <= min($sheet->getHighestDataRow(), 100); $row++) {
            for ($col = 1; $col <= 10; $col++) {
                if (str_contains(strtolower((string) $sheet->getCell([$col, $row])->getValue()), 'highest possible score')) {
                    return $row;
                }
            }
        }
        throw new \RuntimeException('This sheet needs a HIGHEST POSSIBLE SCORE row within the first 100 rows. Use the official class record template.');
    }

    protected function studentRows(Worksheet $sheet, string $idColumn): array
    {
        $rows = [];
        for ($row = $this->highestScoreRow($sheet) + 1; $row <= $sheet->getHighestDataRow(); $row++) {
            $number = trim((string) $sheet->getCell('A'.$row)->getValue());
            $name = trim((string) $sheet->getCell('B'.$row)->getValue());
            $id = trim((string) $sheet->getCell($idColumn.$row)->getFormattedValue());
            if (is_numeric($number) && $name !== '' && $id !== '') {
                $rows[$row] = $id;
            }
        }
        if ($rows === []) {
            throw new \RuntimeException('No student rows were found. Check the ID header; students need a row number in column A and a name in column B.');
        }

        return $rows;
    }

    protected function assessmentGroup(Worksheet $sheet, string $type): array
    {
        foreach ($sheet->getMergeCells() as $range) {
            [$start, $end] = explode(':', $range);
            if (! str_contains(strtolower((string) $sheet->getCell($start)->getValue()), ($type === 'activity' ? 'activities' : 'quizzes'))) {
                continue;
            }
            [$startCol, $startRow] = Coordinate::coordinateFromString($start);
            [$endCol] = Coordinate::coordinateFromString($end);
            for ($row = $startRow; $row < $this->highestScoreRow($sheet); $row++) {
                for ($col = Coordinate::columnIndexFromString($startCol); $col <= Coordinate::columnIndexFromString($endCol); $col++) {
                    $letter = Coordinate::stringFromColumnIndex($col);
                    if (str_contains(strtolower((string) $sheet->getCell($letter.$row)->getValue()), 'total score')) {
                        return [$startCol, $letter, $row, $startRow];
                    }
                }
            }
        }
        throw new \RuntimeException('Could not find the '.($type === 'activity' ? 'Activities' : 'Quizzes').' group and its Total Score column. Use the official class record template.');
    }

    protected function shift(string $column, int $by): string
    {
        return Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($column) + $by);
    }
}
