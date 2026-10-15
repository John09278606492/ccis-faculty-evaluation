<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}"><title>CCIS Classroom Server</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f3f6fb;color:#172239;font:16px/1.5 system-ui,sans-serif}main{max-width:1020px;margin:auto;padding:38px 42px}header{display:flex;align-items:center;gap:16px;margin-bottom:32px}.mark{background:#2347c8;color:white;border-radius:16px;font-weight:800;padding:15px;font-size:20px}.eyebrow{color:#567;font-size:12px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase}h1{font-size:27px;margin:2px 0}h2{font-size:19px;margin:0 0 12px}p{margin:8px 0;color:#536079}.card{background:white;border:1px solid #dce3ef;border-radius:18px;padding:26px;margin-bottom:20px;box-shadow:0 6px 22px #243c6506}.top{display:flex;justify-content:space-between;align-items:center;gap:20px}.badge{display:inline-flex;align-items:center;gap:9px;background:#eef2f7;padding:7px 13px;border-radius:25px;font-size:14px;font-weight:650}.dot{height:9px;width:9px;border-radius:50%;background:#8995a9}.running .dot{background:#12a36a}.running{background:#e9f8ef;color:#127542}.error{background:#fff0ee;color:#ae352d}.error .dot{background:#df5144}button,.button{cursor:pointer;border:1px solid #cad4e5;border-radius:10px;padding:11px 18px;background:white;color:#263955;font:600 14px system-ui;text-decoration:none;display:inline-block}button:disabled{opacity:.45;cursor:default}.primary{background:#244cd5;border-color:#244cd5;color:white}button:hover:not(:disabled),.button:hover{filter:brightness(.96)}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}.linkbox{display:flex;gap:10px;align-items:center;margin-top:15px;background:#f3f6fc;padding:15px;border-radius:12px}.linkbox input{font-size:23px;font-weight:650;letter-spacing:-.5px;border:0;background:transparent;color:#2047bf;width:100%;min-width:0}.hint{font-size:13px;color:#65728a}ol{padding-left:22px;color:#536079}li{margin:8px 0}summary{cursor:pointer;font-weight:650}label{display:block;margin-top:15px;font-size:14px;font-weight:600}input[type=text],input[type=number],select{width:100%;padding:10px 12px;border:1px solid #ccd6e5;border-radius:8px;background:white;color:#263955;font:inherit}select{margin-top:10px}input:focus,button:focus-visible,a:focus-visible,select:focus{outline:3px solid #a7bbff;outline-offset:2px}.grid{display:grid;grid-template-columns:1.15fr 1fr;gap:20px}.grid .card{margin:0}#notice{color:#b23326;margin:14px 0}footer{color:#728097;font-size:12px;margin-top:20px}[hidden]{display:none!important}@media(max-width:800px){main{padding:24px}.grid{grid-template-columns:1fr}.top{align-items:flex-start}.linkbox input{font-size:18px}}
    </style>
</head>
<body><main>
    <header><div class="mark">CCIS</div><div><div class="eyebrow">Faculty evaluation · quizzes · class records</div><h1>Your classroom server</h1></div></header>
    <section class="card">
        <div class="top"><div><h2>Connect your classroom</h2><p id="message" aria-live="polite">Checking server status…</p></div><span id="badge" class="badge"><span class="dot"></span><span id="state">Checking</span></span></div>
        <div class="actions"><button id="start" class="primary">Start server</button><button id="stop" disabled>Stop server</button><a id="open" class="button" href="#" target="_blank" rel="noopener" hidden>Open system ↗</a></div>
        <div id="notice" role="alert" hidden></div>
    </section>
    <section class="card" id="sharing" hidden>
        <div class="eyebrow">Share this link</div><h2>Other devices can join here</h2>
        <select id="networks" aria-label="Wi-Fi or network address"></select>
        <div class="linkbox"><input id="link" readonly aria-label="Classroom access link"><button id="copy" class="primary">Copy link</button></div>
        <p class="hint">Open this link in a browser on a device connected to the same Wi-Fi. Each person signs in with their own account.</p>
    </section>
    <div class="grid">
        <section class="card"><h2>Keep the classroom connected</h2><ol><li>Keep this app open and the main computer awake.</li><li>Use the same Wi-Fi network on every device.</li><li>Saved quiz and class-record changes appear automatically on connected screens.</li></ol><p class="hint">If a device cannot connect, allow the host application on your Windows private network. Guest Wi-Fi may prevent devices from reaching each other.</p></section>
        <section class="card"><details><summary>Server settings</summary><p class="hint">Stop the server before changing these settings. Your existing classroom database and workbooks stay in their current location.</p>
            <form id="settings"><label>Classroom project folder<input type="text" name="project" value="{{ $settings['project'] }}" required></label><label>PHP executable<input type="text" name="php" value="{{ $settings['php'] }}" required></label><label>Sharing port<input type="number" name="port" value="{{ $settings['port'] }}" min="1024" max="65524" required></label><label><input type="checkbox" name="autostart" @checked($settings['autostart'])> Start server when this app opens</label><div class="actions"><button id="save">Save settings</button></div></form>
        </details><p class="hint">This computer hosts the system. Closing the desktop app stops access for connected devices.</p></section>
    </div><footer>CCIS Classroom Server · Powered by NativePHP · Local network access</footer>
