import test from 'node:test';
import assert from 'node:assert/strict';
import { PanelRefreshQueue, isBusy, refreshable } from '../../resources/js/live-panel-refresh.js';

function fixture() {
    let calls = 0, focused = null, visible = true;
    const component = { id: 'table', ephemeral: {}, effects: {},
        el: { isConnected: true, matches: () => true, querySelectorAll: () => [], contains: () => true },
        $wire: { $refresh: async () => { calls++; } } };
    const queue = new PanelRefreshQueue({ components: () => [component], focused: () => focused, visible: () => visible,
        timer: () => 1, cancel: () => {}, now: () => 10000 });
    return { component, queue, calls: () => calls, focus: value => { focused = value; }, visibility: value => { visible = value; } };
}

test('many pushes coalesce into one authorized component refresh', async () => {
    const f = fixture();
    f.queue.mark(); f.queue.mark(); f.queue.mark();
    await f.queue.flush();
    assert.equal(f.calls(), 1);
    assert.equal(f.queue.pending.size, 0);
});

test('navigation refreshes for permission/setup changes without reloading the page or reacting to unrelated scores', async () => {
    const f = fixture();
    f.component.el.getAttribute = name => name === 'data-live-kinds' ? 'setup users access settings' : null;
    f.queue.mark({ kind: 'evaluation' }); await f.queue.flush();
    assert.equal(f.calls(), 0);
    f.queue.mark({ kind: 'access' }); await f.queue.flush();
    assert.equal(f.calls(), 1);
});

test('read-only details use a fresh authorized record instead of a stale form snapshot', async () => {
    const f = fixture(); let reloaded = 0;
    f.component.el.getAttribute = name => name === 'data-live-refresh-method' ? 'refreshDisplayedRecord' : null;
    f.component.$wire.refreshDisplayedRecord = async () => { reloaded++; };
    f.queue.mark(); await f.queue.flush();
    assert.equal(reloaded, 1);
    assert.equal(f.calls(), 0);
});

test('an update received during an edit modal automatically runs after it closes', async () => {
    const f = fixture();
    f.component.ephemeral.mountedTableActions = ['edit'];
    f.queue.mark(); await f.queue.flush();
    assert.equal(f.calls(), 0);
    assert.equal(f.queue.pending.size, 1);
    f.component.ephemeral.mountedTableActions = [];
    await f.queue.flush();
    assert.equal(f.calls(), 1);
});

test('focused draft inputs defer refresh until typing ends', async () => {
    const f = fixture();
    f.focus({ matches: () => true, hasAttribute: () => false });
    f.queue.mark(); await f.queue.flush();
    assert.equal(f.calls(), 0);
    f.focus(null); await f.queue.flush();
    assert.equal(f.calls(), 1);
});

test('read-only report modal and selector remain live', async () => {
    const f = fixture();
    f.component.ephemeral.mountedTableActions = ['view_details'];
    const selector = { matches: () => true, hasAttribute: name => name === 'data-live-readonly-control' };
    assert.equal(isBusy(f.component, selector), false);
    f.focus(selector); f.queue.mark(); await f.queue.flush();
    assert.equal(f.calls(), 1);
});

test('hidden tabs retain updates and catch up when shown', async () => {
    const f = fixture();
    f.visibility(false); f.queue.mark(); await f.queue.flush();
    assert.equal(f.calls(), 0);
    f.visibility(true); await f.queue.flush();
    assert.equal(f.calls(), 1);
});

test('custom exam handlers and disconnected components are not generically refreshed', () => {
    const f = fixture();
    f.component.effects.listeners = ['classroom-push'];
    f.component.el.matches = selector => selector !== '.fi-ta';
    assert.equal(refreshable(f.component), false);
    f.component.effects.listeners = [];
    f.component.el.isConnected = false;
    assert.equal(refreshable(f.component), false);
});

test('classroom catalogues receive setup changes without duplicating their classroom handler', async () => {
    const f = fixture();
    f.component.effects.listeners = ['classroom-push'];
    f.queue.mark({ skipPushHandlers: true }); await f.queue.flush();
    assert.equal(f.calls(), 0);
    f.queue.mark(); await f.queue.flush();
    assert.equal(f.calls(), 1);
    f.component.ephemeral.mountedTableActions = ['previewWorkbook'];
    f.queue.mark(); await f.queue.flush();
    assert.equal(f.calls(), 1, 'An open workbook may contain unsaved cell edits');
});

test('a change arriving during an in-flight refresh is retained without overlapping requests', async () => {
    const f = fixture();
    let resolve, calls = 0;
    f.component.$wire.$refresh = () => { calls++; return new Promise(done => { resolve = done; }); };
    f.queue.mark(); const first = f.queue.flush();
    await Promise.resolve();
    f.queue.mark(); await f.queue.flush();
    assert.equal(calls, 1);
    resolve(); await first;
    assert.equal(f.queue.pending.size, 1);
    const second = f.queue.flush(); await Promise.resolve();
    assert.equal(calls, 2);
    resolve(); await second;
});

test('a temporary request failure retries the retained update', async () => {
    const f = fixture();
    let calls = 0;
    f.component.$wire.$refresh = async () => { if (++calls === 1) throw Error('Temporary disconnect'); };
    f.queue.mark(); await f.queue.flush();
    assert.equal(f.queue.pending.size, 1);
    await f.queue.flush();
    assert.equal(calls, 2);
    assert.equal(f.queue.pending.size, 0);
});

test('navigation pauses background refreshes until the new page is ready', async () => {
    const f = fixture();
    f.queue.mark();
    f.queue.pause();
    f.queue.mark();
    await f.queue.flush();
    assert.equal(f.calls(), 0);
    assert.equal(f.queue.pending.size, 0);
    f.queue.resume();
    f.queue.mark();
    await f.queue.flush();
    assert.equal(f.calls(), 1);
});

test('a failed refresh from a previous page cannot schedule retries after navigation', async () => {
    const f = fixture();
    let reject;
    f.component.$wire.$refresh = () => new Promise((_, fail) => { reject = fail; });
    f.queue.mark();
    const request = f.queue.flush();
    await Promise.resolve();
    f.queue.pause();
    f.queue.resume();
    reject(new Error('Old page disconnected'));
    await request;
    assert.equal(f.queue.pending.size, 0);
    assert.equal(f.queue.inFlight.size, 0);
});
