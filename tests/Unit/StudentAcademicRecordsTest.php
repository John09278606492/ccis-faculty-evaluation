<?php

use App\Services\StudentAcademicRecords;

it('accepts a zero-unit academic record without turning zero into missing data', function () {
    expect((new StudentAcademicRecords)->errors(['program' => 'BSCS', 'reported_year_level' => '1', 'total_units' => '0', 'subjects' => []]))->toBe([]);
});

it('rejects invalid source academic values', function (array $row) {
    expect((new StudentAcademicRecords)->errors($row))->not->toBeEmpty();
})->with([
    'negative units' => [['total_units' => -1]],
    'fractional year' => [['reported_year_level' => '1.5']],
    'infinite units' => [['total_units' => INF]],
    'missing subject description' => [['subjects' => [['subject_code' => 'CS101', 'description' => '', 'units' => 3]]]],
    'negative subject units' => [['subjects' => [['subject_code' => 'CS101', 'description' => 'Computing', 'units' => -1]]]],
    'duplicate subject' => [['subjects' => [['subject_code' => 'CS101', 'description' => 'Computing', 'units' => 3], ['subject_code' => 'CS101', 'description' => 'Computing', 'units' => 3]]]],
]);
