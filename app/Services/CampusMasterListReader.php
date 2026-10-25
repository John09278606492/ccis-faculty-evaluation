<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\Semester;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

/** Reads the campus report directly; never evaluates spreadsheet formulas. */
class CampusMasterListReader
{
    private const ALIASES = [
        'student_id' => ['studentid', 'idno', 'idnumber', 'studid'],
        'first_name' => ['firstname', 'givenname'], 'last_name' => ['lastname', 'surname'],
        'middle_name' => ['middlename'], 'department_code' => ['departmentcode', 'college', 'department'],
        'department_name' => ['departmentname', 'collegename'],
        'email' => ['email', 'emailaddress'], 'sections' => ['sections', 'section'],
        'program' => ['program', 'degreeprogram', 'course'], 'reported_year_level' => ['yearlevel', 'reportedyearlevel'],
        'reported_sex' => ['sex', 'gender', 'reportedsex'], 'total_units' => ['totalunits'],
    ];

    private function header(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(ltrim(trim((string) $value), "\xEF\xBB\xBF")));
    }

    public function read(string $path): array
    {
        if (! is_file($path) || filesize($path) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'Upload a CSV or Excel file of at most 5 MB.']);
        }
        $reader = IOFactory::createReaderForFile($path, ['Csv', 'Xls', 'Xlsx']);
        if ($reader instanceof Xlsx) {
            $zip = new \ZipArchive;
            if ($zip->open($path) !== true) {
                throw ValidationException::withMessages(['file' => 'The Excel workbook is damaged.']);
            }
            try {
                $size = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $size += $zip->statIndex($i)['size'];
                }
                if ($size > 128 * 1024 * 1024 || $zip->numFiles > 2000) {
                    throw ValidationException::withMessages(['file' => 'The expanded workbook is too large. Export the student list without extra worksheets or embedded content.']);
                }
            } finally {
                $zip->close();
            }
        }
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }
        $info = $reader->listWorksheetInfo($path);
        if (count($info) > 10 || collect($info)->contains(fn ($s) => $s['totalRows'] > 5050 || $s['totalColumns'] > 256)) {
            throw ValidationException::withMessages(['file' => 'Use up to 5,000 student rows, 256 columns and 10 worksheets per upload.']);
        }
        $book = $reader->load($path);
        $rows = $sources = [];
        try {
            foreach ($book->getWorksheetIterator() as $sheet) {
                // Dimension lookups scan the cell collection; never repeat them per cell/row.
                $lastRow = $sheet->getHighestDataRow();
                $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
                $headerRow = null;
                $columns = [];
                $blocks = [];
                for ($line = 1; $line <= min(50, $lastRow); $line++) {
                    $labels = [];
                    for ($col = 1; $col <= $lastColumn; $col++) {
                        $labels[$col] = $this->header($sheet->getCell([$col, $line])->getValue());
                    }
                    $mapped = [];
                    foreach (self::ALIASES as $key => $aliases) {
                        $matches = array_keys(array_intersect($labels, $aliases));
                        if (count($matches) > 1) {
                            continue;
                        }
                        if ($matches) {
                            $mapped[$key] = $matches[0];
                        }
                    }
                    if (array_diff(['student_id', 'first_name', 'last_name', 'department_code'], array_keys($mapped))) {
                        continue;
                    }
                    $headerRow = $line;
                    $columns = $mapped;
                    foreach ($labels as $col => $label) {
                        if ($label === 'subjectcode') {
                            if (array_slice(array_values($labels), $col - 1, 5) !== ['subjectcode', 'description', 'lec', 'lab', 'units']) {
                                throw ValidationException::withMessages(['file' => 'Each Subject Code must be followed by Description, Lec, Lab and Units.']);
                            }
                            $blocks[] = $col;
                        }
                    }
                    break;
                }
                if (! $headerRow) {
                    if ($lastRow > 7) {
                        throw ValidationException::withMessages(['file' => 'No student headers found in worksheet '.$sheet->getTitle().'. Upload the campus student-list report or the example CSV.']);
                    }

                    continue;
                }
                $meta = ['sheet' => $sheet->getTitle(), 'header_row' => $headerRow, 'format' => $blocks ? 'Campus student master list' : 'Student CSV / Excel'];
                for ($line = 1; $line < $headerRow; $line++) {
                    $text = trim((string) $sheet->getCell([1, $line])->getValue());
                    if (preg_match('/semester\s*:\s*(1|2|3|summer)\s+school\s+year\s*:\s*(\d{4})\s*[-–]\s*(\d{4})/iu', $text, $m)) {
                        $meta['semester_number'] = strtolower($m[1]);
                        $meta['school_year'] = $m[2].'-'.$m[3];
                    } elseif (preg_match('/\bcampus\b/iu', $text)) {
                        $meta['campus'] = $text;
                    }
                }
                $sources[] = $meta;
                for ($line = $headerRow + 1; $line <= $lastRow; $line++) {
                    $values = $errors = [];
                    foreach ($columns as $key => $col) {
                        $values[$key] = $this->value($sheet->cellExists([$col, $line]) ? $sheet->getCell([$col, $line]) : null, $errors, $key);
                    }
                    // Ignore empty rows and repeated printed headers, never a partial student row.
                    if (($errors === [] && collect($values)->every(fn ($v) => $v === '')) || $this->header($values['student_id']) === $this->header($sheet->getCell([$columns['student_id'], $headerRow])->getValue())) {
                        continue;
                    }
                    $values['department_code'] = mb_strtoupper($values['department_code']);
                    if (isset($values['program'])) {
                        $values['program'] = mb_strtoupper($values['program']);
                    }
                    if ($blocks) {
                        $subjects = [];
                        foreach ($blocks as $start) {
                            $subject = [];
                            foreach (['subject_code', 'description', 'lecture', 'laboratory', 'units'] as $offset => $key) {
                                $subject[$key] = $this->value($sheet->cellExists([$start + $offset, $line]) ? $sheet->getCell([$start + $offset, $line]) : null, $errors, $key);
                            }
                            if (collect($subject)->every(fn ($v) => $v === '')) {
                                continue;
                            }
                            $subject['subject_code'] = StudentAcademicRecords::code($subject['subject_code']);
                            $subjects[] = $subject;
                        }
                        $values['subjects'] = $subjects;
                    }
                    $rows[] = [...$values, '_source_row' => $line, '_source_sheet' => $sheet->getTitle(), '_read_errors' => array_values(array_unique($errors))];
                    if (count($rows) > 5000) {
                        throw ValidationException::withMessages(['file' => 'Import up to 5,000 student rows per file.']);
                    }
                }
            }
            if (! $sources) {
                throw ValidationException::withMessages(['file' => 'No supported student list found. Upload the campus report or example CSV.']);
            }

            return ['rows' => $rows, 'sources' => $sources];
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function value(?Cell $cell, array &$errors, string $field): string
    {
        if (! $cell) {
            return '';
        }
        if ($cell->getDataType() === DataType::TYPE_FORMULA || $cell->getDataType() === DataType::TYPE_ERROR) {
            $errors[] = 'Replace the formula or Excel error in '.$field.' with its actual value.';

            return '';
        }
        $raw = $cell->getValue();
        if ($field === 'student_id' && (is_int($raw) || is_float($raw))) {
            if ($raw < 0 || floor($raw) !== (float) $raw || strlen(sprintf('%.0f', $raw)) > 15) {
                $errors[] = 'Student ID must be text or a whole number of at most 15 digits. Excel may have rounded a longer numeric ID.';
            }
            // Preserve explicit zero-padding without evaluating cell content.
            $format = $cell->getStyle()->getNumberFormat()->getFormatCode();

            return preg_match('/^0+$/', $format) ? str_pad(sprintf('%.0f', $raw), strlen($format), '0', STR_PAD_LEFT) : sprintf('%.0f', $raw);
        }

        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    public function period(Campus $campus, array $sources, int $fallback): int
    {
        $ids = [];
        foreach ($sources as $source) {
            $normalCampus = fn ($v) => preg_replace('/[^\pL\pN]/u', '', mb_strtolower(preg_replace('/\bcampus\b/iu', '', $v)));
            if (isset($source['campus']) && $normalCampus($source['campus']) !== $normalCampus($campus->name)) {
                throw ValidationException::withMessages(['file' => 'The report campus does not match the selected campus. Switch to the correct campus before uploading.']);
            }
            if (! isset($source['school_year'])) {
                continue;
            }
            $matches = Semester::whereHas('schoolYear', fn ($q) => $q->where('campus_id', $campus->id))->with('schoolYear')->get()->filter(function ($period) use ($source) {
                preg_match('/\d{4}\s*[-–]\s*\d{4}/u', $period->schoolYear->name, $year);
                $name = strtolower($period->name);
                $number = preg_match('/\b(first|1st|1)\b/', $name) ? '1' : (preg_match('/\b(second|2nd|2)\b/', $name) ? '2' : (str_contains($name, 'summer') ? 'summer' : (preg_match('/\b(third|3rd|3)\b/', $name) ? '3' : '')));

                return preg_replace('/\s/', '', $year[0] ?? '') === $source['school_year'] && $number === $source['semester_number'];
            });
            if ($matches->count() !== 1) {
                throw ValidationException::withMessages(['semester_id' => 'The report specifies semester '.$source['semester_number'].' / '.$source['school_year'].'. Create or correct that academic period in this campus first; it must match exactly one period.']);
            }
            $ids[] = $matches->first()->id;
        }
        if (count(array_unique($ids)) > 1) {
            throw ValidationException::withMessages(['file' => 'The worksheets contain different academic periods. Upload one period at a time.']);
        }

        return (int) ($ids[0] ?? AcademicPeriod::validate($campus, $fallback));
    }
}
