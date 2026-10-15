<?php

namespace App\Support;

/**
 * The files of a code question — starter files, a reference solution or a
 * student's answer — stored as {"files": [{"name": ..., "content": ...}]}.
 *
 * resources/js/components/code-editor.js mirrors the file name, language and
 * runner rules below; keep the two in sync.
 */
class CodeProject
{
    public const MAX_FILES = 10;

    /** Longest combined content of all files in one project. */
    public const MAX_LENGTH = 50_000;

    public const FILE_NAME_PATTERN = '/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*\.[A-Za-z0-9]+$/';

    /** Editor language per file extension. */
    public const EXTENSIONS = [
        'html' => 'html', 'htm' => 'html',
        'css' => 'css',
        'js' => 'javascript', 'json' => 'javascript',
        'php' => 'php',
        'sql' => 'sql',
        'py' => 'python',
        'java' => 'java',
        'c' => 'c', 'h' => 'c',
        'cpp' => 'cpp', 'cc' => 'cpp', 'hpp' => 'cpp',
        'cs' => 'csharp',
        'txt' => 'plaintext', 'md' => 'plaintext',
    ];

    /** Files a new question starts with, per language preset. */
    public const PRESET_FILES = [
        'web' => ['index.html', 'style.css', 'app.js'],
        'php' => ['index.php'],
        'html' => ['index.html'],
        'css' => ['style.css'],
        'javascript' => ['app.js'],
        'sql' => ['query.sql'],
        'python' => ['main.py'],
        'java' => ['Main.java'],
        'c' => ['main.c'],
        'cpp' => ['main.cpp'],
        'csharp' => ['Program.cs'],
        'plaintext' => ['main.txt'],
    ];

    /**
     * @return list<array{name: string, content: string}>
     */
    public static function preset(string $language, string $firstFileContent = ''): array
    {
        return collect(self::PRESET_FILES[$language] ?? self::PRESET_FILES['plaintext'])
            ->map(fn (string $name, int $index) => ['name' => $name, 'content' => $index === 0 ? $firstFileContent : ''])
            ->all();
    }

    /**
     * Normalize decoded input into a list of files, or null if it is not one.
     *
     * @return list<array{name: string, content: string}>|null
     */
    public static function normalize(mixed $files): ?array
    {
        if (! is_array($files) || ! array_is_list($files)) {
            return null;
        }
        $normalized = [];
        foreach ($files as $file) {
            if (! is_array($file) || ! is_string($file['name'] ?? null) || ! is_string($file['content'] ?? '')) {
                return null;
            }
            $normalized[] = ['name' => $file['name'], 'content' => (string) ($file['content'] ?? '')];
        }

        return $normalized;
    }

    /**
     * Decode stored JSON. Anything else is treated as the content of a single
     * file (code saved before multi-file support) named after $fallback's first file.
     *
     * @param  list<array{name: string, content: string}>  $fallback
     * @return list<array{name: string, content: string}>
     */
    public static function decode(?string $stored, array $fallback): array
    {
        if ($stored === null || $stored === '') {
            return $fallback;
        }
        $decoded = json_decode($stored, true);
        if (is_array($decoded) && ($files = self::normalize($decoded['files'] ?? null)) !== null) {
            return $files;
        }
        $files = $fallback ?: self::preset('plaintext');
        $files[0]['content'] = $stored;

        return $files;
    }

    /**
     * @param  list<array{name: string, content: string}>  $files
     */
    public static function encode(array $files): string
    {
        return json_encode(['files' => array_values($files)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  list<array{name: string, content: string}>  $files
     * @return list<string>
     */
    public static function issues(array $files): array
    {
        $issues = [];
        if ($files === [] || count($files) > self::MAX_FILES) {
            $issues[] = 'Use between 1 and '.self::MAX_FILES.' files.';
        }
        $names = array_column($files, 'name');
        foreach ($names as $name) {
            if (! self::isValidName($name)) {
                $issues[] = "\"{$name}\" is not a valid file name. Use letters, numbers, - or _ and an extension such as .html, .css, .js, .php or .sql.";
            }
        }
        if (count(array_unique(array_map('strtolower', $names))) !== count($names)) {
            $issues[] = 'File names must be unique.';
        }
        if (self::length($files) > self::MAX_LENGTH) {
            $issues[] = 'The files may not exceed '.number_format(self::MAX_LENGTH).' characters in total.';
        }

        return $issues;
    }

    public static function isValidName(string $name): bool
    {
        return strlen($name) <= 64
            && preg_match(self::FILE_NAME_PATTERN, $name) === 1
            && array_key_exists(self::extension($name), self::EXTENSIONS);
    }

    /**
     * @param  list<array{name: string, content: string}>  $files
     */
    public static function length(array $files): int
    {
        return array_sum(array_map(fn (array $file) => mb_strlen($file['content']), $files));
    }

    /**
     * Which in-browser runner can execute these files: PHP (WebAssembly), a web
     * page preview, SQLite — or null for languages that cannot run in a browser.
     *
     * @param  list<array{name: string, content: string}>  $files
     */
    public static function runner(array $files): ?string
    {
        $extensions = array_map(fn (array $file) => self::extension($file['name']), $files);

        return match (true) {
            in_array('php', $extensions, true) => 'php',
            (bool) array_intersect($extensions, ['html', 'htm', 'css', 'js']) => 'web',
            in_array('sql', $extensions, true) => 'sql',
            default => null,
        };
    }

    public static function extension(string $name): string
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }
}
