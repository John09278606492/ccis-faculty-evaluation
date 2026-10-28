// NativePHP owns this process and its children. Only the classroom gateway is public.
const http = require('node:http');
const net = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const zlib = require('node:zlib');
const { spawn, spawnSync } = require('node:child_process');
const { RestartPolicy } = require('./restart-policy.cjs');
const { campusLinks } = require('./network-links.cjs');
const [project, php, rawPort, statusFile] = process.argv.slice(2);
const port = Number(rawPort);
const wsPort = port + 7;
// Independent PHP workers; reserve the WebSocket and phpMyAdmin ports.
const workerPorts = [8, 9, 10, 11, ...Array.from({length:12},(_,i)=>14+i)].slice(0, Math.min(16, Math.max(4, os.cpus().length))).map(offset => port + offset);
const publicDir = path.join(project, 'public');
// phpMyAdmin for student database workspaces, when the project has them installed.
const phpMyAdminPorts = [12, 13].map(offset => port + offset);
let studentDb = null;
let ownsStudentDb = false;
const campusMode = process.argv.includes('--campus');
let nextPhpMyAdmin = 0;
const children = new Set();
const serviceStates = new Map();
const restartTimers = new Set();
let stopping = false;
let gateway;
let lastNetworkLinks = '';
let lastStatus;
// Never pass the desktop application's SQLite, encryption key, or NativePHP API
// credentials into the shared Laravel application.
const env = Object.fromEntries(Object.entries(process.env).filter(([key]) =>
    /^(PATH|PHPRC|PHP_INI_SCAN_DIR|SYSTEMROOT|WINDIR|TEMP|TMP|COMSPEC|PATHEXT|USERPROFILE|APPDATA|LOCALAPPDATA|PROGRAMDATA|PROGRAMFILES|PROGRAMFILES\(X86\)|SYSTEMDRIVE|NUMBER_OF_PROCESSORS|PROCESSOR_ARCHITECTURE)$/i.test(key)));
Object.assign(env, {
    REVERB_HOST: '127.0.0.1', REVERB_PORT: String(wsPort), REVERB_SCHEME: 'http',
    REVERB_SERVER_HOST: '127.0.0.1', REVERB_SERVER_PORT: String(wsPort),
    CLASSROOM_WEBSOCKET_PORT: String(port), CLASSROOM_WEBSOCKET_HOST: '',
    CLASSROOM_WEBSOCKET_SCHEME: 'http', BROADCAST_CONNECTION: 'reverb', APP_DEBUG: 'false',
    STUDENT_DB_PHPMYADMIN_URL: '/phpmyadmin',
    // Requests arrive from this gateway, which passes on each device's own address.
    TRUSTED_PROXIES: '127.0.0.1',
});
if (campusMode && process.env.CAMPUS_PUBLIC_URL) {
    const publicUrl = new URL(process.env.CAMPUS_PUBLIC_URL);
    if (publicUrl.protocol !== 'http:' || publicUrl.username || publicUrl.password || publicUrl.pathname !== '/') throw new Error('Invalid campus web address.');
    env.APP_URL = publicUrl.origin;
}
// Runtime-owned cache is rebuilt on every start, so moving networks or changing
// the SQLite path cannot leave stale configuration in the web workers.
const runtimeConfig = path.join(project, 'storage/framework/cache', `host-${port}-config.php`);
fs.rmSync(runtimeConfig, {force:true});
env.APP_CONFIG_CACHE = path.relative(project, runtimeConfig);
// Compiled PHP stays cached in memory between requests, re-checking files every two seconds so edits still show.
// On Windows OPcache also needs a cache folder on disk, or PHP stops with an ASLR error.
const opcacheDir = path.join(os.tmpdir(), 'ccis-opcache');
fs.mkdirSync(opcacheDir, { recursive: true });
const opcache = ['-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=1', '-d', 'opcache.revalidate_freq=2',
    '-d', 'opcache.memory_consumption=192', '-d', 'opcache.max_accelerated_files=20000',
    '-d', `opcache.file_cache=${opcacheDir}`, '-d', 'opcache.file_cache_fallback=1'];
