<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Students can now create files and folders in code questions unless the instructor
 * turns it off. Questions saved before had it off by default, so turn it on for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('quiz_questions')->where('type', 'code')->orderBy('id')->each(function (object $question) {
            $options = json_decode($question->options ?? '[]', true) ?: [];
            if (($options['allow_student_files'] ?? null) === true) {
                return;
            }
            $options['allow_student_files'] = true;
            DB::table('quiz_questions')->where('id', $question->id)->update(['options' => json_encode($options)]);
        });
    }

    public function down(): void
    {
        // Leaves the setting as it is: instructors can switch it off per question.
    }
};
