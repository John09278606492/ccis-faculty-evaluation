const $ = id => document.getElementById(id);
let busy = false;
function render(s) {
    $('badge').textContent = s.state;
    $('status').textContent = s.state === 'running' ? 'Classroom is live' : s.initialized ? 'Your classroom server' : 'Welcome to CCIS Classroom';
    $('message').textContent = s.message;
    $('setup').hidden = s.initialized;
    $('controls').hidden = !s.initialized;
    $('version').textContent = s.version;
    $('data-path').textContent = s.dataPath;
    if (document.activeElement !== $('port')) $('port').value = s.port;
    $('port').disabled = busy || s.state !== 'stopped';
    $('start').disabled = busy || !['stopped', 'error'].includes(s.state);
    $('stop').disabled = busy || !['running', 'error'].includes(s.state);
    $('open').disabled = $('database').disabled = busy || s.state !== 'running';
    $('backup').disabled = busy || !s.initialized;
    $('restore').disabled = busy || s.state !== 'stopped';
    $('links').hidden = s.state !== 'running';
    $('local-url').textContent = s.url || '';
    $('network-links').replaceChildren();
    for (const url of s.links || []) {
        const button = document.createElement('button'); button.textContent = 'Copy student link: ' + url;
        button.onclick = async () => { await navigator.clipboard.writeText(url); button.textContent = 'Copied: ' + url; };
        $('network-links').append(button);
    }
}
async function action(callback) {
    if (busy) return;
    busy = true; $('error').hidden = true;
    document.querySelectorAll('button').forEach(b => b.disabled = true);
    try { const r = await callback(); if (!r.ok) throw new Error(r.error); }
    catch (e) { $('error').textContent = e.message; $('error').hidden = false; }
    finally { busy = false; await refresh(); }
}
async function refresh() { const r = await window.classroom.status(); if (r.ok) render(r.value); }
$('setup-form').onsubmit = e => { e.preventDefault(); const input = { name: $('name').value, email: $('email').value, password: $('password').value, port: Number($('setup-port').value) }; action(() => window.classroom.setup(input)).finally(() => { $('password').value = ''; }); };
for (const [id, method] of Object.entries({ stop: 'stop', open: 'openSystem', database: 'openDatabase', data: 'openData', backup: 'backup', restore: 'restore' })) $(id).onclick = () => action(() => window.classroom[method]());
$('start').onclick = () => action(() => window.classroom.start(Number($('port').value)));
setInterval(() => refresh().catch(() => {}), 1500);
refresh();
