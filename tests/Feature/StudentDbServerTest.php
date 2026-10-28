<?php

use App\Models\User;
use App\Services\StudentDatabase\MySqlStudentDatabases;
use App\Services\StudentDatabase\StudentDbServer;
use Symfony\Component\Process\Process;

it('reports a stopped student server to a web host without claiming an occupied port', function () {
    $server = Mockery::mock(StudentDbServer::class);
    $server->shouldReceive('isRunning')->once()->andReturn(false);
    $server->shouldReceive('isInstalled')->once()->andReturn(true);
    $server->shouldReceive('port')->once()->andReturn(19400);
    $server->shouldReceive('phpMyAdminServerArguments')->with(0)->once()->andReturn([]);
    app()->instance(StudentDbServer::class, $server);
    $this->artisan('classroom:student-db host-config')->expectsOutputToContain('"running":false')->assertSuccessful();
});

it('refuses to reuse a student database port when its credentials cannot be verified', function () {
    $server = Mockery::mock(StudentDbServer::class);
    $server->shouldReceive('isInstalled')->once()->andReturn(true);
    $server->shouldReceive('isRunning')->once()->andReturn(true);
    $server->shouldReceive('connect')->once()->andThrow(new PDOException('Student database credential check failed'));
    app()->instance(StudentDbServer::class, $server);
    $this->artisan('classroom:student-db host-config')->expectsOutputToContain('Student database credential check failed')->assertFailed();
});

it('starts an evaluation host without probing an uninstalled assessment database or another application on its port', function () {
    $server = Mockery::mock(StudentDbServer::class);
    $server->shouldReceive('isInstalled')->once()->andReturn(false);
    $server->shouldNotReceive('isRunning');
    $server->shouldNotReceive('connect');
    $server->shouldReceive('port')->once()->andReturn(3307);
    $server->shouldReceive('phpMyAdminServerArguments')->with(0)->once()->andReturn([]);
    app()->instance(StudentDbServer::class, $server);
    $this->artisan('classroom:student-db host-config')->expectsOutputToContain('"installed":false,"running":false')->assertSuccessful();
});

it('refreshes the phpmyadmin port after moving or reconfiguring a classroom', function () {
    $folder = storage_path('framework/testing/pma-port-'.uniqid());
    config(['classroom.student_db.path' => $folder, 'classroom.student_db.port' => 19382]);
    $server = new class extends StudentDbServer
    {
        public function refreshForTest(): void
        {
            $this->writeSignOn();
        }
    };
    $server->refreshForTest();
    expect(file_get_contents($folder.'/web/phpmyadmin/config.inc.php'))->toContain("['port'] = '19382'");
    config(['classroom.student_db.port' => 19482]);
    $server->refreshForTest();
    expect(file_get_contents($folder.'/web/phpmyadmin/config.inc.php'))->toContain("['port'] = '19482'")
        ->not->toContain("['port'] = '19382'");
});

/**
 * The separate MySQL server that gives every student their own database.
 */

/**
 * The "-d" flag that sets the session folder, or null when there is none.
 */
function sessionPathFlag(array $arguments): ?string
{
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '-d session.save_path=')) {
            return $argument;
        }
    }

    return null;
}

it('reconnects and finishes provisioning when mysql loses the account connection', function () {
    $error = new PDOException('MySQL server has gone away');
    $error->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
    $lost = Mockery::mock(PDO::class);
    $lost->shouldReceive('quote')->once()->andReturn("'password'");
    $lost->shouldReceive('exec')->once()->andThrow($error);
    $fresh = Mockery::mock(PDO::class);
    $fresh->shouldReceive('exec')->times(2)->andReturn(0);
    $server = Mockery::mock(StudentDbServer::class);
    $server->shouldReceive('connect')->twice()->andReturn($lost, $fresh);

    $account = (new MySqlStudentDatabases($server))->account(User::factory()->create());
    expect($account->mysql_user)->toStartWith('stu');
});

it('does not retry a mysql permission error as a lost connection', function () {
    $error = new PDOException('Permission denied');
    $error->errorInfo = ['42000', 1044, 'Permission denied'];
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('quote')->once()->andReturn("'password'");
    $pdo->shouldReceive('exec')->once()->andThrow($error);
    $server = Mockery::mock(StudentDbServer::class);
    $server->shouldReceive('connect')->once()->andReturn($pdo);
    expect(fn () => (new MySqlStudentDatabases($server))->account(User::factory()->create()))->toThrow(PDOException::class);
});

it('keeps the session path and its flag in one argument', function () {
    $arguments = app(StudentDbServer::class)->phpMyAdminServerArguments(8095);

    // A separate value is cut short at the apostrophe in a project folder like
    // "Val's PC", so the session files land in a folder that does not exist.
    expect(sessionPathFlag($arguments))->not->toBeNull()
        ->and(sessionPathFlag($arguments))->toStartWith('-d session.save_path="')
        ->and(sessionPathFlag($arguments))->toEndWith('"');
});

it('keeps the full session path when php reads it', function () {
    // A folder name with an apostrophe, like the one on val's external drive, on any machine.
    config(['classroom.student_db.path' => sys_get_temp_dir().DIRECTORY_SEPARATOR."lenovo's drive".DIRECTORY_SEPARATOR.'student-db']);
    $flag = sessionPathFlag(app(StudentDbServer::class)->phpMyAdminServerArguments(8095));

    // A script file keeps the code free of quotes that the command line would mangle.
    $script = tempnam(sys_get_temp_dir(), 'pma').'.php';
    file_put_contents($script, '<?php echo ini_get("session.save_path");');

    $process = new Process([PHP_BINARY, $flag, $script]);
    $process->run();
    unlink($script);

    $read = trim($process->getOutput());
    expect($read)->not->toBe('')
        ->and(str_contains($read, 'student-db'))->toBeTrue()
        // The path must not be cut short at the apostrophe.
        ->and(str_contains($read, 'lenovo\'s drive'))->toBeTrue();
});

it('serves phpMyAdmin from the web folder', function () {
    $arguments = app(StudentDbServer::class)->phpMyAdminServerArguments(8095);

    expect($arguments)->toContain('-S', '127.0.0.1:8095')
        ->and($arguments)->toContain('-t')
        ->and($arguments)->toContain('-d upload_max_filesize=64M')
        ->and($arguments)->toContain('-d post_max_size=64M');
});

it('writes a sign-on token that stays usable for a few minutes', function () {
    $server = app(StudentDbServer::class);
    $url = $server->signOnUrl('stu1', 'secret', 'stu1_test');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $token = $query['token'] ?? '';

    expect($token)->toHaveLength(40)
        ->and($url)->toContain('signon.php?token=');

    $file = storage_path('app/student-db/tokens/'.$token.'.json');
    expect(is_file($file))->toBeTrue();

    $data = json_decode((string) file_get_contents($file), true);
    expect($data['user'])->toBe('stu1')
        ->and($data['database'])->toBe('stu1_test')
        // Long enough for a student to open the link, look around and reload.
        ->and($data['expires'] - time())->toBeGreaterThan(60);

    unlink($file);
});

it('does not delete the token when it is used, so a reload still works', function () {
    // The sign-on page keeps the token file until it expires. A student who
    // reloads phpMyAdmin, or opens the link in a second tab, is sent back to
    // the sign-on page and must still be able to get in.
    $stub = resource_path('student-db/signon.php.stub');

    expect(is_file($stub))->toBeTrue();
    expect(file_get_contents($stub))
        ->not->toContain('@unlink($file)')
        ->toContain("preg_match('/^[A-Za-z0-9]{40}$/'");
});
