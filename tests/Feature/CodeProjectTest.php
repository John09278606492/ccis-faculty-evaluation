<?php

use App\Support\CodeProject;

it('reads the starter files of a language preset', function () {
    $php = '<?php echo 1;';

    expect(CodeProject::preset('web'))->toHaveCount(3)
        ->and(CodeProject::preset('web')[0])->toBe(['name' => 'index.html', 'content' => ''])
        ->and(CodeProject::preset('php'))->toBe([['name' => 'index.php', 'content' => '']])
        ->and(CodeProject::preset('php', $php)[0]['content'])->toBe($php);
});

it('falls back to plain text for an unknown language', function () {
    expect(CodeProject::preset('cobol'))->toBe([['name' => 'main.txt', 'content' => '']]);
});

it('rejects a file list that is not a list of files', function () {
    expect(CodeProject::normalize('nope'))->toBeNull()
        ->and(CodeProject::normalize(['name' => 'a.txt']))->toBeNull()
        ->and(CodeProject::normalize([['name' => 'a.txt', 'content' => 'x']]))->not->toBeNull();
});

it('reads a saved project and keeps only the folders that hold no file', function () {
    $stored = CodeProject::encode(
        [['name' => 'css/style.css', 'content' => 'body{}']],
        ['css', 'empty']
    );

    expect(CodeProject::decode($stored, []))->toBe([['name' => 'css/style.css', 'content' => 'body{}']])
        ->and(CodeProject::decodeFolders($stored))->toBe(['empty']);
});

it('reads old single-file answers as one file', function () {
    $files = CodeProject::decode('echo "hi";', [['name' => 'index.php', 'content' => '']]);

    expect($files)->toHaveCount(1)
        ->and($files[0]['name'])->toBe('index.php')
        ->and($files[0]['content'])->toBe('echo "hi";');
});

it('drops folders that hold a file', function () {
    $stored = CodeProject::encode([['name' => 'css/style.css', 'content' => '']], ['css', 'keep-me']);

    expect(CodeProject::decodeFolders($stored))->toBe(['keep-me']);
});

it('accepts a valid project and reports the problems in an invalid one', function () {
    expect(CodeProject::issues([['name' => 'index.html', 'content' => '']]))->toBeEmpty();

    expect(CodeProject::issues([['name' => 'index.html', 'content' => ''], ['name' => 'index.html', 'content' => '']]))
        ->toContain('File names must be unique.');

    expect(CodeProject::issues([['name' => 'index.exe', 'content' => '']]))
        ->toContain('"index.exe" is not a valid file name. Use letters, numbers, - or _, folders separated by /, and an extension such as .html, .css, .js, .php or .sql.');

    expect(CodeProject::issues([]))
        ->toContain('Use between 1 and '.CodeProject::MAX_FILES.' files.');
});

it('rejects a file name that tries to escape the workspace', function () {
    expect(CodeProject::isValidName('../secret.txt'))->toBeFalse()
        ->and(CodeProject::isValidName('a/b/c/d/e/file.txt'))->toBeFalse()
        ->and(CodeProject::isValidName('a b.txt'))->toBeFalse()
        // Four path segments is the limit, and the file name counts as one.
        ->and(CodeProject::isValidName('a/b/c/file.txt'))->toBeTrue()
        ->and(CodeProject::isValidName('a/b/c/d/file.txt'))->toBeFalse();
});

it('rejects a project that is too long', function () {
    $files = [['name' => 'index.html', 'content' => str_repeat('a', CodeProject::MAX_LENGTH + 1)]];

    expect(CodeProject::issues($files))->toContain('The files may not exceed 50,000 characters in total.');
});

it('picks the runner that can execute the project', function () {
    expect(CodeProject::runner([['name' => 'index.php', 'content' => '']]))->toBe('php')
        ->and(CodeProject::runner([['name' => 'index.html', 'content' => '']]))->toBe('web')
        ->and(CodeProject::runner([['name' => 'style.css', 'content' => '']]))->toBe('web')
        ->and(CodeProject::runner([['name' => 'query.sql', 'content' => '']]))->toBe('sql')
        ->and(CodeProject::runner([['name' => 'main.py', 'content' => '']]))->toBeNull()
        ->and(CodeProject::runner([['name' => 'Main.java', 'content' => '']]))->toBeNull();
});

it('prefers PHP over the web runner when a project has both', function () {
    expect(CodeProject::runner([
        ['name' => 'index.html', 'content' => ''],
        ['name' => 'api.php', 'content' => ''],
    ]))->toBe('php');
});

it('knows the language of each file extension', function () {
    expect(CodeProject::extension('style.css'))->toBe('css')
        ->and(CodeProject::extension('INDEX.HTML'))->toBe('html')
        ->and(CodeProject::extension('Main.java'))->toBe('java')
        ->and(CodeProject::EXTENSIONS['cs'])->toBe('csharp');
});
