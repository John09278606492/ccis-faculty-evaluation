import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

const source = readFileSync(new URL('../../resources/js/live-updates.js', import.meta.url), 'utf8')
    .replace(/^import .*;\r?\n/gm, '');

function panel({ online = true, healthy = true } = {}) {
    let sockets = 0, refreshes = 0, probes = 0, tick;
    const indicator = { textContent: '', dataset: {} };
    const listeners = new Map();
    const context = {
        Echo: class { constructor() { sockets++; throw new Error('Polling must not open sockets'); } },
        Pusher: {},
        PanelRefreshQueue: class {
            reset() {} resume() {} pause() {} schedule() {}
            mark() { refreshes++; }
        },
        window: { Livewire: { all: () => [], hook() {}, dispatch() {} }, addEventListener() {} },
        navigator: { onLine: online },
        document: { hidden: false, activeElement: null,
            getElementById: id => id === 'classroom-live-config'
                ? { textContent: JSON.stringify({ transport: 'polling', channel: 'production.campus.3', staff: true }) }
                : indicator,
            addEventListener: (name, callback) => listeners.set(name, callback),
        },
        fetch: async () => { probes++; return { ok: healthy }; },
        AbortSignal: { timeout: () => undefined },
        setInterval: callback => { tick = callback; },
    };
    vm.runInNewContext(source, context);
    return { indicator, tick: () => tick(), navigate: () => listeners.get('livewire:navigated')(),
        counts: () => ({ sockets, refreshes, probes }) };
}

test('shared hosting updates pages without opening a WebSocket, including after navigation', async () => {
    const app = panel();
    app.navigate();
    await app.tick();
    assert.deepEqual(app.counts(), { sockets: 0, refreshes: 1, probes: 1 });
    assert.equal(app.indicator.textContent, 'Automatic updates active');
});

test('unreachable shared hosting does not accumulate refresh requests', async () => {
    const app = panel({ healthy: false });
    await app.tick();
    assert.deepEqual(app.counts(), { sockets: 0, refreshes: 0, probes: 1 });
    assert.match(app.indicator.textContent, /Connection lost/);
});

test('offline shared hosting pages do not poll or open sockets', async () => {
    const app = panel({ online: false });
    await app.tick();
    assert.deepEqual(app.counts(), { sockets: 0, refreshes: 0, probes: 0 });
});
