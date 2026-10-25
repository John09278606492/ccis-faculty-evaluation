<?php

namespace App\Console\Commands;

use App\Models\Quiz;
use Illuminate\Console\Command;

/**
 * Shows pictures of the expected output under one question (until the question
 * builder can upload them). Each image is "path/under/public.png::Caption".
 */
class SetExpectedOutput extends Command
{
    protected $signature = 'classroom:set-expected-output {quiz : Assessment id} {question : Question number, from 1} {images* : public/ path, optionally followed by ::caption}';

    protected $description = 'Show expected-output pictures under a question';

    public function handle(): int
    {
        $question = Quiz::withoutGlobalScopes()->find($this->argument('quiz'))?->questions()->where('order', (int) $this->argument('question'))->first();
        if (! $question) {
            $this->error('No such assessment or question.');

            return self::FAILURE;
        }
        $images = [];
        foreach ($this->argument('images') as $image) {
            [$path, $caption] = array_pad(explode('::', $image, 2), 2, '');
            $path = ltrim(str_replace('\\', '/', $path), '/');
            if (str_contains($path, '..') || ! is_file(public_path($path))) {
                $this->error("public/{$path} was not found.");

                return self::FAILURE;
            }
            $images[] = ['path' => $path, 'caption' => $caption];
        }
        $question->update(['options' => [...($question->options ?? []), 'expected_output' => $images]]);
        $this->info('Question '.$question->order.' now shows '.count($images).' expected-output picture(s).');

        return self::SUCCESS;
    }
}
