<?php

/**
 * Lets test-locally.bat skip setup steps whose inputs have not changed since
 * they last succeeded on this PC.
 *
 *   php scripts/local-test-state.php check <step>   exit 0 when the step can be skipped
 *   php scripts/local-test-state.php save <step>    record that the step succeeded
 */
$root = dirname(__DIR__);
$stateFile = $root.'/storage/framework/cache/local-test-state.json';

$steps = [
    'composer' => ['inputs' => ['composer.json', 'composer.lock'], 'requires' => ['vendor/autoload.php']],
    'npm' => ['inputs' => ['package.json', 'package-lock.json'], 'requires' => ['node_modules/.package-lock.json']],
    'build' => [
        'inputs' => ['package-lock.json', 'vite.config.js', 'tailwind.config.js', 'postcss.config.js', 'scripts/build-code-runner.mjs', 'resources/js', 'resources/css'],
        'requires' => ['public/build/manifest.json', 'public/code-runner/php-worker.js'],
    ],
    'roles' => ['inputs' => ['database/seeders/RolesSeeder.php', 'app/Filament', 'app/Models', 'app/Policies'], 'requires' => []],
];

[$action, $step] = [$argv[1] ?? '', $argv[2] ?? ''];
if (! isset($steps[$step]) || ! in_array($action, ['check', 'save'], true)) {
    fwrite(STDERR, "Usage: php scripts/local-test-state.php check|save composer|npm|build|roles\n");
    exit(2);
}

$fingerprint = function (array $inputs) use ($root): string {
    $parts = [];
    foreach ($inputs as $input) {
        $path = $root.'/'.$input;
        if (is_file($path)) {
            $parts[] = $input.':'.md5_file($path);
        } elseif (is_dir($path)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            $list = [];
            foreach ($files as $file) {
                $list[] = substr($file->getPathname(), strlen($root) + 1).':'.$file->getSize().':'.$file->getMTime();
            }
            sort($list);
            $parts[] = md5(implode("\n", $list));
        }
    }

    return md5(implode("\n", $parts));
};

$state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
$current = $fingerprint($steps[$step]['inputs']);

if ($action === 'save') {
    $state[$step] = $current;
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
    exit(0);
}

foreach ($steps[$step]['requires'] as $required) {
    if (! file_exists($root.'/'.$required)) {
        exit(1);
    }
}
// A ready-made vendor folder records which composer.lock it was built from.
if ($step === 'composer' && is_file($root.'/vendor/.lock-md5')
    && trim((string) file_get_contents($root.'/vendor/.lock-md5')) === md5(str_replace("\r\n", "\n", (string) file_get_contents($root.'/composer.lock')))) {
    exit(0);
}
exit(($state[$step] ?? null) === $current ? 0 : 1);
