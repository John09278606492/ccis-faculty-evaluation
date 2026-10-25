// A class taking a coding exam at once, over real HTTP and WebSocket sessions.
// Point it at a server running an isolated database made by classroom:assessment-load-fixture.
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const zlib = require('node:zlib');
const crypto = require('node:crypto');
const {performance} = require('node:perf_hooks');
const args = Object.fromEntries(process.argv.slice(2).map(v => v.replace(/^--/, '').split('=')));
const base = (args.url || 'http://127.0.0.1:8300').replace(/\/$/, '');
const fixture = JSON.parse(fs.readFileSync(path.resolve(args.manifest), 'utf8'));
const students = fixture.students.slice(0, Number(args.students || fixture.students.length));
const minutes = Number(args.minutes || 3);
const saveEvery = Number(args.saveEvery || 5000);
const rampMs = Number(args.rampMs || 20000);
const startSpreadMs = Number(args.startSpreadMs || 10000);
const useSockets = args.ws !== '0';
const out = args.out ? path.resolve(args.out) : null;
const records = [];
const failures = [];
const counters = {broadcasts: 0, studentPushRequests: 0, instructorPushRequests: 0, socketErrors: 0};
const decode = s => s.replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const jitter = ms => ms * (0.5 + Math.random());
// Distinct loopback addresses, so the gateway passes each student's own address on, as on the classroom network.
let nextAddress = 10;
const addressFor = () => { const n = nextAddress++; return `127.0.${Math.floor(n / 250) + 1}.${(n % 250) + 2}`; };

