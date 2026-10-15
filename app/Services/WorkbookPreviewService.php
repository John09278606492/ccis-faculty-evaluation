<?php

namespace App\Services;

use App\Models\ClassRecordSheet;
use App\Models\Quiz;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class WorkbookPreviewService
{
    public function sheetNames(ClassRecordSheet $record): array
    {
        $path = Storage::disk('local')->path($record->upload->file_path);
        $names = IOFactory::createReaderForFile($path)->listWorksheetNames($path);

        return array_combine($names, $names);
    }

    public function preview(ClassRecordSheet $record, ?Quiz $quiz = null, ?string $worksheet = null, bool $proposed = false, int $firstRow = 1): array
    {
        $book = null;
        try {
            $path = Storage::disk('local')->path($record->upload->file_path);
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName($worksheet ?? $record->sheet_name);
            if (! $sheet) {
                throw new \RuntimeException('That worksheet is not present in the uploaded workbook.');
            }
            $plan = null;
            $blockingReason = null;
            if ($quiz) {
                try {
                    $plan = app(ClassRecordSyncService::class)->preview($quiz, $record);
                    if ($plan['matched'] === 0) {
                        $blockingReason = 'No fully graded score matches a student row yet. The student details below explain what is missing.';
                    }
                } catch (\Throwable $e) {
                    $blockingReason = $e->getMessage();
                }
            }
            $changes = [];
            if ($proposed && $plan && $sheet->getTitle() === $record->sheet_name) {
                foreach ($plan['students'] as $student) {
                    if ($student['ready']) {
                        $changes[$student['cell']] = ['before' => $plan['new_column'] ? null : $sheet->getCell($student['cell'])->getValue(), 'after' => $student['score']];
                    }
                }
                foreach ([$plan['header_row'] => $plan['header_label'], $plan['highest_score_row'] => $quiz->total_points] as $row => $value) {
                    $address = $plan['column'].$row;
                    $changes[$address] = ['before' => $plan['new_column'] ? null : $sheet->getCell($address)->getValue(), 'after' => $value];
                }
                if ($plan['opened_date'] && $plan['date_cell']) {
                    $changes[$plan['date_cell']] = ['before' => $plan['new_column'] ? null : $sheet->getCell($plan['date_cell'])->getValue(), 'after' => $plan['opened_date']];
                }
                app(ClassRecordSyncService::class)->applyScores($sheet, $quiz, $record, $plan);
            }
            $grid = $this->grid($sheet, $changes, $firstRow);

            return [
                ...$grid, 'filename' => $record->upload->original_filename,
                'sheet' => $sheet->getTitle(), 'plan' => $plan, 'blocking_reason' => $blockingReason,
                'proposed' => $proposed && $plan !== null, 'error' => null,
            ];
        } catch (\Throwable $e) {
            return ['error' => 'Cannot preview this workbook: '.$e->getMessage()];
        } finally {
            $book?->disconnectWorksheets();
        }
    }

    protected function grid(Worksheet $sheet, array $changes, int $firstRow): array
    {
        $totalRows = $sheet->getHighestDataRow();
        $totalColumns = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $firstRow = max(1, min($firstRow, $totalRows));
        $lastRow = min($firstRow + 99, $totalRows);
        $lastColumn = min(80, $totalColumns);
        $spans = [];
        $covered = [];
        foreach ($sheet->getMergeCells() as $range) {
            [[$left, $top], [$right, $bottom]] = Coordinate::rangeBoundaries($range);
            if ($bottom < $firstRow || $top > $lastRow || $left > $lastColumn) {
                continue;
            }
            $visibleTop = max($top, $firstRow);
            $visibleBottom = min($bottom, $lastRow);
            $visibleRight = min($right, $lastColumn);
            $anchor = Coordinate::stringFromColumnIndex($left).$visibleTop;
            $spans[$anchor] = ['colspan' => $visibleRight - $left + 1, 'rowspan' => $visibleBottom - $visibleTop + 1, 'source' => Coordinate::stringFromColumnIndex($left).$top];
            for ($r = $visibleTop; $r <= $visibleBottom; $r++) {
                for ($c = $left; $c <= $visibleRight; $c++) {
                    $address = Coordinate::stringFromColumnIndex($c).$r;
                    if ($address !== $anchor) {
                        $covered[$address] = true;
                    }
                }
            }
        }
        $columns = [];
        for ($c = 1; $c <= $lastColumn; $c++) {
            $letter = Coordinate::stringFromColumnIndex($c);
            $width = $sheet->getColumnDimension($letter)->getWidth();
            $columns[] = ['letter' => $letter, 'width' => max(55, min(280, $width > 0 ? (int) ($width * 7 + 12) : 105))];
        }
        $rows = [];
        $externalFormulaCount = 0;
        foreach ($sheet->getCellCollection()->getCoordinates() as $address) {
            $cell = $sheet->getCell($address);
            if ($cell->getDataType() === DataType::TYPE_FORMULA && preg_match('/\[[^\]]+\]/', (string) $cell->getValue())) {
                $externalFormulaCount++;
            }
        }
        for ($r = $firstRow; $r <= $lastRow; $r++) {
            $cells = [];
            for ($c = 1; $c <= $lastColumn; $c++) {
                $address = Coordinate::stringFromColumnIndex($c).$r;
                if (isset($covered[$address])) {
                    continue;
                }
                $span = $spans[$address] ?? ['colspan' => 1, 'rowspan' => 1, 'source' => $address];
                $cell = $sheet->getCell($span['source']);
                $raw = $cell->getValue();
                $raw = $raw instanceof RichText ? $raw->getPlainText() : $raw;
                $formula = $cell->getDataType() === DataType::TYPE_FORMULA ? (string) $raw : null;
                // Never evaluate workbook formulas on the server, including in the preview.
                $cached = $formula ? $cell->getOldCalculatedValue() : null;
                $display = $formula ? 'ƒx' : (string) ($raw ?? '');
                if (! $formula && is_numeric($raw)) {
                    $display = (string) NumberFormat::toFormattedString($raw, $cell->getStyle()->getNumberFormat()->getFormatCode());
                }
                $style = $cell->getStyle();
                $fill = $style->getFill()->getFillType() === 'solid' ? $style->getFill()->getStartColor()->getRGB() : 'FFFFFF';
                $color = $style->getFont()->getColor()->getRGB();
                $fill = preg_match('/^[A-Fa-f0-9]{6}$/', $fill ?? '') ? $fill : 'FFFFFF';
                $color = preg_match('/^[A-Fa-f0-9]{6}$/', $color ?? '') ? $color : '111827';
                $align = $style->getAlignment()->getHorizontal();
                $align = in_array($align, ['left', 'center', 'right'], true) ? $align : (is_numeric($raw) ? 'right' : 'left');
                $cells[] = [
                    'address' => $address, 'display' => $display, 'formula' => $formula,
                    'cached' => $cached === null ? null : (string) $cached,
                    'colspan' => $span['colspan'], 'rowspan' => $span['rowspan'],
                    'changed' => isset($changes[$address]), 'before' => $changes[$address]['before'] ?? null,
                    'style' => "background:#{$fill};color:#{$color};text-align:{$align};font-weight:".($style->getFont()->getBold() ? '700' : '400').';font-style:'.($style->getFont()->getItalic() ? 'italic' : 'normal'),
                ];
            }
            $rows[] = ['number' => $r, 'cells' => $cells];
        }

        return ['columns' => $columns, 'rows' => $rows, 'first_row' => $firstRow, 'last_row' => $lastRow, 'total_rows' => $totalRows, 'total_columns' => $totalColumns, 'external_formula_count' => $externalFormulaCount];
    }
}