// Many PHP installs (Laragon among them) ship OPcache switched off in php.ini.
const opcacheLoaded = (extra) => spawnSync(php, [...extra, '-r', "exit(extension_loaded('Zend OPcache') ? 0 : 1);"], { windowsHide: true, timeout: 15000, stdio: 'ignore' }).status === 0;
if (!opcacheLoaded([])) {
    if (opcacheLoaded(['-d', 'zend_extension=opcache'])) opcache.unshift('-d', 'zend_extension=opcache');
    else opcache.length = 0;
}
// Laravel caches rebuilt on every start and removed on stop, so code changes are never hidden afterwards.
const caches = ['route', 'event', 'filament:cache-components', 'icons'];
function artisan(...args) {
    return spawnSync(php, ['-d', 'opcache.enable_cli=0', 'artisan', ...args], { cwd: project, env, windowsHide: true, timeout: 60000, stdio: 'ignore' });
}
function status(state, message) {
    const links = campusLinks(os.networkInterfaces(), port);
    lastNetworkLinks = JSON.stringify(links);
    lastStatus = { state, message };
    fs.writeFileSync(statusFile + '.tmp', JSON.stringify({ state, message, health: [...serviceStates.values()].some(service => service.state !== 'running') ? 'degraded' : 'healthy', services: [...serviceStates.values()], links, port, pid: process.pid, updatedAt: new Date().toISOString() }));
    fs.renameSync(statusFile + '.tmp', statusFile);
}
const networkMonitor = setInterval(() => {
    if (!stopping && gateway?.listening && lastStatus
        && JSON.stringify(campusLinks(os.networkInterfaces(), port)) !== lastNetworkLinks) {
        status(lastStatus.state, lastStatus.message);
    }
}, 5000);
networkMonitor.unref();
function stop(message, failed = false) {
    if (stopping) return;
    stopping = true;
    clearInterval(networkMonitor);
    status(failed ? 'error' : 'stopping', message);
    gateway?.close();
    for (const timer of restartTimers) clearTimeout(timer);
    restartTimers.clear();
    // Let the student MySQL server shut down cleanly before its process tree is ended.
    if (ownsStudentDb) {
        const stopped = spawnSync(php, ['-d', 'opcache.enable_cli=0', 'artisan', 'classroom:student-db', 'stop'], { cwd: project, env, windowsHide: true, timeout: 45000, stdio: 'ignore' });
        if (stopped.status !== 0) {
            status('error', 'Student MySQL did not confirm a clean shutdown. Keep its data intact and retry Stop.');
            process.exit(1);
        }
    }
    for (const child of children) {
        if (!child.pid) continue;
        if (process.platform === 'win32') spawnSync('taskkill', ['/pid', String(child.pid), '/T', '/F'], { windowsHide: true, stdio: 'ignore' });
        else child.kill('SIGTERM');
    }
    clearCaches();
    status(failed ? 'error' : 'stopped', message);
    process.exit(failed ? 1 : 0);
}
function clearCaches() {
    for (const cache of caches) artisan(cache.includes(':') ? 'filament:clear-cached-components' : `${cache}:clear`);
}
function launch(args, cwd, options = ['-d', 'opcache.enable=0'], policy = new RestartPolicy()) {
    const key = args.join(' ');
    const label = args[0] === 'artisan' ? args.slice(0, 3).join(' ') : 'PHP worker ' + (args[args.indexOf('-S') + 1] ?? '');
    const listenAddress = args.includes('-S') ? args[args.indexOf('-S') + 1] : null;
    const listenPort = listenAddress ? Number(listenAddress.split(':').pop()) : null;
    const logName = key.replace(/[^a-z0-9]+/gi, '-').slice(0, 100);
    const log = fs.openSync(path.join(project, `storage/logs/host-service-${logName}.log`), 'a');
    const child = spawn(php, ['-d', 'opcache.enable_cli=0', ...options, ...args], { cwd, env, windowsHide: true, stdio: ['ignore', log, log] });
    fs.closeSync(log);
    children.add(child);
    policy.started();
    serviceStates.set(key, { key, label, port: listenPort, pid: child.pid, state: 'running', restarts: policy.failures });
    let handled = false;
    const recover = reason => {
        if (handled) return;
        handled = true;
        children.delete(child);
        if (stopping) return;
        const retry = policy.failed();
        serviceStates.set(key, { key, label, port: listenPort, state: retry.exhausted ? 'failed' : 'restarting', restarts: retry.failures, message: reason });
        console.error(`${label}: ${reason}. ${retry.exhausted ? 'Restart budget exhausted; other services remain available.' : `Retrying in ${retry.delayMs}ms.`}`);
        status(gateway ? 'running' : 'starting', 'Some classroom services are recovering. Other available pages remain online.');
        if (retry.exhausted) return;
        const timer = setTimeout(() => {
            restartTimers.delete(timer);
            if (stopping) return;
            launch(args, cwd, options, policy);
            status(gateway ? 'running' : 'starting', 'Classroom service restarted. Review service health for unresolved failures.');
        }, retry.delayMs);
        restartTimers.add(timer);
    };
    child.on('error', error => recover(error.message));
    child.on('exit', code => recover(`Process exited (code ${code})`));
}
function runningPorts(ports) {
    return ports.filter(value => [...serviceStates.values()].some(service => service.port === value && service.state === 'running'));
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
// Each PHP worker serves one request at a time, so send each request to the least busy one.
const busy = new Map();
function pickWorker() {
    const available = runningPorts(workerPorts);
    return available.length ? available.reduce((best, value) => ((busy.get(value) ?? 0) < (busy.get(best) ?? 0) ? value : best)) : null;
}
function clientAddress(req) {
    return (req.socket.remoteAddress ?? '').replace(/^::ffff:/, '');
}

// Files in public/ (styles, scripts, the 18 MB PHP runtime) are sent from here, so a slow
// download never ties up a PHP worker. Compressed copies are made once and kept in memory.
const types = { '.js': 'text/javascript', '.mjs': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.map': 'application/json',
    '.wasm': 'application/wasm', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.gif': 'image/gif',
    '.webp': 'image/webp', '.ico': 'image/x-icon', '.woff': 'font/woff', '.woff2': 'font/woff2', '.ttf': 'font/ttf', '.txt': 'text/plain',
    '.html': 'text/html', '.data': 'application/octet-stream', '.pdf': 'application/pdf' };
const compressible = new Set(['.js', '.mjs', '.css', '.json', '.map', '.wasm', '.svg', '.txt', '.html', '.data', '.ttf']);
const gzipped = new Map();
function serveStatic(req, res) {
    if (req.method !== 'GET' && req.method !== 'HEAD') return false;
    const [rawPath, query = ''] = req.url.split('?');
    let relative;
    try { relative = decodeURIComponent(rawPath); } catch { return false; }
    if (relative === '/' || relative.includes('\0') || /(^|\/)\./.test(relative) || relative.startsWith('/phpmyadmin')) return false;
    const file = path.join(publicDir, relative);
    const ext = path.extname(file).toLowerCase();
    if (!file.startsWith(publicDir + path.sep) || ext === '.php') return false;
    let stat;
    try { stat = fs.statSync(file); } catch { return false; }
    if (!stat.isFile()) return false;
    const etag = `"${stat.size.toString(36)}-${Math.floor(stat.mtimeMs).toString(36)}"`;
    // Versioned assets never change at the same address; everything else is re-checked.
    const immutable = /(^|&)(v|id)=/.test(query) || /^\/(build\/assets|code-runner)\//.test(relative);
    const headers = { 'Content-Type': types[ext] ?? 'application/octet-stream', ETag: etag, 'Last-Modified': stat.mtime.toUTCString(),
        'Cache-Control': immutable ? 'public, max-age=31536000, immutable' : 'no-cache', 'X-Content-Type-Options': 'nosniff' };
    if (req.headers['if-none-match'] === etag) {
        res.writeHead(304, headers);
        res.end();
        return true;
    }
    if (compressible.has(ext) && stat.size > 1024 && /\bgzip\b/.test(req.headers['accept-encoding'] ?? '')) {
        const key = file + etag;
        if (!gzipped.has(key)) {
            gzipped.set(key, fs.promises.readFile(file).then(data => new Promise((resolve, reject) =>
                zlib.gzip(data, { level: 6 }, (error, result) => error ? reject(error) : resolve(result)))));
        }
        gzipped.get(key).then(body => {
            res.writeHead(200, { ...headers, 'Content-Encoding': 'gzip', Vary: 'Accept-Encoding', 'Content-Length': body.length });
            res.end(req.method === 'HEAD' ? undefined : body);
        }, () => {
            gzipped.delete(key);
            if (!res.headersSent) res.writeHead(500);
            res.end();
        });
        return true;
    }
    res.writeHead(200, { ...headers, 'Content-Length': stat.size, ...(compressible.has(ext) ? { Vary: 'Accept-Encoding' } : {}) });
    if (req.method === 'HEAD') res.end();
    else fs.createReadStream(file).on('error', () => res.destroy()).pipe(res);
    return true;
}

async function main() {
    if (!Number.isInteger(port) || port < 1024 || port > 65500) throw new Error('Choose a port between 1024 and 65500.');
    status('starting', 'Checking the classroom database and starting live updates…');
    if (!fs.existsSync(path.join(project, 'artisan')) || !fs.existsSync(php)) throw new Error('Select the classroom project folder and PHP executable in Settings.');
    if (fs.existsSync(path.join(project, 'bootstrap/cache/config.php'))) throw new Error('The classroom project has cached configuration. Clear its configuration cache before using the desktop host.');
    await checkPort(port, '0.0.0.0');
    for (const value of [wsPort, ...workerPorts]) await checkPort(value);
    const probe = spawnSync(php, ['-d', 'opcache.enable_cli=0', '-r', "require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); try { Illuminate\\Support\\Facades\\DB::connection()->getPdo(); } catch (Throwable $e) { exit(2); }"], { cwd: project, env, windowsHide: true, timeout: 15000, stdio: 'ignore' });
    if (probe.status !== 0) throw new Error('The application database is unavailable. Check SQLite file permissions or the configured database service, then try again.');
    const studentDbConfig = spawnSync(php, ['-d', 'opcache.enable_cli=0', 'artisan', 'classroom:student-db', 'host-config'], { cwd: project, env, windowsHide: true, timeout: 15000, encoding: 'utf8' });
    if (studentDbConfig.status !== 0) throw new Error('The student database configuration could not be verified. Check classroom:student-db status.');
    try { studentDb = JSON.parse(studentDbConfig.stdout.trim().split('\n').pop()); } catch { studentDb = null; }
    if (!studentDb?.installed) studentDb = null;
    if (studentDb) {
        for (const value of phpMyAdminPorts) await checkPort(value);
        if (!campusMode || !studentDb.running) {
            await checkPort(studentDb.mysql_port);
            launch(['artisan', 'classroom:student-db', 'serve'], project);
            ownsStudentDb = true;
        }
        const webRoot = studentDb.phpmyadmin_arguments[studentDb.phpmyadmin_arguments.indexOf('-t') + 1];
        for (const value of phpMyAdminPorts) {
            launch(studentDb.phpmyadmin_arguments.map(argument => argument === '127.0.0.1:0' ? `127.0.0.1:${value}` : argument), webRoot, opcache);
        }
    }
    // Live updates need a key and secret; new installs start with them empty.
    artisan('classroom:live-keys');
    launch(['artisan', 'reverb:start', '--host=127.0.0.1', `--port=${wsPort}`], project);
    // Runs scheduled work such as the daily backup while the server is open.
    launch(['artisan', 'schedule:work'], project);
    launch(['artisan', 'queue:work', '--sleep=1', '--tries=1'], project);
    launch(['artisan', 'queue:work', 'bulk', '--queue=bulk', '--sleep=1', '--tries=1', '--timeout=900'], project);
    status('starting', 'Preparing the classroom pages…');
    for (const cache of caches) artisan(cache.includes(':') ? cache : `${cache}:cache`);
    if (artisan('config:cache').status !== 0) throw new Error('Application configuration could not be cached. Check the database and writable storage directory.');
    for (const value of workerPorts) launch(['-S', `127.0.0.1:${value}`, path.join(project, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], publicDir, opcache);
    await Promise.all([wsPort, ...workerPorts, ...(studentDb ? [studentDb.mysql_port, ...phpMyAdminPorts] : [])].map(waitReady));
    // Close expired attempts and retry/freeze retained workspaces before exposing phpMyAdmin.
    const maintenance = artisan('classroom:assessments-maintain', '--limit=5000');
    if (maintenance.status !== 0) console.error('Some assessment snapshots need attention; their workspaces were retained.');
    gateway = http.createServer((req, res) => {
        if (serveStatic(req, res)) return;
        const toPhpMyAdmin = studentDb && /^\/phpmyadmin(\/|$|\?)/.test(req.url);
        if (toPhpMyAdmin && req.url.split('?')[0] === '/phpmyadmin') {
            res.writeHead(301, { Location: '/phpmyadmin/' });
            res.end();
            return;
        }
        const availablePma = runningPorts(phpMyAdminPorts);
        const target = toPhpMyAdmin ? availablePma[nextPhpMyAdmin++ % availablePma.length] : pickWorker();
        if (!target) { res.writeHead(503, { 'Content-Type': 'text/plain', 'Retry-After': '5' }); res.end('This classroom service is recovering. Retry in a moment; your saved work is retained.'); return; }
        busy.set(target, (busy.get(target) ?? 0) + 1);
        let released = false;
        const release = () => { if (!released) { released = true; busy.set(target, busy.get(target) - 1); } };
        res.on('close', release);
        const headers = { ...req.headers, 'x-forwarded-for': clientAddress(req), 'x-forwarded-proto': 'http', 'x-forwarded-host': req.headers.host ?? '' };
        const upstream = http.request({ hostname: '127.0.0.1', port: target, method: req.method, path: req.url, headers }, response => {
            const responseHeaders = { ...response.headers };
            delete responseHeaders.connection;
            delete responseHeaders['keep-alive'];
            delete responseHeaders['transfer-encoding'];
            res.writeHead(response.statusCode, responseHeaders);
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
    gateway.keepAliveTimeout = 65000;
    gateway.headersTimeout = 66000;
    gateway.listen({port, host:'0.0.0.0', backlog:4096}, () => status('running', 'Ready. Devices on the same Wi-Fi can open the link below.'
        + (studentDb ? ' Student databases (phpMyAdmin) are running too.' : '')));
}
process.on('SIGTERM', () => stop('Server stopped.'));
process.on('SIGINT', () => stop('Server stopped.'));
process.on('message', message => { if (message === 'stop') stop('Server stopped.'); });
main().catch(error => stop(error.code === 'EADDRINUSE' ? 'A required port is already in use. Choose another sharing port in Settings.' : error.message, true));