class Browser {
    cookies = new Map();
    components = new Map();
    queue = Promise.resolve();
    pending = new Map();
    constructor(name) {
        this.name = name;
        this.address = addressFor();
        this.agent = new http.Agent({keepAlive: true, maxSockets: 6, localAddress: this.address});
    }
    request(url, options = {}, label = 'other') {
        const started = performance.now();
        const target = new URL(url, base);
        const headers = {'Accept-Encoding': 'gzip', ...options.headers, Cookie: [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; ')};
        if (options.body) headers['Content-Length'] = Buffer.byteLength(options.body);
        return new Promise((resolve, reject) => {
            const req = http.request(target, {method: options.method || 'GET', headers, agent: this.agent}, res => {
                const chunks = [];
                res.on('data', chunk => chunks.push(chunk));
                res.on('end', () => {
                    let body = Buffer.concat(chunks);
                    const bytes = body.length;
                    if (res.headers['content-encoding'] === 'gzip') body = zlib.gunzipSync(body);
                    for (const cookie of res.headers['set-cookie'] || []) {
                        const part = cookie.split(';')[0]; const at = part.indexOf('=');
                        if (at > 0) this.cookies.set(part.slice(0, at), part.slice(at + 1));
                    }
                    records.push({label, status: res.statusCode, ms: performance.now() - started, bytes, sent: headers['Content-Length'] || 0});
                    resolve({status: res.statusCode, text: options.binary ? '' : body.toString('utf8'), location: res.headers.location});
                });
                res.on('error', reject);
            });
            req.setTimeout(120000, () => req.destroy(Error('Client deadline exceeded')));
            req.on('error', error => { records.push({label, status: 'network_error', ms: performance.now() - started}); reject(error); });
            req.end(options.body);
        });
    }
    readPage(html) {
        this.token = decode(html.match(/name="csrf-token"[^>]*content="([^"]+)"/)[1]);
        this.components.clear();
        for (const [, raw] of html.matchAll(/wire:snapshot="([^"]+)"/g)) {
            const snapshot = decode(raw);
            const memo = JSON.parse(snapshot).memo;
            this.components.set(memo.id, {name: memo.name, snapshot});
        }
        this.lazy = [...html.matchAll(/wire:id="([^"]+)"[^>]*x-intersect="\$wire\.__lazyLoad\(&#039;([^&]+)&#039;\)"/g)].map(([, id, encoded]) => [id, encoded]);
        const config = html.match(/id="classroom-live-config">([^<]+)</);
        this.live = config ? JSON.parse(decode(config[1])) : null;
    }
    find(fragment) {
        for (const [id, component] of this.components) if (component.name.includes(fragment)) return id;
        throw Error(`No ${fragment} component`);
    }
    // Livewire sends one request at a time from a page and folds calls made meanwhile into the next one.
    commit(id, {updates = {}, calls = []}, label) {
        const entry = this.pending.get(id);
        if (entry) { Object.assign(entry.updates, updates); entry.calls.push(...calls); return entry.promise; }
        const next = {updates: {...updates}, calls: [...calls]};
        this.pending.set(id, next);
        next.promise = this.queue = this.queue.catch(() => {}).then(async () => {
            this.pending.delete(id);
            const component = this.components.get(id);
            const headers = {'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.token, 'X-Livewire': 'true'};
            if (this.socketId) headers['X-Socket-ID'] = this.socketId;
            const r = await this.request('/livewire/update', {method: 'POST', headers,
                body: JSON.stringify({_token: this.token, components: [{snapshot: component.snapshot, updates: next.updates, calls: next.calls}]})}, label);
            if (r.status !== 200) throw Error(`${label} ${r.status}`);
            const result = JSON.parse(r.text).components[0];
            component.snapshot = result.snapshot;
            if (result.effects?.html) {
                for (const [, raw] of result.effects.html.matchAll(/wire:snapshot="([^"]+)"/g)) {
                    const snapshot = decode(raw); const memo = JSON.parse(snapshot).memo;
                    if (memo.id !== id) this.components.set(memo.id, {name: memo.name, snapshot});
                }
            }
            return result;
        });
        return next.promise;
    }
    // Lazy components (the bell, relation manager tables) load right after the page, as the browser does.
    loadLazy(label) {
        return Promise.all(this.lazy.map(([id, encoded]) => this.commit(id, {calls: [{path: '', method: '__lazyLoad', params: [encoded]}]}, label)));
    }
    async login(identifier, secret, staff = false) {
        const page = await this.request('/login', {}, 'login_page');
        this.readPage(page.text);
        const id = this.find('login');
        if (staff) await this.commit(id, {calls: [{path: '', method: 'toggleStaffLogin', params: []}]}, 'login_submit');
        else await this.commit(id, {updates: {'data.login': identifier}, calls: [{path: '', method: 'authenticate', params: []}]}, 'login_submit');
        const updates = staff ? {'data.login': identifier, 'data.password': secret} : {'data.password': secret};
        const result = await this.commit(id, {updates, calls: [{path: '', method: 'authenticate', params: []}]}, 'login_submit');
        if (!result.effects.redirect) throw Error(`Sign-in rejected for ${identifier}`);
    }
    async assets(html) {
        const urls = new Set();
        for (const [, url] of html.matchAll(/(?:src|href)="([^"]+\.(?:js|css|wasm)(?:\?[^"]*)?)"/g)) urls.add(decode(url));
        for (const [, url] of html.matchAll(/x-load-src="([^"]+)"/g)) urls.add(decode(url));
        urls.add('/code-runner/php-worker.js');
        const started = performance.now();
        await Promise.all([...urls].map(url => this.request(url, {binary: true}, url.includes('.wasm') ? 'asset_php_runtime' : 'asset')));
        return performance.now() - started;
    }
    connect(onEvent) {
        if (!useSockets || !this.live) return Promise.resolve();
        return new Promise((resolve, reject) => {
            const ws = new WebSocket(`${base.replace(/^http/, 'ws')}/app/${this.live.key}?protocol=7&client=js&version=8.4.0&flash=false`);
            this.ws = ws;
            const channels = [this.live.channel, ...this.live.panelChannels];
            const waiting = new Set(channels.map(name => `private-${name}`));
            ws.onerror = () => { counters.socketErrors++; reject(Error('WebSocket failed')); };
            ws.onmessage = async ({data}) => {
                const message = JSON.parse(data);
                if (message.event === 'pusher:connection_established') {
                    this.socketId = JSON.parse(message.data).socket_id;
                    for (const name of waiting) {
                        const r = await this.request('/broadcasting/auth', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': this.token},
                            body: new URLSearchParams({socket_id: this.socketId, channel_name: name}).toString()}, 'socket_auth');
                        if (r.status !== 200) { reject(Error(`Channel auth ${r.status}`)); return; }
                        ws.send(JSON.stringify({event: 'pusher:subscribe', data: {auth: JSON.parse(r.text).auth, channel: name}}));
                    }
                } else if (message.event === 'pusher_internal:subscription_succeeded') {
                    waiting.delete(message.channel);
                    if (message.channel === `private-${this.live.channel}`) onEvent('subscribed', {kind: 'reconnected'});
                    if (!waiting.size) resolve();
                } else if (message.event === 'pusher:ping') {
                    ws.send(JSON.stringify({event: 'pusher:pong', data: {}}));
                } else if (message.channel) {
                    counters.broadcasts++;
                    onEvent(message.event, typeof message.data === 'string' ? JSON.parse(message.data) : message.data, message.channel);
                }
            };
        });
    }
}

// Typing grows the files; now and then a student adds a file of their own.
function edit(project, step) {
    const files = project.files;
    if (step % 9 === 4 && files.length < 7) files.push({name: `includes/helper${files.length}.php`, content: '<?php\n'});
    const file = files[Math.floor(Math.random() * files.length)];
    const line = file.name.endsWith('.php') ? `    $total += $grades[${step}] ?? 0; // step ${step}\n`
        : file.name.endsWith('.css') ? `.row-${step} { margin: ${step % 12}px; }\n` : `  console.log('step ${step}');\n`;
    file.content += line.repeat(1 + Math.floor(Math.random() * 3));
}

function summarize(items) {
    const a = items.map(r => r.ms).sort((x, y) => x - y);
    const pct = p => +(a[Math.min(a.length - 1, Math.ceil(a.length * p) - 1)] || 0).toFixed(1);
    return {requests: a.length, p50_ms: pct(.5), p95_ms: pct(.95), p99_ms: pct(.99), max_ms: pct(1),
        statuses: items.reduce((o, r) => (o[r.status] = (o[r.status] || 0) + 1, o), {})};
}

async function main() {
    const t0 = performance.now();
    const quizPath = `/${fixture.campus}/take-assessment/${fixture.quiz}`;
    // The instructor watches the Attempts tab; every live update reloads it, as in the browser.
    const teacher = new Browser('instructor');
    await teacher.login(fixture.instructor.email, fixture.instructor.password, true);
    const editPage = await teacher.request(`/${fixture.campus}/assessments/${fixture.quiz}/edit?activeRelationManager=2`, {}, 'instructor_attempts_page');
    if (editPage.status !== 200) throw Error(`Instructor page ${editPage.status}`);
    teacher.readPage(editPage.text);
    await teacher.loadLazy('instructor_attempts_load');
    const pushListeners = () => [...teacher.components].filter(([, c]) => /edit-quiz|attempts-relation-manager/.test(c.name)).map(([id]) => id);
    const teacherEvent = (event, data, channel) => {
        if (channel?.includes('App.Models.User')) {
            const bell = [...teacher.components].find(([, c]) => c.name.includes('database-notifications'));
            if (bell) teacher.commit(bell[0], {}, 'instructor_bell').catch(e => failures.push({who: 'instructor', error: e.message}));
            return;
        }
        if (event !== 'classroom.changed' && event !== 'subscribed') return;
        for (const id of pushListeners()) {
            counters.instructorPushRequests++;
            teacher.commit(id, {calls: [{path: '', method: '__dispatch', params: ['classroom-push', data]}]}, 'instructor_live_refresh')
                .catch(e => failures.push({who: 'instructor', error: e.message}));
        }
    };
    await teacher.connect(teacherEvent);
    process.stdout.write(`Instructor watching attempts. ${students.length} students signing in over ${rampMs / 1000}s...\n`);

    let releaseStart;
    const startSignal = new Promise(resolve => releaseStart = resolve);
    const endAt = {value: 0};
    let started = 0, submitted = 0;
    const finals = new Map();
    const runStudent = async (student, index) => {
        const browser = new Browser(student.student_id);
        try {
            await sleep(rampMs * index / students.length);
            await browser.login(student.student_id, fixture.pin);
            const page = await browser.request(quizPath, {}, 'exam_page');
            if (page.status !== 200) throw Error(`exam page ${page.status}`);
            browser.readPage(page.text);
            const exam = browser.find('take-quiz');
            await browser.loadLazy('exam_page_lazy');
            await browser.assets(page.text);
            // As resources/js/live-updates.js: classmates' attempts are ignored, the student's own arrive privately.
            await browser.connect((event, data, channel) => {
                if (event === 'subscribed' || (event === 'classroom.changed' && !['focus', 'progress', 'attempt'].includes(data.kind))
                    || (event === 'panel.changed' && data.kind === 'attempt' && channel.includes('App.Models.User'))) {
                    counters.studentPushRequests++;
                    browser.commit(exam, {calls: [{path: '', method: '__dispatch', params: ['classroom-push', data]}]}, 'student_live_refresh')
                        .catch(e => failures.push({who: student.student_id, error: e.message}));
                }
            });
            await startSignal;
            await sleep(Math.random() * startSpreadMs);
            const begun = await browser.commit(exam, {calls: [{path: '', method: 'beginAttempt', params: []}]}, 'exam_start');
            if (!JSON.stringify(begun.effects.html || '').includes('code-')) throw Error('Attempt did not start');
            started++;
            const projects = {php: JSON.parse(fixture.starter.php), web: JSON.parse(fixture.starter.web)};
            let step = 0;
            while (performance.now() < endAt.value) {
                await sleep(jitter(saveEvery));
                if (performance.now() >= endAt.value) break;
                step++;
                const which = step % 3 === 0 ? 'web' : 'php';
                edit(projects[which], step);
                const json = JSON.stringify(projects[which]);
                await browser.commit(exam, {updates: {[`answers.${fixture.questions[which]}`]: json}}, 'code_save');
                finals.set(`${student.id}:${fixture.questions[which]}`, json);
                if (step === 3) await browser.commit(exam, {updates: {[`answers.${fixture.questions.short}`]: '$_POST'}}, 'short_answer_save');
                // Exam supervision: a paste from outside and a tab switch now and then.
                if (step === 5 && index % 4 === 0) await browser.commit(exam, {calls: [{path: '', method: 'recordPaste', params: [180, 6]}]}, 'exam_paste_log');
                if (step === 7 && index % 5 === 0) {
                    await browser.commit(exam, {calls: [{path: '', method: 'recordFocusLost', params: ['tab']}]}, 'exam_tab_leave');
                    await sleep(1500);
                    await browser.commit(exam, {calls: [{path: '', method: 'recordFocusReturned', params: []}]}, 'exam_tab_return');
                }
            }
            // Some hand in early; the rest are submitted by the timer at the same second.
            if (index % 3 === 0) {
                await browser.commit(exam, {calls: [{path: '', method: 'confirmSubmission', params: []}]}, 'exam_confirm_submit');
            } else {
                await sleep(Math.max(0, endAt.timer - performance.now()));
            }
            const done = await browser.commit(exam, {calls: [{path: '', method: 'submit', params: []}]}, 'exam_submit');
            if (!done.snapshot.includes('"reviewMode":true')) throw Error('Submission not accepted');
            submitted++;
            await sleep(3000);
        } catch (error) {
            failures.push({who: student.student_id, error: error.message});
        } finally {
            browser.ws?.close();
        }
    };
    const runs = students.map(runStudent);
    await sleep(rampMs + 8000);
    process.stdout.write('Instructor opens the exam: students press Start.\n');
    const workStart = performance.now();
    endAt.value = workStart + startSpreadMs + minutes * 60000;
    endAt.timer = endAt.value + 15000;
    releaseStart();
    const progress = setInterval(() => process.stdout.write(`  started ${started}, submitted ${submitted}, requests ${records.length}, failures ${failures.length}\n`), 15000);
    await Promise.all(runs);
    clearInterval(progress);
    await sleep(2000);
    teacher.ws?.close();

    // Every student's last save must be exactly what was stored, and every attempt closed.
    let audit = null;
    if (args.db) {
        const {DatabaseSync} = require('node:sqlite');
        const db = new DatabaseSync(path.resolve(args.db), {readOnly: true});
        const files = json => Object.fromEntries((JSON.parse(json || '{}').files || []).map(f => [f.name, f.content]));
        const answer = db.prepare('SELECT a.answer_text FROM quiz_answers a JOIN quiz_attempts t ON t.id = a.quiz_attempt_id WHERE t.user_id = ? AND a.quiz_question_id = ?');
        let mismatched = 0;
        for (const [key, json] of finals) {
            const [user, question] = key.split(':').map(Number);
            if (JSON.stringify(files(answer.get(user, question)?.answer_text)) !== JSON.stringify(files(json))) mismatched++;
        }
        const statuses = Object.fromEntries(db.prepare('SELECT status, COUNT(*) AS n FROM quiz_attempts WHERE quiz_id = ? GROUP BY status').all(fixture.quiz).map(r => [r.status, r.n]));
        const duplicates = db.prepare('SELECT COUNT(*) AS n FROM (SELECT user_id FROM quiz_attempts WHERE quiz_id = ? GROUP BY user_id HAVING COUNT(*) > 1)').get(fixture.quiz).n;
        audit = {answers_checked: finals.size, answers_mismatched: mismatched, attempt_statuses: statuses, students_with_duplicate_attempts: duplicates,
            integrity: db.prepare('PRAGMA integrity_check').get().integrity_check};
        if (mismatched || duplicates || audit.integrity !== 'ok') failures.push({who: 'audit', error: JSON.stringify(audit)});
    }
    const labels = [...new Set(records.map(r => r.label))];
    const byLabel = Object.fromEntries(labels.map(label => [label, summarize(records.filter(r => r.label === label))]));
    const dynamic = records.filter(r => !r.label.startsWith('asset'));
    const saves = records.filter(r => r.label === 'code_save');
    const result = {
        students: students.length, minutes, save_every_ms: saveEvery, started, submitted, failures: failures.length,
        elapsed_s: +((performance.now() - t0) / 1000).toFixed(1),
        work_phase_requests_per_s: +(dynamic.filter(r => r.label !== 'login_page').length / ((performance.now() - workStart) / 1000)).toFixed(1),
        avg_save_kb: +(saves.reduce((s, r) => s + r.sent, 0) / Math.max(1, saves.length) / 1024).toFixed(1),
        asset_mb_per_student: +(records.filter(r => r.label.startsWith('asset')).reduce((s, r) => s + r.bytes, 0) / students.length / 1048576).toFixed(2),
        audit, counters, all_dynamic: summarize(dynamic), by_label: byLabel, failure_samples: failures.slice(0, 20),
        expected_answers: Object.fromEntries([...finals].map(([key, json]) => [key, crypto.createHash('sha256').update(json).digest('hex')])),
    };
    if (out) fs.writeFileSync(out, JSON.stringify(result, null, 2));
    const {expected_answers: _, ...shown} = result;
    process.stdout.write(JSON.stringify(shown, null, 2) + '\n');
    process.exitCode = failures.length || submitted !== students.length ? 1 : 0;
}
main().catch(error => { console.error(error); process.exitCode = 1; });
