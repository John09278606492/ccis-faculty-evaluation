<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

/** Read the original workbook, projecting only offering fields. Never calculate formulas. */
class InstructorOfferingFileReader
{
    private const ALIASES = [
        'sy' => ['sy', 'schoolyear', 'academicyear'], 'sem' => ['sem', 'semester', 'term'],
        'course_code' => ['coursecode', 'subjectcode'], 'course_desc' => ['coursedesc', 'coursedescription', 'coursetitle', 'subjectdescription', 'subjecttitle', 'description'],
        'units' => ['units', 'totalunits', 'creditunits'], 'section' => ['section', 'sectionname', 'classsection'],
        'id' => ['id', 'facultyid', 'instructorid', 'teacherid', 'sourcefacultyid'],
        'first_name' => ['firstname', 'facultyfirstname', 'instructorfirstname'], 'last_name' => ['lastname', 'surname', 'facultylastname', 'instructorlastname'],
        'campus_id' => ['campusid', 'sourcecampusid'], 'code' => ['code', 'programcode', 'degreeprogramcode', 'curriculumcode'],
        'name' => ['name', 'programname', 'degreeprogram', 'degreeprogramname', 'curriculumname'],
        'year' => ['year', 'curriculumyear'], 'sem-2' => ['sem2', 'curriculumsemester'],
        'lec_units' => ['lecunits', 'lectureunits'], 'lab_units' => ['labunits', 'laboratoryunits'],
        'id-2' => ['id2', 'programid'], 'grade_system' => ['gradesystem', 'gradingsystem'], 'is_active' => ['isactive'],
        'created_at' => ['createdat'], 'updated_at' => ['updatedat'], 'updated_by' => ['updatedby'],
    ];

