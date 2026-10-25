<?php

namespace App\Console\Commands;

use App\Models\CourseOffering;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Adds a prepared assessment (JSON) to a class as an unpublished draft, so the
 * instructor can preview it and publish it from the Assessments page.
 * Without --offering it only lists the classes that match --find.
 */
class ImportAssessment extends Command
{
    protected $signature = 'classroom:import-assessment {file} {--offering= : Class (course offering) id} {--find= : Words to look for in the course and section, e.g. "3C"}';

    protected $description = 'Add a prepared assessment to a class as an unpublished draft';

    public function handle(): int
    {
        $definition = json_decode((string) @file_get_contents($this->argument('file')), true);
        if (! is_array($definition) || blank($definition['title'] ?? null) || ! is_array($definition['questions'] ?? null) || $definition['questions'] === []) {
            $this->error('The file is not an assessment definition (title and questions are required).');

            return self::FAILURE;
        }

        if (! $this->option('offering')) {
            $this->listClasses((string) $this->option('find'));
            $this->line('Run again with --offering=ID to add "'.$definition['title'].'" to one of these classes.');

            return self::FAILURE;
        }

        $offering = CourseOffering::withoutGlobalScopes()->with('course')->find($this->option('offering'));
        if (! $offering) {
            $this->error('No class has id '.$this->option('offering').'.');

            return self::FAILURE;
        }
        if (Quiz::withoutGlobalScopes()->where('course_offering_id', $offering->id)->where('title', $definition['title'])->exists()) {
            $this->error('This class already has an assessment called "'.$definition['title'].'". Nothing was changed.');

            return self::FAILURE;
        }

        $quiz = DB::transaction(function () use ($definition, $offering) {
            $quiz = Quiz::create([
                'course_offering_id' => $offering->id,
                'auto_sync_class_record' => false,
                'title' => $definition['title'],
                'instructions' => $definition['instructions'] ?? null,
                'type' => array_key_exists($definition['type'] ?? '', Quiz::TYPES) ? $definition['type'] : 'exam',
                'duration_minutes' => $definition['duration_minutes'] ?? null,
                'shuffle_questions' => (bool) ($definition['shuffle_questions'] ?? false),
                'max_attempts' => (int) ($definition['max_attempts'] ?? 1),
                'results_visibility' => array_key_exists($definition['results_visibility'] ?? '', Quiz::RESULTS_VISIBILITY) ? $definition['results_visibility'] : 'manual',
                'is_published' => false,
                'assign_to_all' => true,
            ]);
            foreach (array_values($definition['questions']) as $index => $question) {
                abort_unless(array_key_exists($question['type'] ?? '', QuizQuestion::TYPES), 422, 'Question '.($index + 1).' has an unknown type.');
                $quiz->questions()->create([
                    'type' => $question['type'],
                    'question_text' => $question['question_text'] ?? '',
                    'points' => (int) ($question['points'] ?? 1),
                    'order' => $index + 1,
                    'options' => $question['options'] ?? null,
                    'correct_answer' => $question['correct_answer'] ?? null,
                ]);
            }

            return $quiz;
        });

        $this->info("Added \"{$quiz->title}\" (id {$quiz->id}) to {$offering->course?->code} {$offering->section} as an unpublished draft: ".$quiz->questions()->count().' questions, '.$quiz->questions()->sum('points').' points.');
        foreach ($quiz->publicationIssues() as $issue) {
            $this->warn('Before publishing: '.$issue);
        }

        return self::SUCCESS;
    }

    private function listClasses(string $find): void
    {
        $words = array_filter(preg_split('/\s+/', strtoupper($find)));
        $normalize = fn (?string $text) => preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $text));
        $rows = CourseOffering::withoutGlobalScopes()->with(['course' => fn ($q) => $q->withoutGlobalScopes(), 'instructor' => fn ($q) => $q->withoutGlobalScopes()])->withCount('students')->get()
            ->filter(function (CourseOffering $offering) use ($words, $normalize) {
                $haystack = $normalize($offering->course?->code.' '.$offering->course?->name.' '.$offering->section);

                return collect($words)->every(fn ($word) => str_contains($haystack, $normalize($word)));
            })
            ->map(fn (CourseOffering $offering) => [$offering->id, $offering->course?->code, $offering->course?->name, $offering->section, trim($offering->instructor?->first_name.' '.$offering->instructor?->last_name), $offering->students_count, $offering->is_active ? 'yes' : 'no']);

        $this->table(['id', 'Course code', 'Course', 'Section', 'Instructor', 'Students', 'Active'], $rows->values()->all());
    }
}
