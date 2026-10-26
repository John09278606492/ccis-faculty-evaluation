<?php

namespace App\Services\StudentDatabase;

use App\Models\QuizAnswer;
use App\Support\CodeProject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs a student's saved PHP project on the classroom server, one request at a
 * time through php-cgi, so pages that use mysqli and fetch() work as they would
 * in XAMPP. Each run is fenced in: it may only read its own folder, cannot run
 * programs, open network connections or read the server's settings, and every
 * mysqli connection goes to the student's own workspace database whatever
 * host, user or database name the code asks for.
 */
class StudentProjectRunner
{
    public const TIMEOUT_SECONDS = 5;

    public const BLOCKED_FUNCTIONS = [
        'exec', 'passthru', 'shell_exec', 'system', 'proc_open', 'popen', 'pcntl_exec', 'dl', 'putenv', 'getenv', 'mail',
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server', 'socket_create', 'curl_init', 'curl_exec',
        'curl_multi_exec', 'symlink', 'link', 'set_time_limit', 'ini_set', 'ini_alter', 'ini_restore', 'phpinfo',
        'apache_setenv', 'highlight_file', 'show_source', 'php_uname', 'getmypid', 'posix_kill', 'proc_nice',
    ];

    private const TYPES = [
        'html' => 'text/html; charset=UTF-8', 'htm' => 'text/html; charset=UTF-8', 'css' => 'text/css', 'js' => 'text/javascript',
        'json' => 'application/json', 'svg' => 'image/svg+xml', 'txt' => 'text/plain; charset=UTF-8', 'md' => 'text/plain; charset=UTF-8',
        'csv' => 'text/plain; charset=UTF-8', 'xml' => 'text/xml', 'sql' => 'text/plain; charset=UTF-8',
    ];

    public function __construct(protected StudentDbServer $server) {}

    public function cgiBinary(): ?string
    {
        $directory = dirname(PHP_BINARY);
        foreach (PHP_OS_FAMILY === 'Windows' ? ['php-cgi.exe'] : ['php-cgi', 'php-cgi'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION] as $name) {
            if (is_file($candidate = $directory.DIRECTORY_SEPARATOR.$name)) {
                return $candidate;
            }
        }

        return null;
    }

    /** The page the Run button opens: index.php, else index.html, else the first PHP or HTML file. */
    public function entry(QuizAnswer $answer): ?string
    {
        $names = array_column($this->files($answer), 'name');
        foreach (['index.php', 'index.html', 'index.htm'] as $index) {
            if (in_array($index, $names, true)) {
                return $index;
            }
        }

        return collect($names)->first(fn (string $name) => in_array(CodeProject::extension($name), ['php', 'html', 'htm'], true));
    }