    private function header(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(ltrim(trim((string) $value), "\xEF\xBB\xBF")));
    }

    private function columns(array $labels): array
    {
        $mapped = [];
        foreach (self::ALIASES as $field => $aliases) {
            $matches = array_keys(array_intersect($labels, $aliases));
            if (count($matches) === 1) {
                $mapped[$field] = $matches[0];
            }
        }

        return array_diff(InstructorOfferingImports::REQUIRED_COLUMNS, array_keys($mapped)) ? [] : $mapped;
    }

    private function filter(array $maps = []): IReadFilter
    {
        return new class($maps) implements IReadFilter
        {
            public function __construct(private array $maps) {}

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                $col = Coordinate::columnIndexFromString($columnAddress);
                if (! $this->maps) {
                    return $row <= 50 && $col <= 512;
                }
                // The CSV reader passes no worksheet name to its read filter.
                $map = $this->maps[$worksheetName] ?? ($worksheetName === '' && count($this->maps) === 1 ? array_values($this->maps)[0] : null);

                return $map && $row > $map['header_row'] && $row <= 20050 && in_array($col, $map['columns'], true);
            }
        };
    }

    public function read(string $path): array
    {
        if (! is_file($path) || filesize($path) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'Upload the original CSV or Excel file, up to 5 MB.']);
        }
        $reader = IOFactory::createReaderForFile($path, ['Csv', 'Xls', 'Xlsx']);
        if ($reader instanceof Csv) {
            return $this->readCsv($path, $reader);
        }
        if ($reader instanceof Xlsx) {
            $zip = new \ZipArchive;
            if ($zip->open($path) !== true) {
                throw ValidationException::withMessages(['file' => 'The Excel workbook is damaged. Upload the original export again.']);
            }
            try {
                $size = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $size += $zip->statIndex($i)['size'];
                }
                if ($size > 128 * 1024 * 1024 || $zip->numFiles > 2000) {
                    throw ValidationException::withMessages(['file' => 'The expanded workbook exceeds the supported size.']);
                }
            } finally {
                $zip->close();
            }
        }
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }
        $info = collect($reader->listWorksheetInfo($path))->keyBy('worksheetName');
        $reader->setReadFilter($this->filter());
        $scan = $reader->load($path);
        $maps = $ignored = [];
        try {
            foreach ($scan->getWorksheetIterator() as $sheet) {
                $end = min(512, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));
                for ($row = 1; $row <= min(50, $sheet->getHighestDataRow()); $row++) {
                    $labels = [];
                    for ($col = 1; $col <= $end; $col++) {
                        $labels[$col] = $this->header($sheet->getCell([$col, $row])->getValue());
                    }
                    $columns = $this->columns($labels);
                    if ($columns) {
                        $maps[$sheet->getTitle()] = ['sheet' => $sheet->getTitle(), 'header_row' => $row, 'columns' => $columns,
                            'ignored_columns' => count(array_filter($labels, fn ($v) => $v !== '')) - count($columns)];
                        break;
                    }
                }
                if (! isset($maps[$sheet->getTitle()])) {
                    $ignored[] = $sheet->getTitle();
                }
            }
        } finally {
            $scan->disconnectWorksheets();
        }
        if (! $maps) {
            throw ValidationException::withMessages(['file' => 'No instructor offering headers were found in the first 50 rows. Required fields: '.implode(', ', InstructorOfferingImports::REQUIRED_COLUMNS).'. Duplicate required fields must be distinguishable; extra columns are ignored.']);
        }
        if (count($maps) > 20 || collect($maps)->contains(fn ($map) => ($info->get($map['sheet'])['totalRows'] ?? 0) - $map['header_row'] > 20000)) {
            throw ValidationException::withMessages(['file' => 'The offering sheets exceed 20,000 rows or 20 supported worksheets.']);
        }
        $reader->setLoadSheetsOnly(array_keys($maps));
        $reader->setReadFilter($this->filter($maps));
        $book = $reader->load($path);
        $rows = [];
        $repeated = 0;
        try {
            foreach ($book->getWorksheetIterator() as $sheet) {
                $map = $maps[$sheet->getTitle()] ?? null;
                if (! $map) {
                    continue;
                }
                $last = $sheet->getHighestDataRow();
                for ($line = $map['header_row'] + 1; $line <= $last; $line++) {
                    $values = $errors = [];
                    foreach ($map['columns'] as $field => $col) {
                        $cell = $sheet->cellExists([$col, $line]) ? $sheet->getCell([$col, $line]) : null;
                        $values[$field] = $this->value($cell, $errors, $field);
                    }
                    if (! array_filter($values, fn ($v) => $v !== '') && ! $errors) {
                        continue;
                    }
                    if ($this->columns(array_map(fn ($v) => $this->header($v), $values))) {
                        $repeated++;

                        continue;
                    }
                    $first = $this->header(collect($values)->first(fn ($v) => $v !== ''));
                    if (in_array($first, ['total', 'grandtotal'], true) && blank($values['id']) && blank($values['course_code'])) {
                        continue;
                    }
                    $rows[] = [...$values, '_row' => $line, '_sheet' => $sheet->getTitle(), '_read_errors' => $errors];
                    if (count($rows) > 20000) {
                        throw ValidationException::withMessages(['file' => 'The offering sheets exceed 20,000 data rows.']);
                    }
                }
            }
        } finally {
            $book->disconnectWorksheets();
        }

        return ['rows' => $rows, 'sources' => array_values(array_map(fn ($m) => Arr::except($m, ['columns']), $maps)),
            'ignored_sheets' => $ignored, 'repeated_headers' => $repeated];
    }

    private function value(?Cell $cell, array &$errors, string $field): string
    {
        if (! $cell) {
            return '';
        }
        if (in_array($cell->getDataType(), [DataType::TYPE_FORMULA, DataType::TYPE_ERROR], true)) {
            if (in_array($field, InstructorOfferingImports::REQUIRED_COLUMNS, true)) {
                $errors[] = 'Replace the formula or Excel error in '.$field.' ('.$cell->getCoordinate().') with its actual value.';
            }

            return '';
        }
        $value = $cell->getValue();
        if (in_array($field, ['id', 'campus_id', 'id-2'], true) && (is_float($value) || is_int($value))) {
            if ($value < 0 || floor($value) !== (float) $value || strlen(sprintf('%.0f', $value)) > 15) {
                $errors[] = 'Source IDs must be text or whole numbers of at most 15 digits; Excel may have rounded a longer ID.';
            }
            $format = $cell->getStyle()->getNumberFormat()->getFormatCode();

            return preg_match('/^0+$/', $format) ? str_pad(sprintf('%.0f', $value), strlen($format), '0', STR_PAD_LEFT) : sprintf('%.0f', $value);
        }

        return trim((string) $value);
    }

    private function readCsv(string $path, Csv $reader): array
    {
        // Use the reader's delimiter detection, then stream rows without creating
        // tens of thousands of spreadsheet cell objects for an ordinary CSV.
        $reader->listWorksheetInfo($path);
        $bytes = file_get_contents($path);
        if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            $bytes = mb_convert_encoding($bytes, 'UTF-8', str_starts_with($bytes, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $bytes);
        rewind($stream);
        $columns = [];
        $rows = [];
        $line = $repeated = $headerRow = $ignoredColumns = 0;
        try {
            while (($raw = fgetcsv($stream, 0, $reader->getDelimiter() ?: ',', $reader->getEnclosure(), '')) !== false) {
                $line++;
                if (! $columns) {
                    if ($line > 50) {
                        break;
                    }
                    $labels = [];
                    foreach ($raw as $col => $value) {
                        $labels[$col + 1] = $this->header($value);
                    }
                    $columns = $this->columns($labels);
                    if ($columns) {
                        $headerRow = $line;
                        $ignoredColumns = count(array_filter($labels, fn ($v) => $v !== '')) - count($columns);
                    }

                    continue;
                }
                $values = $errors = [];
                foreach ($columns as $field => $col) {
                    $value = trim((string) ($raw[$col - 1] ?? ''));
                    if (in_array($field, InstructorOfferingImports::REQUIRED_COLUMNS, true)) {
                        if (! mb_check_encoding($value, 'UTF-8')) {
                            $errors[] = 'Save the file as UTF-8 CSV to preserve '.$field.'.';
                        }
                        if (str_starts_with($value, '=') || in_array($value, ['#DIV/0!', '#VALUE!', '#N/A', '#REF!', '#NAME?', '#NUM!', '#NULL!'], true)) {
                            $errors[] = 'Replace the formula or Excel error in '.$field.' with its actual value.';
                        }
                    }
                    $values[$field] = $value;
                }
                if (! array_filter($values, fn ($v) => $v !== '') && ! $errors) {
                    continue;
                }
                if ($this->columns(array_map(fn ($v) => $this->header($v), $values))) {
                    $repeated++;

                    continue;
                }
                $first = $this->header(collect($values)->first(fn ($v) => $v !== ''));
                if (in_array($first, ['total', 'grandtotal'], true) && blank($values['id']) && blank($values['course_code'])) {
                    continue;
                }
                $rows[] = [...$values, '_row' => $line, '_sheet' => 'Worksheet', '_read_errors' => $errors];
                if (count($rows) > 20000) {
                    throw ValidationException::withMessages(['file' => 'The offering file exceeds 20,000 data rows.']);
                }
            }
        } finally {
            fclose($stream);
        }
        if (! $columns) {
            throw ValidationException::withMessages(['file' => 'No instructor offering headers were found in the first 50 rows. Required fields: '.implode(', ', InstructorOfferingImports::REQUIRED_COLUMNS).'. Extra columns are ignored.']);
        }

        return ['rows' => $rows, 'sources' => [['sheet' => 'Worksheet', 'header_row' => $headerRow, 'ignored_columns' => $ignoredColumns]],
            'ignored_sheets' => [], 'repeated_headers' => $repeated];
    }
}
