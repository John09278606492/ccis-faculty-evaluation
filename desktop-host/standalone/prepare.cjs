const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const out = path.join(__dirname, 'bundle');
if (fs.existsSync(out)) throw new Error('bundle already exists. Rename it before creating a new release.');
const php = process.env.CCIS_PHP_SOURCE || 'C:/laragon/bin/php/php-8.3.35-Win32-vs16-x64';
const mysql = process.env.CCIS_MYSQL_SOURCE || 'C:/laragon/bin/mysql/mysql-8.0.30-winx64';
const pma = process.env.CCIS_PHPMYADMIN_SOURCE || 'C:/laragon/etc/apps/phpMyAdmin';
for (const file of [path.join(php, 'php.exe'), path.join(mysql, 'bin/mysqld.exe'), path.join(pma, 'vendor/autoload.php'), path.join(root, 'vendor/autoload.php'), path.join(root, 'public/code-runner/php-worker.js')]) {
    if (!fs.existsSync(file)) throw new Error(`Missing build input: ${file}`);
}
fs.mkdirSync(out, { recursive: true });
const copy = (src, dest, filter) => fs.cpSync(src, dest, { recursive: true, dereference: true, filter });
for (const item of ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'vendor', 'artisan', 'composer.json', 'composer.lock', '.env.example']) {
    copy(path.join(root, item), path.join(out, 'application', item), source => {
        const relative = path.relative(root, source).replaceAll('\\', '/');
        return !/^bootstrap\/cache\/.+\.php$/.test(relative)
            && !/^database\/.+\.(sqlite|sql)$/.test(relative)
            && !/^public\/(storage|hot|build\.old)(\/|$)/.test(relative);
    });
}
copy(php, path.join(out, 'php'), source => !['php.ini', 'php.ini-development', 'php.ini-production'].includes(path.basename(source)));
fs.writeFileSync(path.join(out, 'php/php.ini'), `extension_dir="${'.'}/ext"\nextension=curl\nextension=exif\nextension=fileinfo\nextension=gd\nextension=intl\nextension=mbstring\nextension=mysqli\nextension=openssl\nextension=pdo_mysql\nextension=pdo_sqlite\nextension=sqlite3\nextension=sodium\nextension=zip\nmemory_limit=512M\nmax_execution_time=120\nupload_max_filesize=64M\npost_max_size=68M\ndate.timezone=Asia/Manila\nopcache.enable_cli=0\n`);
// PHP resolves relative extension paths against the working directory: use ${PHP_INI_SCAN_DIR}
// only for optional ini files, and set extension_dir explicitly in the runtime manager.
copy(mysql, path.join(out, 'mysql'), source => !['data', 'mysql-test', 'mysqlrouter', 'docs', 'my.ini'].includes(path.basename(source)));
copy(pma, path.join(out, 'phpmyadmin'), source => !['config.inc.php', 'tmp'].includes(path.basename(source)));
copy(path.join(root, 'desktop-host/resources/server/host.cjs'), path.join(out, 'host.cjs'));
copy(path.join(__dirname, 'setup.php'), path.join(out, 'setup.php'));
copy(path.join(__dirname, 'README.md'), path.join(out, 'README.md'));
fs.writeFileSync(path.join(out, 'release.json'), JSON.stringify({ format: 1, version: '2.0.0', mysqlSeries: '8.0', builtAt: new Date().toISOString() }, null, 2));
const probe = spawnSync(path.join(out, 'php/php.exe'), ['-c', path.join(out, 'php/php.ini'), '-d', `extension_dir=${path.join(out, 'php/ext')}`, '-r', "foreach(['pdo_mysql','mbstring','intl','zip','openssl'] as $e) if(!extension_loaded($e)) exit(1);"], { encoding: 'utf8', windowsHide: true });
if (probe.status !== 0) throw new Error('Bundled PHP extension check failed: ' + probe.stderr);
const redist = path.join(out, 'vc_redist.x64.exe');
const downloaded = spawnSync('curl.exe', ['-L', '--fail', '--silent', '--show-error', 'https://aka.ms/vs/17/release/vc_redist.x64.exe', '-o', redist], { windowsHide: true, timeout: 180000 });
if (downloaded.status !== 0) throw new Error('Could not download Microsoft runtime prerequisite.');
const signature = spawnSync('powershell.exe', ['-NoProfile', '-Command', `(Get-AuthenticodeSignature -LiteralPath '${redist.replaceAll("'", "''")}').Status`], { encoding: 'utf8', windowsHide: true });
if (signature.stdout.trim() !== 'Valid') throw new Error('Microsoft prerequisite signature did not validate.');
console.log('Standalone bundle prepared. No .env, live databases or uploaded records were copied.');
