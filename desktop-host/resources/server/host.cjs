// NativePHP owns this process and its children. Only the classroom gateway is public.
const http = require('node:http');
const net = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');
const [project, php, rawPort, statusFile] = process.argv.slice(2);
const port = Number(rawPort);
const wsPort = port + 7;
const workerPorts = [8, 9, 10, 11].map(offset => port + offset);
// phpMyAdmin for student database workspaces, when the project has them installed.
const phpMyAdminPorts = [12, 13].map(offset => port + offset);
let studentDb = null;
let nextPhpMyAdmin = 0;
const children = [];
let stopping = false;
let gateway;
let nextWorker = 0;
// Never pass the desktop application's SQLite, encryption key, or NativePHP API
// credentials into the shared Laravel application.
const env = Object.fromEntries(Object.entries(process.env).filter(([key]) =>
    /^(PATH|SYSTEMROOT|WINDIR|TEMP|TMP|COMSPEC|PATHEXT|USERPROFILE|APPDATA|LOCALAPPDATA|PROGRAMDATA|PROGRAMFILES|PROGRAMFILES\(X86\)|SYSTEMDRIVE|NUMBER_OF_PROCESSORS|PROCESSOR_ARCHITECTURE)$/i.test(key)));
Object.assign(env, {
    REVERB_HOST: '127.0.0.1', REVERB_PORT: String(wsPort), REVERB_SCHEME: 'http',
    REVERB_SERVER_HOST: '127.0.0.1', REVERB_SERVER_PORT: String(wsPort),
    CLASSROOM_WEBSOCKET_PORT: String(port), CLASSROOM_WEBSOCKET_HOST: '',
    CLASSROOM_WEBSOCKET_SCHEME: 'http', BROADCAST_CONNECTION: 'reverb', APP_DEBUG: 'false',
});
function status(state, message) {
    const links = Object.entries(os.networkInterfaces()).flatMap(([name, addresses]) =>
        addresses.filter(a => a.family === 'IPv4' && !a.internal && !a.address.startsWith('169.254.'))
            .map(a => ({ name, url: `http://${a.address}:${port}` })))
        .sort((a, b) => Number(/wi-fi|wireless/i.test(b.name)) - Number(/wi-fi|wireless/i.test(a.name)));
    fs.writeFileSync(statusFile + '.tmp', JSON.stringify({ state, message, links, port, pid: process.pid, updatedAt: new Date().toISOString() }));
    fs.renameSync(statusFile + '.tmp', statusFile);
}
function stop(message, failed = false) {
    if (stopping) return;
    stopping = true;
    status(failed ? 'error' : 'stopped', message);
    gateway?.close();
    // Let the student MySQL server shut down cleanly before its process tree is ended.
    if (studentDb) spawnSync(php, ['-d', 'opcache.enable_cli=0', 'artisan', 'classroom:student-db', 'stop'], { cwd: project, env, windowsHide: true, timeout: 15000, stdio: 'ignore' });
    for (const child of children) {
        if (!child.pid) continue;
        if (process.platform === 'win32') spawnSync('taskkill', ['/pid', String(child.pid), '/T', '/F'], { windowsHide: true, stdio: 'ignore' });
        else child.kill('SIGTERM');
    }
    process.exit(failed ? 1 : 0);
}
function launch(args, cwd) {
    const child = spawn(php, ['-d', 'opcache.enable_cli=0', '-d', 'opcache.enable=0', ...args], { cwd, env, windowsHide: true, stdio: 'ignore' });
    children.push(child);
    child.on('error', () => stop('A server process could not start. Check the PHP path in Settings.', true));
    child.on('exit', () => { if (!stopping) stop('A server process stopped unexpectedly. Start the server again.', true); });
}
async function checkPort(value, host = '127.0.0.1') {
    await new Promise((resolve, reject) => {
        const server = net.createServer();
        server.once('error', reject);
        server.listen(value, host, () => server.close(resolve));
    });
}
async function waitReady(value) {
    for (let i = 0; i < 100; i++) {
        const ready = await new Promise(resolve => {
            const socket = net.connect(value, '127.0.0.1');
            socket.on('connect', () => { socket.destroy(); resolve(true); });
            socket.on('error', () => resolve(false));
        });
        if (ready) return;
        await new Promise(resolve => setTimeout(resolve, 200));
    }
    throw new Error('Server startup timed out. Check that PHP can run the selected project.');
}
async function main() {
    if (!Number.isInteger(port) || port < 1024 || port > 65524) throw new Error('Choose a port between 1024 and 65524.');
    status('starting', 'Checking the classroom database and starting live updates…');
    if (!fs.existsSync(path.join(project, 'artisan')) || !fs.existsSync(php)) throw new Error('Select the classroom project folder and PHP executable in Settings.');
    if (fs.existsSync(path.join(project, 'bootstrap/cache/config.php'))) throw new Error('The classroom project has cached configuration. Clear its configuration cache before using the desktop host.');
    await checkPort(port, '0.0.0.0');
    for (const value of [wsPort, ...workerPorts]) await checkPort(value);
    const probe = spawnSync(php, ['-d', 'opcache.enable_cli=0', '-r', "require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); try { Illuminate\\Support\\Facades\\DB::connection()->getPdo(); } catch (Throwable $e) { exit(2); }"], { cwd: project, env, windowsHide: true, timeout: 15000, stdio: 'ignore' });
    if (probe.status !== 0) throw new Error('The classroom database is unavailable. Start MySQL on this computer, then try again.');
    const studentDbConfig = spawnSync(php, ['-d', 'opcache.enable_cli=0', 'artisan', 'classroom:student-db', 'host-config'], { cwd: project, env, windowsHide: true, timeout: 15000, encoding: 'utf8' });
    try { studentDb = JSON.parse(studentDbConfig.stdout.trim().split('\n').pop()); } catch { studentDb = null; }
    if (!studentDb?.installed) studentDb = null;
    if (studentDb) {
        for (const value of [studentDb.mysql_port, ...phpMyAdminPorts]) await checkPort(value);
        launch(['artisan', 'classroom:student-db', 'serve'], project);
        const webRoot = studentDb.phpmyadmin_arguments[studentDb.phpmyadmin_arguments.indexOf('-t') + 1];
        for (const value of phpMyAdminPorts) {
            launch(studentDb.phpmyadmin_arguments.map(argument => argument === '127.0.0.1:0' ? `127.0.0.1:${value}` : argument), webRoot);
        }
    }
    launch(['artisan', 'reverb:start', '--host=127.0.0.1', `--port=${wsPort}`], project);
    // Runs scheduled work such as the daily backup while the server is open.
    launch(['artisan', 'schedule:work'], project);
    for (const value of workerPorts) launch(['-S', `127.0.0.1:${value}`, path.join(project, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], path.join(project, 'public'));
    await Promise.all([wsPort, ...workerPorts, ...(studentDb ? [studentDb.mysql_port, ...phpMyAdminPorts] : [])].map(waitReady));
    gateway = http.createServer((req, res) => {
        const toPhpMyAdmin = studentDb && /^\/phpmyadmin(\/|$|\?)/.test(req.url);
        if (toPhpMyAdmin && req.url.split('?')[0] === '/phpmyadmin') {
            res.writeHead(301, { Location: '/phpmyadmin/' });
            res.end();
            return;
        }
        const target = toPhpMyAdmin
            ? phpMyAdminPorts[nextPhpMyAdmin++ % phpMyAdminPorts.length]
            : workerPorts[nextWorker++ % workerPorts.length];
        const upstream = http.request({ hostname: '127.0.0.1', port: target, method: req.method, path: req.url, headers: req.headers }, response => {
            res.writeHead(response.statusCode, response.headers);
            response.pipe(res);
        });
        upstream.on('error', () => { if (!res.headersSent) res.writeHead(503, { 'Content-Type': 'text/plain' }); res.end('The classroom server is restarting. Please try again.'); });
        req.on('aborted', () => upstream.destroy());
        req.pipe(upstream);
    });
    gateway.on('upgrade', (req, socket, head) => {
        if (!req.url.startsWith('/app/')) { socket.destroy(); return; }
        const upstream = net.connect(wsPort, '127.0.0.1', () => {
            upstream.write(`${req.method} ${req.url} HTTP/${req.httpVersion}\r\n${Object.entries(req.headers).map(([k,v]) => `${k}: ${v}`).join('\r\n')}\r\n\r\n`);
            if (head.length) upstream.write(head);
            socket.pipe(upstream).pipe(socket);
        });
        upstream.on('error', () => socket.destroy());
        socket.on('error', () => upstream.destroy());
        socket.on('close', () => upstream.destroy());
    });
    gateway.on('error', error => stop(error.code === 'EADDRINUSE' ? 'This sharing port is already in use. Choose another port in Settings.' : 'The sharing server could not start.', true));
    gateway.listen(port, '0.0.0.0', () => status('running', 'Ready. Devices on the same Wi-Fi can open the link below.'
        + (studentDb ? ' Student databases (phpMyAdmin) are running too.' : '')));
}
process.on('SIGTERM', () => stop('Server stopped.'));
process.on('SIGINT', () => stop('Server stopped.'));
main().catch(error => stop(error.code === 'EADDRINUSE' ? 'A required port is already in use. Choose another sharing port in Settings.' : error.message, true));
