<?php

namespace App\Services;

use App\Models\ClassRecordQuizColumn;
use App\Models\ClassRecordSheet;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Writes a quiz's scores into an instructor-uploaded class record (a real,
 * formula-driven Excel template — BISU's official Quizzes/Activities/Exam
 * layout), matching students by ID rather than by name. Built against one
 * concrete template shape: a merged "Quizzes & Topic Analysis" header group
 * containing one raw-score column per quiz followed by Total/Percentage/
 * Equivalent Score columns, a "HIGHEST POSSIBLE SCORE" row, and student rows
 * below it. Not a generic spreadsheet editor — the column/row detection below
 * assumes this layout.
 */
class ClassRecordSyncService
{
    protected const HEADER_ROW = 18;

    /**
     * @return array{matched: int, unmatched: array<int, string>, column: string}
     */
    public function sync(Quiz $quiz, ClassRecordSheet $recordSheet): array
    {
        $upload = $recordSheet->upload;
        $fullPath = Storage::disk('local')->path($upload->file_path);

        $spreadsheet = IOFactory::load($fullPath);
        $sheet = $spreadsheet->getSheetByName($recordSheet->sheet_name);

        if (!$sheet) {
            throw new \RuntimeException("Sheet \"{$recordSheet->sheet_name}\" no longer exists in this file.");
        }

        $existingColumn = ClassRecordQuizColumn::where('class_record_sheet_id', $recordSheet->id)
            ->where('quiz_id', $quiz->id)
            ->first();

        $quizColumn = $existingColumn
            ? $existingColumn->column_letter
            : $this->insertQuizColumn($sheet, $recordSheet, $quiz);

        $studentIdColumn = $this->locateColumnByHeader($sheet, $recordSheet->student_id_header);

        if (!$studentIdColumn) {
            throw new \RuntimeException(
                "Could not find a column headed \"{$recordSheet->student_id_header}\" in this sheet."
            );
        }

        $highestScoreRow = $this->locateHighestPossibleScoreRow($sheet);
        $sheet->setCellValue("{$quizColumn}{$highestScoreRow}", $quiz->total_points);

        $enrolledStudentIds = $quiz->courseOffering->students()
            ->whereNotNull('student_id')
            ->pluck('student_id')
            ->map(fn ($id) => (string) $id)
            ->all();
        $scoresByStudentId = $this->latestScoresByStudentId($quiz);

        $matched = 0;
        $noScoreYet = [];
        $unrecognized = [];
        $dataStartRow = $highestScoreRow + 1;

        for ($row = $dataStartRow; $this->rowHasStudent($sheet, $row); $row++) {
            $studentId = trim((string) $sheet->getCell("{$studentIdColumn}{$row}")->getValue());

            if ($studentId === '') {
                continue;
            }

            if (array_key_exists($studentId, $scoresByStudentId)) {
                $sheet->setCellValue("{$quizColumn}{$row}", $scoresByStudentId[$studentId]);
                $matched++;
            } elseif (in_array($studentId, $enrolledStudentIds, true)) {
                // A real, enrolled student — just hasn't attempted this quiz yet.
                $noScoreYet[] = $studentId;
            } else {
                // This ID in the spreadsheet doesn't match any enrolled student —
                // likely a typo in the sheet, worth the instructor's attention.
                $unrecognized[] = $studentId;
            }
        }

        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($fullPath);

        return [
            'matched' => $matched,
            'no_score_yet' => $noScoreYet,
            'unrecognized' => $unrecognized,
            'column' => $quizColumn,
        ];
    }

    /**
     * Inserts a new raw-score column for this quiz right before the "Total
     * Score" column of the Quizzes group, extends that group's SUM formulas
     * to include it, shifts any already-mapped quiz columns on this sheet
     * that just moved right, and records the new mapping.
     */
    protected function insertQuizColumn(Worksheet $sheet, ClassRecordSheet $recordSheet, Quiz $quiz): string
    {
        [$groupStartCol, $groupEndCol] = $this->locateQuizzesGroupRange($sheet);
        $totalScoreCol = $this->locateColumnInRange($sheet, $groupStartCol, $groupEndCol, self::HEADER_ROW, 'total score');

        if (!$totalScoreCol) {
            throw new \RuntimeException('Could not find the "Total Score" column in the Quizzes & Topic Analysis group.');
        }

        $sheet->insertNewColumnBefore($totalScoreCol, 1);

        // Every column at/after the insertion point — including other quizzes
        // already synced here — just shifted one letter to the right.
        ClassRecordQuizColumn::where('class_record_sheet_id', $recordSheet->id)
            ->get()
            ->each(function (ClassRecordQuizColumn $mapping) use ($totalScoreCol) {
                if ($this->columnIndex($mapping->column_letter) >= $this->columnIndex($totalScoreCol)) {
                    $mapping->update(['column_letter' => $this->shiftColumn($mapping->column_letter, 1)]);
                }
            });

        $newColumn = $totalScoreCol; // the freshly inserted (now-empty) column occupies the old Total Score letter
        $newTotalScoreCol = $this->shiftColumn($totalScoreCol, 1);

        $sheet->setCellValue("{$newColumn}" . self::HEADER_ROW, $quiz->title);

        $this->extendTotalScoreFormulas($sheet, $groupStartCol, $newTotalScoreCol);

        ClassRecordQuizColumn::create([
            'class_record_sheet_id' => $recordSheet->id,
            'quiz_id' => $quiz->id,
            'column_letter' => $newColumn,
        ]);

        return $newColumn;
    }