    /**
     * @param  array{user: string, password: string, database: string}  $workspace
     */
    public function run(QuizAnswer $answer, string $path, Request $request, array $workspace): Response
    {
        // "a12x", not "12": open_basedir matches by prefix, so one answer's folder never covers another's (a120x).
        $root = storage_path('app'.DIRECTORY_SEPARATOR.'project-run'.DIRECTORY_SEPARATOR.'a'.$answer->id.'x');
        $www = $root.DIRECTORY_SEPARATOR.'www';
        $files = $this->files($answer);
        $this->sync($www, $files);
        $this->writeBoot($root, $workspace);

        $file = collect($files)->first(fn (array $file) => $file['name'] === $path);
        if (! $file) {
            return $this->message(404, "There is no file named \"{$path}\" in your project. Check the file name in your code (it is case-sensitive).");
        }
        $extension = CodeProject::extension($path);
        if ($extension !== 'php') {
            return new Response($file['content'], 200, ['Content-Type' => self::TYPES[$extension] ?? 'text/plain; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']);
        }

        $cgi = $this->cgiBinary();
        if (! $cgi) {
            return $this->message(503, 'Running PHP projects needs php-cgi next to the server\'s PHP. Ask your instructor.');
        }

        // A FormData body arrives already parsed, so it is passed on as an ordinary form post.
        $isMultipart = str_starts_with(strtolower((string) $request->header('Content-Type')), 'multipart/form-data');
        $body = $isMultipart ? http_build_query($request->post()) : (string) $request->getContent();
        $contentType = $isMultipart ? 'application/x-www-form-urlencoded' : (string) $request->header('Content-Type');
        $script = $www.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        $base = '/project-run/'.$answer->id.'/';

        $process = new Process([$cgi, ...$this->iniArguments($root)], dirname($script), $this->environment([
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'REDIRECT_STATUS' => '200',
            'SERVER_SOFTWARE' => 'CCIS project runner',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_NAME' => $request->getHost(),
            'SERVER_PORT' => (string) $request->getPort(),
            'HTTP_HOST' => $request->getHttpHost(),
            'HTTP_ACCEPT' => (string) $request->header('Accept'),
            'HTTP_USER_AGENT' => (string) $request->userAgent(),
            'REQUEST_METHOD' => $request->getMethod(),
            'REQUEST_URI' => $base.$path.(($query = $request->getQueryString()) ? '?'.$query : ''),
            'QUERY_STRING' => (string) $request->server('QUERY_STRING', ''),
            'SCRIPT_NAME' => $base.$path,
            'PHP_SELF' => $base.$path,
            'SCRIPT_FILENAME' => $script,
            'DOCUMENT_ROOT' => $www,
            'CONTENT_TYPE' => $contentType,
            'CONTENT_LENGTH' => (string) strlen($body),
            'REMOTE_ADDR' => (string) $request->ip(),
            'TEMP' => $root.DIRECTORY_SEPARATOR.'tmp',
            'TMP' => $root.DIRECTORY_SEPARATOR.'tmp',
            'TMPDIR' => $root.DIRECTORY_SEPARATOR.'tmp',
        ]), $body, self::TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return $this->message(504, 'Your code ran for more than '.self::TIMEOUT_SECONDS.' seconds and was stopped. Look for a loop that never ends.');
        }

        return $this->response((string) $process->getOutput(), (string) $process->getErrorOutput());
    }

    /**
     * @return list<array{name: string, content: string}>
     */
    public function files(QuizAnswer $answer): array
    {
        $answer->loadMissing('question');
        $starter = $answer->question->starterFiles();
        $files = CodeProject::decode($answer->answer_text, $starter);

        return array_values(array_filter($files, fn (array $file) => CodeProject::isValidName($file['name'])));
    }

