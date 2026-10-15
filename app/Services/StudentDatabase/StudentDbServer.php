<?php

namespace App\Services\StudentDatabase;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The separate MySQL server for student database workspaces, and the
 * phpMyAdmin copy students use to reach it.
 *
 * Everything lives under config('classroom.student_db.path'):
 *   my.ini, data/       MySQL configuration and data (127.0.0.1 only)
 *   web/phpmyadmin/     phpMyAdmin copied from Laragon, sign-on only
 *   tokens/             one-time sign-on links, written here and read by signon.php
 *   secrets.json        the MySQL root password, encrypted with APP_KEY
 */
class StudentDbServer
{
    public function path(string $relative = ''): string
    {
        $base = rtrim(config('classroom.student_db.path'), '\\/');

        return $relative === '' ? $base : $base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function port(): int
    {
        return (int) config('classroom.student_db.port');
    }

    public function isInstalled(): bool
    {
        return is_file($this->path('installed.json'));
    }

    public function isRunning(): bool
    {
        $socket = @fsockopen('127.0.0.1', $this->port(), $errorCode, $errorMessage, 0.5);
        if ($socket) {
            fclose($socket);

            return true;
        }

        return false;
    }

    /**
     * MySQL's bin folder: the configured one, else the newest found in Laragon.
     */
    public function mysqlBin(): ?string
    {
        if ($configured = config('classroom.student_db.mysql_bin')) {
            return is_file($configured.DIRECTORY_SEPARATOR.$this->exe('mysqld')) ? $configured : null;
        }

        return $this->newest(
            fn (string $laragon) => glob($laragon.'/bin/mysql/*/bin', GLOB_ONLYDIR) ?: [],
            fn (string $bin) => is_file($bin.DIRECTORY_SEPARATOR.$this->exe('mysqld'))
                && is_file($bin.DIRECTORY_SEPARATOR.$this->exe('mysqldump'))
                && is_file($bin.DIRECTORY_SEPARATOR.$this->exe('mysql')),
            fn (string $bin) => basename(dirname($bin)),
        );
    }

    /**
     * phpMyAdmin to copy: the configured folder, else the newest found in Laragon.
     */
    public function phpMyAdminSource(): ?string
    {
        if ($configured = config('classroom.student_db.phpmyadmin_source')) {
            return is_file($configured.'/index.php') ? $configured : null;
        }

        return $this->newest(
            fn (string $laragon) => glob($laragon.'/etc/apps/*', GLOB_ONLYDIR) ?: [],
            fn (string $folder) => strtolower(basename($folder)) === 'phpmyadmin'
                && is_file($folder.'/index.php') && is_file($folder.'/vendor/autoload.php'),
            fn (string $folder) => basename(glob($folder.'/RELEASE-DATE-*')[0] ?? 'RELEASE-DATE-0'),
        );
    }

    public function rootPassword(): string
    {
        $secrets = json_decode((string) @file_get_contents($this->path('secrets.json')), true);
        if (! isset($secrets['root_password'])) {
            throw new RuntimeException('The student database server is not installed. Run `php artisan classroom:student-db install`.');
        }

        return Crypt::decryptString($secrets['root_password']);
    }

    public function connect(?string $user = null, ?string $password = null, ?string $database = null): PDO
    {
        $dsn = "mysql:host=127.0.0.1;port={$this->port()}".($database ? ";dbname={$database}" : '').';charset=utf8mb4';

        return new PDO($dsn, $user ?? 'root', $password ?? $this->rootPassword(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    /**
     * Run a MySQL client program with credentials passed in a temporary options
     * file, never on the command line where other processes could read them.
     */
    public function runClient(string $program, array $arguments, string $user, string $password, ?string $input = null): string
    {
        $options = $this->path('tmp/'.Str::random(24).'.cnf');
        File::ensureDirectoryExists(dirname($options));
        file_put_contents($options, "[client]\nhost=127.0.0.1\nport={$this->port()}\nuser={$user}\npassword=\"".addcslashes($password, '"\\')."\"\n");
        try {
            $process = $this->process([$this->mysqlBin().DIRECTORY_SEPARATOR.$this->exe($program), "--defaults-extra-file={$options}", ...$arguments]);
            $process->setTimeout(120)->setInput($input)->mustRun();

            return $process->getOutput();
        } finally {
            @unlink($options);
        }
    }

    /**
     * Create the data folder, root password and phpMyAdmin copy. Safe to run
     * again: existing data is kept and phpMyAdmin is refreshed.
     */
    public function install(callable $log): void
    {
        $bin = $this->mysqlBin() ?? throw new RuntimeException('No MySQL server was found. Install Laragon, or set STUDENT_DB_MYSQL_BIN to MySQL\'s bin folder.');
        $phpMyAdmin = $this->phpMyAdminSource() ?? throw new RuntimeException('No phpMyAdmin was found. Install Laragon, or set STUDENT_DB_PHPMYADMIN_SOURCE.');
        foreach (['data', 'logs', 'tmp', 'tokens', 'sessions', 'no-files', 'web'] as $folder) {
            File::ensureDirectoryExists($this->path($folder));
        }
        $log("MySQL: {$bin}");
        $this->writeMyIni($bin);

        $fresh = ! is_dir($this->path('data/mysql'));
        if ($fresh) {
            $log('Creating the student database server…');
            $this->process([$bin.DIRECTORY_SEPARATOR.$this->exe('mysqld'), '--defaults-file='.$this->path('my.ini'), '--initialize-insecure'])
                ->setTimeout(300)->mustRun();
        }
        $this->ensureRootPassword($fresh, $log);

        $log("phpMyAdmin: {$phpMyAdmin}");
        $this->installPhpMyAdmin($phpMyAdmin);

        file_put_contents($this->path('installed.json'), json_encode([
            'mysql_bin' => $bin,
            'phpmyadmin_source' => $phpMyAdmin,
            'installed_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Start MySQL and wait until it stops. With $phpMyAdminPort, also serve
     * phpMyAdmin on 127.0.0.1 (the desktop host serves it itself).
     */
    public function serve(?int $phpMyAdminPort, callable $log): int
    {
        if (! $this->isInstalled()) {
            throw new RuntimeException('Run `php artisan classroom:student-db install` first.');
        }
        if ($this->isRunning()) {
            throw new RuntimeException("Port {$this->port()} is already in use. Is the student database server already running?");
        }
        $this->writeMyIni($this->mysqlBin());
        $processes = [$this->process([$this->mysqlBin().DIRECTORY_SEPARATOR.$this->exe('mysqld'), '--defaults-file='.$this->path('my.ini')])];
        if ($phpMyAdminPort) {
            $processes[] = $this->process([PHP_BINARY, ...$this->phpMyAdminServerArguments($phpMyAdminPort)]);
            $log("phpMyAdmin: http://127.0.0.1:{$phpMyAdminPort}/phpmyadmin/");
        }
        foreach ($processes as $process) {
            $process->setTimeout(null)->start();
        }
        $log("Student MySQL is listening on 127.0.0.1:{$this->port()}.");

        while ($processes[0]->isRunning()) {
            usleep(250_000);
        }
        foreach ($processes as $process) {
            $process->stop(2);
        }

        return $processes[0]->getExitCode() ?? 1;
    }

    /**
     * Arguments for `php` to serve phpMyAdmin; shared with the desktop host.
     *
     * @return list<string>
     */
    public function phpMyAdminServerArguments(int $port): array
    {
        return [
            '-d', 'session.save_path='.$this->path('sessions'),
            '-d', 'upload_max_filesize=64M',
            '-d', 'post_max_size=64M',
            '-S', "127.0.0.1:{$port}",
            '-t', $this->path('web'),
        ];
    }

    public function stop(): bool
    {
        if (! $this->isRunning()) {
            return false;
        }
        $this->connect()->exec('SHUTDOWN');

        return true;
    }

    /**
     * Issue a one-time phpMyAdmin sign-on link, valid for one minute.
     */
    public function signOnUrl(string $user, string $password, string $database): string
    {
        $tokens = $this->path('tokens');
        File::ensureDirectoryExists($tokens);
        foreach (glob($tokens.DIRECTORY_SEPARATOR.'*.json') ?: [] as $stale) {
            if (filemtime($stale) < time() - 300) {
                @unlink($stale);
            }
        }
        $token = Str::random(40);
        file_put_contents($tokens.DIRECTORY_SEPARATOR."{$token}.json", json_encode([
            'user' => $user,
            'password' => $password,
            'database' => $database,
            'expires' => time() + 60,
        ]));

        return rtrim(config('classroom.student_db.phpmyadmin_url'), '/')."/signon.php?token={$token}";
    }

    protected function writeMyIni(string $bin): void
    {
        $slashes = fn (string $path) => str_replace('\\', '/', $path);
        file_put_contents($this->path('my.ini'), implode("\n", [
            '[mysqld]',
            'basedir="'.$slashes(dirname($bin)).'"',
            'datadir="'.$slashes($this->path('data')).'"',
            "port={$this->port()}",
            // Only this computer can connect; students go through phpMyAdmin.
            'bind-address=127.0.0.1',
            'mysqlx=OFF',
            'local_infile=0',
            'secure_file_priv="'.$slashes($this->path('no-files')).'"',
            'max_connections=300',
            'max_allowed_packet=64M',
            'log-error="'.$slashes($this->path('logs/mysql-error.log')).'"',
            'pid-file="'.$slashes($this->path('mysqld.pid')).'"',
            '',
        ]));
    }

    protected function ensureRootPassword(bool $fresh, callable $log): void
    {
        $hasSecret = is_file($this->path('secrets.json'));
        if (! $fresh && $hasSecret) {
            return;
        }
        if (! $fresh) {
            throw new RuntimeException('The student MySQL data exists but its root password is missing from secrets.json. Delete '.$this->path('data').' to start over.');
        }
        $startedHere = ! $this->isRunning();
        $server = $this->process([$this->mysqlBin().DIRECTORY_SEPARATOR.$this->exe('mysqld'), '--defaults-file='.$this->path('my.ini')]);
        if ($startedHere) {
            $server->setTimeout(null)->start();
            $this->waitUntilRunning($server);
        }
        try {
            $password = Str::password(32, symbols: false);
            $pdo = $this->connect('root', '');
            $quoted = $pdo->quote($password);
            $pdo->exec("ALTER USER 'root'@'localhost' IDENTIFIED BY {$quoted}");
            $pdo->exec("CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY {$quoted}");
            $pdo->exec("GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION");
            file_put_contents($this->path('secrets.json'), json_encode(['root_password' => Crypt::encryptString($password)]));
            $log('Root password created.');
            if ($startedHere) {
                $pdo->exec('SHUTDOWN');
                $server->wait();
            }
        } finally {
            if ($startedHere && $server->isRunning()) {
                $server->stop(10);
            }
        }
    }

    protected function waitUntilRunning(Process $server): void
    {
        for ($i = 0; $i < 120; $i++) {
            if ($this->isRunning()) {
                return;
            }
            if (! $server->isRunning()) {
                throw new RuntimeException('MySQL stopped while starting. See '.$this->path('logs/mysql-error.log'));
            }
            usleep(250_000);
        }
        throw new RuntimeException('MySQL did not start within 30 seconds.');
    }

    protected function installPhpMyAdmin(string $source): void
    {
        $target = $this->path('web/phpmyadmin');
        File::deleteDirectory($target);
        File::ensureDirectoryExists($target);
        // Leave out the setup wizard, examples, docs and nested copies of phpMyAdmin.
        $skip = ['setup', 'examples', 'doc', 'test', 'tmp', 'config.inc.php'];
        foreach (File::glob($source.DIRECTORY_SEPARATOR.'*') as $entry) {
            $name = basename($entry);
            if (in_array(strtolower($name), $skip, true) || str_starts_with(strtolower($name), 'phpmyadmin-')) {
                continue;
            }
            is_dir($entry) ? File::copyDirectory($entry, "{$target}/{$name}") : File::copy($entry, "{$target}/{$name}");
        }
        File::ensureDirectoryExists("{$target}/tmp");

        $stubs = resource_path('student-db');
        file_put_contents("{$target}/config.inc.php", strtr(file_get_contents("{$stubs}/config.inc.php.stub"), [
            '{{blowfish_secret}}' => bin2hex(random_bytes(16)),
            '{{port}}' => (string) $this->port(),
        ]));
        file_put_contents("{$target}/signon.php", strtr(file_get_contents("{$stubs}/signon.php.stub"), [
            '{{tokens_path}}' => var_export($this->path('tokens'), true),
        ]));
        File::copy("{$stubs}/signed-out.html", "{$target}/signed-out.html");
    }

    /**
     * Child processes get the full system environment. Under a web server, PHP
     * only passes request variables, and on Windows a program started without
     * SYSTEMROOT cannot open network sockets (MySQL error 2004 / WSA 10106).
     *
     * @param  list<string>  $command
     */
    protected function process(array $command): Process
    {
        return new Process($command, null, array_filter(getenv(), 'is_string'));
    }

    protected function exe(string $program): string
    {
        return PHP_OS_FAMILY === 'Windows' ? "{$program}.exe" : $program;
    }

    /**
     * @param  callable(string): list<string>  $candidates
     */
    protected function newest(callable $candidates, callable $accept, callable $version): ?string
    {
        $found = collect(config('classroom.student_db.laragon_paths'))
            ->flatMap(fn (string $laragon) => is_dir($laragon) ? $candidates($laragon) : [])
            ->filter($accept)
            ->sort(fn (string $a, string $b) => version_compare($this->versionOf($version($b)), $this->versionOf($version($a))))
            ->first();

        return $found ? realpath($found) : null;
    }

    protected function versionOf(string $name): string
    {
        return preg_match('/(\d+(?:\.\d+)+)/', $name, $match) ? $match[1] : '0';
    }
}