    /**
     * Rewrites =SUM(...) in the Total Score column for every existing student
     * row so its range covers every raw quiz column in the group, since
     * inserting a column doesn't automatically widen a SUM range the way it
     * would in a live Excel session.
     */
    protected function extendTotalScoreFormulas(Worksheet $sheet, string $groupStartCol, string $totalScoreCol): void
    {
        $lastQuizCol = $this->shiftColumn($totalScoreCol, -1);
        $highestScoreRow = $this->locateHighestPossibleScoreRow($sheet);

        $sheet->setCellValue("{$totalScoreCol}{$highestScoreRow}", "=SUM({$groupStartCol}{$highestScoreRow}:{$lastQuizCol}{$highestScoreRow})");

        for ($row = $highestScoreRow + 1; $this->rowHasStudent($sheet, $row); $row++) {
            $sheet->setCellValue("{$totalScoreCol}{$row}", "=SUM({$groupStartCol}{$row}:{$lastQuizCol}{$row})");
        }
    }

    /**
     * Latest attempt's score per student_id, for students enrolled in this
     * quiz's course offering. Ungraded/no-attempt students are simply absent
     * from the map (their cell is left untouched rather than zeroed out).
     */
    protected function latestScoresByStudentId(Quiz $quiz): array
    {
        $studentIdsByUserId = $quiz->courseOffering->students()
            ->whereNotNull('student_id')
            ->pluck('student_id', 'users.id');

        $latestAttempts = QuizAttempt::where('quiz_id', $quiz->id)
            ->whereIn('user_id', $studentIdsByUserId->keys())
            ->whereNotNull('score')
            ->orderByDesc('attempt_number')
            ->get()
            ->unique('user_id');

        $scores = [];
        foreach ($latestAttempts as $attempt) {
            $studentId = $studentIdsByUserId[$attempt->user_id] ?? null;
            if ($studentId !== null) {
                $scores[(string) $studentId] = $attempt->score;
            }
        }

        return $scores;
    }

    protected function locateColumnByHeader(Worksheet $sheet, string $header, int $row = self::HEADER_ROW): ?string
    {
        $needle = strtolower(trim($header));
        $highestColumn = $sheet->getHighestColumn();

        for ($col = 'A'; true; $col++) {
            $value = strtolower(trim((string) $sheet->getCell("{$col}{$row}")->getValue()));
            if ($value === $needle) {
                return $col;
            }
            if ($col === $highestColumn) {
                break;
            }
        }

        return null;
    }

    protected function locateColumnInRange(Worksheet $sheet, string $startCol, string $endCol, int $row, string $needle): ?string
    {
        $needle = strtolower($needle);

        for ($col = $startCol; true; $col++) {
            $value = strtolower(trim((string) $sheet->getCell("{$col}{$row}")->getValue()));
            if (str_contains($value, $needle)) {
                return $col;
            }
            if ($col === $endCol) {
                break;
            }
        }

        return null;
    }

    /**
     * Finds the merged header range (row 16 in the known template) whose
     * label mentions "quizzes", returning its [startColumn, endColumn].
     */
    protected function locateQuizzesGroupRange(Worksheet $sheet): array
    {
        foreach ($sheet->getMergeCells() as $range) {
            [$topLeft] = explode(':', $range);
            $label = strtolower((string) $sheet->getCell($topLeft)->getValue());

            if (str_contains($label, 'quizzes')) {
                [$start, $end] = explode(':', $range);
                $startCol = preg_replace('/\d+/', '', $start);
                $endCol = preg_replace('/\d+/', '', $end);

                return [$startCol, $endCol];
            }
        }

        throw new \RuntimeException('Could not find the "Quizzes & Topic Analysis" header group on this sheet.');
    }

    protected function locateHighestPossibleScoreRow(Worksheet $sheet): int
    {
        $highestRow = $sheet->getHighestRow();

        for ($row = 1; $row <= $highestRow; $row++) {
            $value = strtolower(trim((string) $sheet->getCell("B{$row}")->getValue()));
            if (str_contains($value, 'highest possible score')) {
                return $row;
            }
        }

        throw new \RuntimeException('Could not find the "HIGHEST POSSIBLE SCORE" row on this sheet.');
    }

    /**
     * Requires both a numeric row number (column A) and a name (column B) —
     * some class records have stray notes in column B below the last real
     * student row (no row number), which a name-only check would wrongly
     * treat as another student row.
     */
    protected function rowHasStudent(Worksheet $sheet, int $row): bool
    {
        $number = trim((string) $sheet->getCell("A{$row}")->getValue());
        $name = trim((string) $sheet->getCell("B{$row}")->getValue());

        return $number !== '' && is_numeric($number) && $name !== '';
    }

    protected function columnIndex(string $column): int
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($column);
    }

    protected function shiftColumn(string $column, int $by): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($this->columnIndex($column) + $by);
    }
}
