const { app, BrowserWindow, ipcMain, dialog, shell } = require('electron');
const path = require('node:path');
const fs = require('node:fs');
const { Runtime } = require('./runtime.cjs');
let window, runtime, quitting = false, closing = false;
async function quitSafely() {
    if (closing) return;
    if (runtime?.operation) { dialog.showErrorBox('Operation in progress', 'Wait until installation, backup or restore finishes before closing.'); return; }
    closing = true;
    try {
        if (runtime && Object.keys(runtime.children).length) await runtime.stop();
        quitting = true; app.quit();
    } catch (e) { closing = false; dialog.showErrorBox('Services are still running', e.message); }
}
if (!app.requestSingleInstanceLock()) app.quit();
else {
    app.on('second-instance', () => { window?.show(); window?.focus(); });
    app.whenReady().then(() => {
        const bundle = app.isPackaged ? path.join(process.resourcesPath, 'classroom') : path.join(__dirname, 'bundle');
        const data = path.join(app.getPath('userData'), 'classroom-data');
        runtime = new Runtime(bundle, data);
        window = new BrowserWindow({ width: 1100, height: 850, minWidth: 800, minHeight: 650, title: 'CCIS Classroom', webPreferences: { preload: path.join(__dirname, 'preload.cjs'), contextIsolation: true, nodeIntegration: false, sandbox: true } });
        window.removeMenu();
        window.on('close', event => { if (!quitting) { event.preventDefault(); quitSafely(); } });
        window.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
        window.webContents.on('will-navigate', event => event.preventDefault());
        const authorized = event => { if (event.sender !== window.webContents || !event.senderFrame.url.startsWith('file:')) throw new Error('Untrusted desktop request.'); };
        const handle = (name, callback) => ipcMain.handle(name, async (event, ...args) => { authorized(event); try { return { ok: true, value: await callback(...args) }; } catch (e) { return { ok: false, error: e.message }; } });
        handle('status', () => runtime.snapshot());
        handle('setup', input => runtime.setup(input));
        handle('start', port => runtime.start(port));
        handle('stop', () => runtime.stop());
        handle('open-system', () => { if (runtime.state !== 'running') throw new Error('Start the classroom first.'); return shell.openExternal(runtime.snapshot().url); });
        handle('open-database', () => shell.openExternal(runtime.databaseLink()));
        handle('open-data', () => { fs.mkdirSync(data, { recursive: true }); return shell.openPath(data); });
        handle('backup', async () => {
            const result = await dialog.showSaveDialog(window, { title: 'Save portable classroom backup', defaultPath: `CCIS-Classroom-${new Date().toISOString().slice(0, 10)}.zip`, filters: [{ name: 'CCIS backup', extensions: ['zip'] }] });
            if (result.canceled) return runtime.snapshot();
            return runtime.backup(result.filePath);
        });
        handle('restore', async () => {
            const result = await dialog.showOpenDialog(window, { title: 'Restore a CCIS desktop backup', properties: ['openFile'], filters: [{ name: 'CCIS desktop backup', extensions: ['zip'] }] });
            if (result.canceled) return runtime.snapshot();
            const confirm = await dialog.showMessageBox(window, { type: 'warning', buttons: ['Cancel', 'Restore backup'], defaultId: 0, cancelId: 0, message: 'Restore this classroom backup?', detail: 'The classroom must be stopped. Current data will be preserved in a recovery folder. The selected backup becomes the active classroom.' });
            if (confirm.response !== 1) return runtime.snapshot();
            return runtime.restore(result.filePaths[0]);
        });
        window.loadFile(path.join(__dirname, 'ui/index.html'));
    });
    app.on('before-quit', event => {
        if (quitting) return;
        event.preventDefault();
        quitSafely();
    });
    app.on('window-all-closed', () => app.quit());
}