    /** The files on disk mirror the saved answer; a file is replaced in one step so a page loading at the same time never reads half of it. */
    protected function sync(string $www, array $files): void
    {
        File::ensureDirectoryExists($www);
        File::ensureDirectoryExists(dirname($www).DIRECTORY_SEPARATOR.'tmp');
        $wanted = [];
        foreach ($files as $file) {
            $target = $www.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file['name']);
            File::ensureDirectoryExists(dirname($target));
            $wanted[realpath(dirname($target)) ?: dirname($target)][] = basename($target);
            $content = CodeProject::extension($file['name']) === 'php' ? self::redirectConnections($file['content']) : $file['content'];
            if (! is_file($target) || md5_file($target) !== md5($content)) {
                $temporary = $target.'.'.bin2hex(random_bytes(4)).'.part';
                file_put_contents($temporary, $content);
                if (! @rename($temporary, $target)) {
                    @unlink($temporary);
                    file_put_contents($target, $content, LOCK_EX);
                }
            }
        }
        foreach (File::allFiles($www) as $existing) {
            $directory = realpath($existing->getPath()) ?: $existing->getPath();
            if (! in_array($existing->getFilename(), $wanted[$directory] ?? [], true) && ! str_ends_with($existing->getFilename(), '.part')) {
                @unlink($existing->getPathname());
            }
        }
    }

    /** Every mysqli connection the student writes goes to their own workspace instead. */
    public static function redirectConnections(string $code): string
    {
        $code = preg_replace('/\bnew\s+\\\\?mysqli\s*\(/i', 'new \\ExamMysqli(', $code);

        return preg_replace('/(?<![\w$>:\\\\])\\\\?mysqli_connect\s*\(/i', '\\exam_mysqli_connect(', $code);
    }

    protected function writeBoot(string $root, array $workspace): void
    {
        $settings = var_export(['127.0.0.1', $workspace['user'], $workspace['password'], $workspace['database'], $this->server->port()], true);
        $boot = <<<PHP
<?php
// Written by the classroom server: connects this project to the student's own database.
final class ExamMysqli extends mysqli
{
    public function __construct(...\$ignored)
    {
        [\$host, \$user, \$password, \$database, \$port] = {$settings};
        parent::__construct(\$host, \$user, \$password, \$database, \$port);
    }
}
function exam_mysqli_connect(...\$ignored)
{
    [\$host, \$user, \$password, \$database, \$port] = {$settings};

    return mysqli_connect(\$host, \$user, \$password, \$database, \$port);
}

PHP;
        $path = $root.DIRECTORY_SEPARATOR.'boot.php';
        if (! is_file($path) || file_get_contents($path) !== $boot) {
            file_put_contents($path, $boot, LOCK_EX);
        }
    }

    /**
     * Each "-d" setting travels as one argument (see StudentDbServer::phpMyAdminServerArguments).
     *
     * @return list<string>
     */
    public function iniArguments(string $root): array
    {
        $tmp = $root.DIRECTORY_SEPARATOR.'tmp';

        return [
            // No trailing backslash before a closing quote: Windows would read it as an escaped quote.
            '-d open_basedir="'.$root.'"',
            '-d disable_functions="'.implode(',', self::BLOCKED_FUNCTIONS).'"',
            '-d allow_url_fopen=0',
            '-d allow_url_include=0',
            '-d auto_prepend_file="'.$root.DIRECTORY_SEPARATOR.'boot.php"',
            '-d max_execution_time='.self::TIMEOUT_SECONDS,
            '-d max_input_time=5',
            '-d memory_limit=128M',
            '-d display_errors=1',
            '-d html_errors=1',
            '-d error_reporting=32767',
            '-d log_errors=0',
            '-d file_uploads=0',
            '-d session.save_path="'.$tmp.'"',
            '-d sys_temp_dir="'.$tmp.'"',
            '-d upload_tmp_dir="'.$tmp.'"',
            '-d opcache.enable=0',
            '-d expose_php=0',
            '-d cgi.force_redirect=1',
        ];
    }

    /**
     * The run sees only the request; none of the server's own settings (its app key,
     * database paths, passwords) are passed on.
     *
     * @param  array<string, string>  $variables
     * @return array<string, string|false>
     */
    protected function environment(array $variables): array
    {
        $environment = [];
        foreach (array_keys(getenv() + $_ENV + $_SERVER) as $name) {
            if (is_string($name)) {
                $environment[$name] = false;
            }
        }
        foreach (['SystemRoot', 'SYSTEMROOT', 'windir', 'WINDIR', 'SystemDrive', 'SYSTEMDRIVE', 'PATH', 'Path', 'PATHEXT', 'PHPRC', 'PHP_INI_SCAN_DIR'] as $kept) {
            if (is_string($value = getenv($kept)) && $value !== '') {
                $environment[$kept] = $value;
            }
        }

        return array_merge($environment, $variables);
    }

    protected function response(string $output, string $errors): Response
    {
        $split = preg_split("/\r?\n\r?\n/", $output, 2);
        [$head, $body] = count($split) === 2 ? $split : ['', $output];
        $status = 200;
        $headers = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => 'text/html; charset=UTF-8'];
        foreach (preg_split("/\r?\n/", $head) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $line, 2));
            match (strtolower($name)) {
                'status' => $status = (int) $value ?: 200,
                'content-type' => $headers['Content-Type'] = $value,
                'location' => [$headers['Location'] = $value, $status = $status === 200 ? 302 : $status],
                default => null,
            };
        }
        if ($body === '' && trim($errors) !== '' && $status === 200) {
            $body = '<pre>'.e(trim($errors)).'</pre>';
        }

        return new Response($body, $status, $headers);
    }

    protected function message(int $status, string $text): Response
    {
        return new Response('<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;padding:1rem"><p>'.e($text).'</p></body>', $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