</main>
<script>
const initial = @json($settings);
const el = id => document.getElementById(id);
let port = initial.port;
let busy = false;
async function request(url, body) {
    const response = await fetch(url, { method: body === undefined ? 'GET' : 'POST', headers: { 'Accept':'application/json', 'Content-Type':'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content }, ...(body === undefined ? {} : {body:JSON.stringify(body)}) });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'The action could not be completed.');
    return data;
}
function showError(error) { el('notice').textContent = error.message; el('notice').hidden = false; }
function render(data) {
    const running = data.state === 'running';
    const starting = data.state === 'starting';
    el('message').textContent = data.message;
    el('state').textContent = ({running:'Live · ready',starting:'Starting',stopped:'Offline',error:'Needs attention'})[data.state] || data.state;
    el('badge').className = 'badge ' + data.state;
    el('start').disabled = running || starting || busy;
    el('stop').disabled = (!running && !starting) || busy;
    el('save').disabled = running || starting;
    el('open').hidden = !running;
    el('open').href = `http://127.0.0.1:${data.port || port}`;
    el('sharing').hidden = !running;
    const selected = el('networks').value;
    el('networks').replaceChildren(...(data.links || []).map(item => new Option(`${item.name} — ${item.url}`, item.url)));
    if ((data.links || []).some(item => item.url === selected)) el('networks').value = selected;
    el('networks').hidden = (data.links || []).length < 2;
    el('link').value = el('networks').value || 'Connect this computer to Wi-Fi';
    el('copy').disabled = !(data.links || []).length;
}
async function refreshUntilReady() {
    for (let attempt = 0; attempt < 45; attempt++) {
        const data = await request('/host/status'); render(data);
        if (data.state !== 'starting') return data;
        await new Promise(resolve => setTimeout(resolve, 1000));
    }
    throw new Error('Startup is taking longer than expected. Stop the server and check Settings.');
}
async function action(name) {
    if (busy) return;
    busy = true; el('notice').hidden = true;
    el('start').disabled = el('stop').disabled = true;
    try { render(await request(`/host/${name}`, {})); }
    catch(error) { showError(error); }
    finally { busy = false; }
    try { await refreshUntilReady(); } catch(error) { showError(error); el('stop').disabled = false; }
}
el('start').onclick = () => action('start');
el('stop').onclick = () => action('stop');
el('networks').onchange = () => { el('link').value = el('networks').value; };
el('copy').onclick = async () => { try { await navigator.clipboard.writeText(el('link').value); el('copy').textContent = 'Copied'; setTimeout(() => el('copy').textContent = 'Copy link', 1800); } catch { el('link').select(); document.execCommand('copy'); } };
el('settings').onsubmit = async event => {
    event.preventDefault(); el('notice').hidden = true;
    const form = new FormData(event.target);
    try { await request('/host/settings', { project:form.get('project'), php:form.get('php'), port:Number(form.get('port')), autostart:form.has('autostart') }); port = Number(form.get('port')); el('save').textContent = 'Saved'; setTimeout(() => el('save').textContent = 'Save settings', 1800); }
    catch(error) { showError(error); }
};
(async () => { try { const data = await refreshUntilReady(); if (initial.autostart && data.state === 'stopped') await action('start'); } catch(error) { showError(error); } })();
// This checks the local process indicator only. Classroom screens use WebSocket push.
setInterval(async () => { if (!busy && !document.hidden) { try { render(await request('/host/status')); } catch(error) { showError(error); } } }, 5000);
</script></body></html>
