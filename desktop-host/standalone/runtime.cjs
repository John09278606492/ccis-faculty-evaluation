const fs = require('node:fs');
const fsp = fs.promises;
const path = require('node:path');
const net = require('node:net');
const os = require('node:os');
const crypto = require('node:crypto');
const { spawn, fork } = require('node:child_process');

function inside(root, target) {
    const relative = path.relative(path.resolve(root), path.resolve(target));
    return relative !== '' && !relative.startsWith('..') && !path.isAbsolute(relative);
}
function validatePort(port) {
    if (!Number.isInteger(port) || port < 1024 || port > 65480) throw new Error('Choose a port from 1024 to 65480.');
    return port;
}
function setEnv(text, values) {
    for (const [key, value] of Object.entries(values)) {
        const line = `${key}=${JSON.stringify(String(value))}`;
        const pattern = new RegExp(`^${key}=.*$`, 'm');
        text = pattern.test(text) ? text.replace(pattern, () => line) : text + '\n' + line;
    }
    return text;
}
const delay = ms => new Promise(resolve => setTimeout(resolve, ms).unref());
async function freePort(port) {
    await new Promise((resolve, reject) => {
        const server = net.createServer();
        server.once('error', () => reject(new Error(`Port ${port} is already in use. Choose another classroom port.`)));
        server.listen(port, '127.0.0.1', () => server.close(resolve));
    });
}
async function ready(port, child, timeout = 60000) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        if (child.exitCode !== null) throw new Error('A bundled service stopped. Check the desktop logs.');
        if (await new Promise(resolve => { const s = net.connect(port, '127.0.0.1'); s.on('connect', () => { s.destroy(); resolve(true); }); s.on('error', () => resolve(false)); })) return;
        await delay(200);
    }
    throw new Error(`Service startup timed out on port ${port}.`);
}
class Runtime {
    constructor(bundle, data, node = process.execPath) {
        this.bundle = path.resolve(bundle); this.data = path.resolve(data); this.node = node;
        this.project = path.join(this.data, 'application');
        this.php = path.join(this.bundle, 'php/php.exe');
        this.children = {}; this.state = 'stopped'; this.message = 'Set up your classroom to begin.';
        this.operation = false;
        this.release = JSON.parse(fs.readFileSync(path.join(bundle, 'release.json')));
    }
    get initialized() { return fs.existsSync(path.join(this.data, 'installed.json')); }
    get instance() { return JSON.parse(fs.readFileSync(path.join(this.data, 'instance.json'))); }
    get port() { return this.instance.port; }
    env() { return { ...process.env, PHPRC: path.join(this.data, 'php.ini'), PHP_INI_SCAN_DIR: '', ELECTRON_RUN_AS_NODE: '1' }; }
    snapshot() {
        const state = { state: this.state, message: this.message, initialized: this.initialized, dataPath: this.data, version: this.release.version, port: fs.existsSync(path.join(this.data, 'instance.json')) ? this.port : 18080, links: [] };
        if (this.state === 'running') {
            state.url = `http://127.0.0.1:${this.port}`;
            state.links = Object.values(os.networkInterfaces()).flat().filter(a => a.family === 'IPv4' && !a.internal && !a.address.startsWith('169.254.')).map(a => `http://${a.address}:${this.port}`);
        }
        return state;
    }
    async exclusive(task) {
        if (this.operation) throw new Error('Another operation is still running.');
        this.operation = true;
        try { return await task(); } catch (e) { this.message = e.message; throw e; } finally { this.operation = false; }
    }
    async prepare() {
        await fsp.mkdir(this.data, { recursive: true });
        const installed = path.join(this.data, 'code-version');
        if (!fs.existsSync(installed) || fs.readFileSync(installed, 'utf8') !== this.release.builtAt) {
            await fsp.cp(path.join(this.bundle, 'application'), this.project, { recursive: true, dereference: true });
            await fsp.writeFile(installed, this.release.builtAt);
        }
        for (const dir of ['storage/app/private', 'storage/app/public', 'storage/logs', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'bootstrap/cache']) await fsp.mkdir(path.join(this.project, dir), { recursive: true });
        const publicStorage = path.join(this.project, 'public/storage');
        if (!fs.existsSync(publicStorage)) await fsp.symlink(path.join(this.project, 'storage/app/public'), publicStorage, process.platform === 'win32' ? 'junction' : 'dir');
        const ini = fs.readFileSync(path.join(this.bundle, 'php/php.ini'), 'utf8');
        await fsp.writeFile(path.join(this.data, 'php.ini'), ini + `\nextension_dir="${path.join(this.bundle, 'php/ext').replaceAll('\\', '/')}"\ndisplay_errors=Off\nlog_errors=On\nerror_log="${path.join(this.data, 'php-error.log').replaceAll('\\', '/')}"\n`);
    }
    run(exe, args, input = null, timeout = 180000) {
        return new Promise((resolve, reject) => {
            const child = spawn(exe, args, { cwd: this.project, env: this.env(), windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'] });
            let stdout = '', stderr = '';
            child.stdout.on('data', b => { stdout = (stdout + b).slice(-30000); });
            child.stderr.on('data', b => { stderr = (stderr + b).slice(-30000); });
            const timer = setTimeout(() => { child.kill(); reject(new Error('Operation timed out. Check the desktop logs.')); }, timeout);
            child.on('error', e => { clearTimeout(timer); reject(e); });
            child.on('close', code => { clearTimeout(timer); code === 0 ? resolve(stdout) : reject(new Error((stderr || stdout || `Process exited ${code}`).slice(-1500))); });
            child.stdin.on('error', () => {});
            child.stdin.end(input);
        });
    }
    helper(action, values = {}) { return this.run(this.php, ['-d', 'opcache.enable_cli=0', path.join(this.bundle, 'setup.php')], JSON.stringify({ action, project: this.project, ...values }), 600000); }
    launch(name, exe, args) {
        const log = fs.openSync(path.join(this.project, `storage/logs/desktop-${name}.log`), 'a');
        const child = spawn(exe, args, { cwd: this.project, env: this.env(), windowsHide: true, stdio: ['ignore', log, log] });
        fs.closeSync(log); this.children[name] = child;
        child.on('error', e => { this.state = 'error'; this.message = e.message; });
        child.on('exit', () => { if (this.state === 'running') { this.state = 'error'; this.message = `${name} stopped unexpectedly. Stop and restart the classroom.`; } });
        return child;
    }
    async writeEnvironment() {
        const config = this.instance;
        const file = path.join(this.project, '.env');
        let text = fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : fs.readFileSync(path.join(this.project, '.env.example'), 'utf8');
        const values = {
            APP_ENV: 'production', APP_DEBUG: 'false', APP_URL: `http://127.0.0.1:${config.port}`,
            DB_CONNECTION: 'mysql', DB_HOST: '127.0.0.1', DB_PORT: config.port + 1, DB_DATABASE: 'ccis', DB_USERNAME: 'ccis', DB_PASSWORD: config.appPassword,
            DB_URL: '', SESSION_COOKIE: 'ccis_desktop_session',
            STUDENT_DB_PORT: config.port + 2, STUDENT_DB_MYSQL_BIN: path.join(this.bundle, 'mysql/bin').replaceAll('\\', '/'),
            STUDENT_DB_PHPMYADMIN_SOURCE: path.join(this.bundle, 'phpmyadmin').replaceAll('\\', '/'), STUDENT_DB_LARAGON_PATHS: '', STUDENT_DB_PHPMYADMIN_URL: '/phpmyadmin',
            BACKUP_MYSQLDUMP: path.join(this.bundle, 'mysql/bin/mysqldump.exe').replaceAll('\\', '/'), MAIL_MAILER: 'log',
        };
        if (!/^APP_KEY=[^\r\n]+$/m.test(text.replaceAll('\r', ''))) values.APP_KEY = 'base64:' + crypto.randomBytes(32).toString('base64');
        if (!/^REVERB_APP_KEY=[^\r\n]+$/m.test(text.replaceAll('\r', ''))) values.REVERB_APP_KEY = crypto.randomBytes(16).toString('hex');
        if (!/^REVERB_APP_SECRET=[^\r\n]+$/m.test(text.replaceAll('\r', ''))) values.REVERB_APP_SECRET = crypto.randomBytes(32).toString('hex');
        await fsp.writeFile(file, setEnv(text, values), { mode: 0o600 });
    }
    async startMysql() {
        await freePort(this.port + 1);
        const mysqlData = path.join(this.data, 'mysql');
        const bin = path.join(this.bundle, 'mysql');
        const slash = p => p.replaceAll('\\', '/');
        const ini = path.join(this.data, 'mysql.ini');
        await fsp.mkdir(mysqlData, { recursive: true });
        await fsp.writeFile(ini, `[mysqld]\nbasedir="${slash(bin)}"\ndatadir="${slash(mysqlData)}"\nport=${this.port + 1}\nbind-address=127.0.0.1\nmysqlx=OFF\nlocal_infile=0\nmax_allowed_packet=64M\nlog-error="${slash(path.join(this.data, 'mysql-error.log'))}"\n`);
        const exe = path.join(bin, 'bin/mysqld.exe');
        if (!fs.existsSync(path.join(mysqlData, 'mysql'))) {
            this.message = 'Initializing the bundled system database…';
            await this.run(exe, [`--defaults-file=${ini}`, '--initialize-insecure'], null, 300000);
        }
        const child = this.launch('mysql', exe, [`--defaults-file=${ini}`]);
        await ready(this.port + 1, child);
        await this.helper('database-init', { port: this.port + 1, rootPassword: this.instance.rootPassword, appPassword: this.instance.appPassword });
    }
    async setup(input) {
        return this.exclusive(async () => {
            if (this.initialized) throw new Error('This classroom is already installed.');
            if (typeof input.name !== 'string' || !input.name.trim() || typeof input.email !== 'string' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.email) || typeof input.password !== 'string' || input.password.length < 12) throw new Error('Enter a name, valid email and a password with at least 12 characters.');
            validatePort(input.port);
            this.state = 'starting'; this.message = 'Installing your classroom…';
            try {
                await this.prepare();
                const config = fs.existsSync(path.join(this.data, 'instance.json')) ? this.instance : { rootPassword: crypto.randomBytes(24).toString('hex'), appPassword: crypto.randomBytes(24).toString('hex'), mysqlSeries: '8.0' };
                config.port = input.port;
                await fsp.writeFile(path.join(this.data, 'instance.json'), JSON.stringify(config), { mode: 0o600 });
                await this.writeEnvironment(); await this.startMysql();
                this.message = 'Creating tables, roles and your administrator account…';
                await this.helper('install', { name: input.name.trim(), email: input.email, password: input.password });
                this.message = 'Installing isolated student database workspaces…';
                await freePort(this.port + 2);
                await this.helper('student-install');
                await fsp.writeFile(path.join(this.data, 'installed.json'), JSON.stringify(this.release));
            } finally { await this.stopServices(); }
            this.message = 'Installation complete. Start your classroom.';
            return this.snapshot();
        });
    }
    async start(inputPort) {
        return this.exclusive(async () => {
            if (!this.initialized) throw new Error('Complete first-run setup or restore a desktop backup.');
            if (this.state === 'running') return this.snapshot();
            if (Object.keys(this.children).length) throw new Error('Stop the remaining services before restarting.');
            this.state = 'starting'; this.message = 'Starting the bundled services…';
            try {
                await this.prepare();
                if (inputPort !== undefined) { validatePort(inputPort); const config = this.instance; config.port = inputPort; await fsp.writeFile(path.join(this.data, 'instance.json'), JSON.stringify(config), { mode: 0o600 }); }
                await this.writeEnvironment(); await this.startMysql();
                await this.helper('install'); // Upgrade migrations; existing admins are preserved.
                this.message = 'Starting classroom gateway and student databases…';
                const log = fs.openSync(path.join(this.project, 'storage/logs/desktop-gateway.log'), 'a');
                const gateway = fork(path.join(this.bundle, 'host.cjs'), [this.project, this.php, String(this.port), path.join(this.data, 'gateway-status.json')], { execPath: this.node, env: this.env(), windowsHide: true, stdio: ['ignore', log, log, 'ipc'] });
                fs.closeSync(log); this.children.gateway = gateway;
                gateway.on('error', e => { this.state = 'error'; this.message = e.message; });
                gateway.on('exit', () => { if (this.state === 'running') { this.state = 'error'; this.message = 'The classroom gateway stopped. Check logs, then restart.'; } });
                await ready(this.port, gateway, 120000);
                await this.startAdmin();
                this.state = 'running'; this.message = 'Classroom ready. PHP, system database, student databases and live updates are running.';
                return this.snapshot();
            } catch (e) { await this.stopServices(); this.state = 'error'; throw e; }
        });
    }
    async startAdmin() {
        const root = path.join(this.data, 'admin');
        const web = path.join(root, 'web/phpmyadmin');
        if (!fs.existsSync(path.join(web, 'index.php'))) await fsp.cp(path.join(this.bundle, 'phpmyadmin'), web, { recursive: true });
        await fsp.mkdir(path.join(root, 'tokens'), { recursive: true });
        await fsp.mkdir(path.join(web, 'tmp'), { recursive: true });
        await fsp.copyFile(path.join(this.project, 'resources/student-db/signon.php.stub'), path.join(web, 'signon.php'));
        await fsp.copyFile(path.join(this.project, 'resources/student-db/signed-out.html'), path.join(web, 'signed-out.html'));
        let config = fs.readFileSync(path.join(this.project, 'resources/student-db/config.inc.php.stub'), 'utf8').replace('{{port}}', String(this.port + 1)).replace('{{blowfish_secret}}', crypto.randomBytes(24).toString('hex')).replace("'CCISStudentDB'", "'CCISSystemDB'");
        let signon = fs.readFileSync(path.join(web, 'signon.php'), 'utf8').replace("'CCISStudentDB'", "'CCISSystemDB'");
        await fsp.writeFile(path.join(web, 'config.inc.php'), config);
        await fsp.writeFile(path.join(web, 'signon.php'), signon);
        await freePort(this.port + 3);
        await ready(this.port + 3, this.launch('admin', this.php, ['-S', `127.0.0.1:${this.port + 3}`, '-t', path.join(root, 'web')]));
    }
    databaseLink() {
        if (this.state !== 'running') throw new Error('Start the classroom first.');
        const token = crypto.randomBytes(20).toString('hex');
        fs.writeFileSync(path.join(this.data, `admin/tokens/${token}.json`), JSON.stringify({ user: 'ccis', password: this.instance.appPassword, database: 'ccis', expires: Math.floor(Date.now() / 1000) + 120 }), { mode: 0o600 });
        return `http://127.0.0.1:${this.port + 3}/phpmyadmin/signon.php?token=${token}`;
    }
    async stopServices() {
        this.state = 'stopping'; this.message = 'Stopping services and flushing database files…';
        const gateway = this.children.gateway;
        if (gateway && gateway.exitCode === null) {
            gateway.send('stop');
            await Promise.race([new Promise(resolve => gateway.once('exit', resolve)), delay(60000).then(() => { throw new Error('Gateway has not stopped. Backup is blocked to protect database consistency.'); })]);
        }
        if (gateway && fs.existsSync(path.join(this.project, 'storage/app/student-db/secrets.json'))) {
            // Also flush a student server left behind by an unexpectedly exited gateway.
            await this.run(this.php, ['artisan', 'classroom:student-db', 'stop'], null, 30000);
            const studentPid = path.join(this.project, 'storage/app/student-db/mysqld.pid');
            if (fs.existsSync(studentPid)) throw new Error('Student MySQL has not confirmed clean shutdown. Backup is blocked; restart then stop the classroom again.');
        }
        const admin = this.children.admin;
        if (admin && admin.exitCode === null) { admin.kill(); await Promise.race([new Promise(resolve => admin.once('exit', resolve)), delay(5000)]); }
        const mysql = this.children.mysql;
        if (mysql && mysql.exitCode === null) {
            const options = path.join(this.data, 'shutdown.cnf');
            await fsp.writeFile(options, `[client]\nhost=127.0.0.1\nport=${this.port + 1}\nuser=root\npassword=${this.instance.rootPassword}\n`, { mode: 0o600 });
            try { await this.run(path.join(this.bundle, 'mysql/bin/mysqladmin.exe'), [`--defaults-file=${options}`, 'shutdown'], null, 30000); }
            finally { await fsp.unlink(options).catch(() => {}); }
            await Promise.race([new Promise(resolve => mysql.exitCode !== null ? resolve() : mysql.once('exit', resolve)), delay(30000).then(() => { throw new Error('MySQL shutdown is incomplete. Data was not archived.'); })]);
        }
        this.children = {}; this.state = 'stopped'; this.message = 'Classroom stopped safely.';
    }
    stop() { return this.exclusive(async () => { await this.stopServices(); return this.snapshot(); }); }
    async backup(target) {
        return this.exclusive(async () => {
            if (!this.initialized) throw new Error('There is no installed classroom to back up.');
            if (inside(this.data, target)) throw new Error('Save the backup outside the application data folder.');
            await this.stopServices();
            this.message = 'Creating a portable backup of both databases, uploads and keys…';
            await this.helper('archive', { target, sources: { 'instance.json': path.join(this.data, 'instance.json'), 'application/.env': path.join(this.project, '.env'), mysql: path.join(this.data, 'mysql'), 'application/storage/app': path.join(this.project, 'storage/app') } });
            this.message = `Backup saved: ${target}`; return this.snapshot();
        });
    }
    async restore(source) {
        return this.exclusive(async () => {
            if (this.state !== 'stopped' || Object.keys(this.children).length) throw new Error('Stop the classroom before restoring.');
            if (inside(this.data, source)) throw new Error('Choose a backup outside the application data folder.');
            const stage = this.data + '.restore-' + crypto.randomBytes(6).toString('hex');
            await this.prepare();
            await fsp.mkdir(stage, { recursive: true });
            try { await this.helper('extract', { source, target: stage }); }
            catch (e) { await fsp.rm(stage, { recursive: true, force: true }); throw e; }
            const config = JSON.parse(fs.readFileSync(path.join(stage, 'instance.json')));
            validatePort(config.port);
            if (config.mysqlSeries !== this.release.mysqlSeries || !/^[a-f0-9]{48}$/.test(config.rootPassword) || !/^[a-f0-9]{48}$/.test(config.appPassword)) throw new Error('Invalid backup instance configuration.');
            await fsp.cp(path.join(this.bundle, 'application'), path.join(stage, 'application'), { recursive: true });
            await fsp.writeFile(path.join(stage, 'code-version'), this.release.builtAt);
            const previous = this.data + '.before-restore-' + Date.now();
            // Keep the complete former installation for recovery; never overwrite live files.
            await fsp.rename(this.data, previous);
            try { await fsp.rename(stage, this.data); }
            catch (e) { await fsp.rename(previous, this.data); throw e; }
            await this.prepare(); await this.writeEnvironment();
            await fsp.writeFile(path.join(this.data, 'installed.json'), JSON.stringify(this.release));
            this.message = `Backup restored. Previous data preserved at ${previous}. Start the classroom to verify.`;
            return this.snapshot();
        });
    }
}
module.exports = { Runtime, inside, validatePort, setEnv };
