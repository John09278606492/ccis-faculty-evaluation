<?php

use App\Models\QuizQuestion;

/**
 * The code editor and the exam preview.
 *
 * The JavaScript here is checked by reading the built files, because the
 * behaviour itself needs a browser: the editor is a CodeMirror component and
 * the preview runs in a sandboxed frame.
 */
it('offers code suggestions for a new code question', function () {
    $question = new QuizQuestion(['type' => 'code']);

    // On unless the instructor turns it off.
    expect($question->allowsAutocomplete())->toBeTrue();
});

it('lets the instructor turn code suggestions off', function () {
    $question = new QuizQuestion(['type' => 'code', 'options' => ['autocomplete' => false]]);

    expect($question->allowsAutocomplete())->toBeFalse();
});

it('keeps code suggestions on for a question saved before the option existed', function () {
    $question = new QuizQuestion(['type' => 'code', 'options' => []]);

    expect($question->allowsAutocomplete())->toBeTrue();
});

it('inlines an external script into the preview instead of using a data url', function () {
    $runner = file_get_contents(resource_path('js/components/code-runner.js'));

    // Chrome refuses to run data: URL scripts inside the sandboxed frame the
    // preview uses, so a separate .js file would never run at all.
    expect($runner)->not->toContain('data:text/javascript')
        ->toContain('js.replace(/<\/script/gi');
});

it('keeps the browser globals in the editor\'s completion list', function () {
    $completions = file_get_contents(resource_path('js/components/js-completions.js'));

    expect($completions)->toContain('document')
        ->toContain('getElementById')
        ->toContain('console');
});

it('registers the completion sources with the languages', function () {
    $editor = file_get_contents(resource_path('js/components/code-editor.js'));

    expect($editor)->toContain('javascriptCompletion')
        ->toContain('htmlCompletion');
});

it('offers the preview next to the code and updates it as the student types', function () {
    $editor = file_get_contents(resource_path('js/components/code-editor.js'));
    $view = file_get_contents(resource_path('views/components/code-editor.blade.php'));

    // The split view: the code on one side, the preview on the other.
    expect($editor)->toContain('sideBySide')
        ->toContain('livePreview')
        ->toContain('scheduleLivePreview')
        ->toContain('refreshLivePreview')
        ->toContain('placePreview');

    expect($view)->toContain('cq-split-preview')
        ->toContain('toggleSideBySide()')
        ->toContain('toggleLivePreview()');
});

it('puts the preview frame where the layout wants it', function () {
    $editor = file_get_contents(resource_path('js/components/code-editor.js'));

    // One frame, moved between the panel below and the side-by-side host, so
    // switching the layout never reloads the page.
    expect($editor)->toContain('this.$refs.splitHost')
        ->toContain('this.$refs.panelHost')
        ->toContain('host.appendChild(frame)');
});

it('spots a tag the student left open', function () {
    // A tag left open swallows everything after it, so a <script> that follows
    // shows up as plain text in the preview instead of running.
    $runner = file_get_contents(resource_path('js/components/code-runner.js'));

    expect($runner)->toContain('export function unclosedTag')
        ->toContain('is not closed');
});

it('marks the unclosed tag in the editor', function () {
    $check = file_get_contents(resource_path('js/components/html-tag-check.js'));

    expect($check)->toContain('export function unclosedTags')
        ->toContain('cq-tag-unclosed');
});

it('treats a php file that holds only html as a web page', function () {
    // A .php file with no PHP in it is a web page: it shows in the preview
    // straight away and follows the code as the student types, instead of
    // waiting for the PHP runtime.
    $runner = file_get_contents(resource_path('js/components/code-runner.js'));

    expect($runner)->toContain('export function hasPhpCode')
        ->toContain('files.some((file) => hasPhpCode(file))');
});

it('keeps a file that really has php on the php runner', function () {
    $runner = file_get_contents(resource_path('js/components/code-runner.js'));

    // The PHP runtime is only used when the file really contains PHP.
    expect($runner)->toContain('export function hasPhpCode')
        ->toContain('/<\\?php|<\\?=/');
});
