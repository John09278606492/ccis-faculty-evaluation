// Web-only server supervisor. No Electron window or desktop installation is needed.
const { fork } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const [project, php, port] = process.argv.slice(2);
const status = path.join(project, 'storage/app/campus-server.json');
const stopFile = path.join(project, 'storage/framework/campus-stop.request');
fs.rmSync(stopFile, { force: true });
const child = fork(path.join(project, 'desktop-host/resources/server/host.cjs'), [project, php, port, status, '--campus'], {
    windowsHide: true, stdio: ['ignore', 'inherit', 'inherit', 'ipc'],
});
let stopping = false;
function stop() {
    if (stopping) return;
    stopping = true;
    if (child.connected) child.send('stop');
}
const timer = setInterval(() => {
    if (fs.existsSync(stopFile)) { fs.rmSync(stopFile, { force: true }); stop(); }
}, 1000);
child.on('error', error => { console.error(error.message); clearInterval(timer); process.exitCode = 1; });
child.on('exit', code => { clearInterval(timer); process.exitCode = code ?? 1; });
process.on('SIGINT', stop);
process.on('SIGTERM', stop);
