import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';
const source = readFileSync(new URL('../../resources/js/workspace-drafts.js', import.meta.url), 'utf8').replace('export function', 'function');
function editor({ saved, leave }) {
    const listeners = new Map(), navigated = [], prompts = [], status = {};
    let data = { name: 'Saved' };
    const el = { isConnected: true, querySelector: selector => selector === '[data-draft-status]' ? status : null, addEventListener() {}, removeEventListener() {} };
    const component = { id: 'test', el, addCleanup() {} };
    const wire = { $get: () => data, $hook() {}, persistWorkspaceDraft: async () => { if (!saved) throw Error('Offline'); return { saved: true }; } };
    const context = { window: { Alpine: { watch: () => () => {} }, addEventListener() {}, removeEventListener() {}, confirm: message => { prompts.push(message); return leave; }, Livewire: { navigate: url => navigated.push(url) } }, document: { addEventListener: (name, fn) => listeners.set(name, fn), removeEventListener() {} }, requestAnimationFrame: fn => fn(), queueMicrotask, setTimeout, clearTimeout };
    vm.runInNewContext(source, context);
    context.window.mountWorkspaceDraft(wire, component, { paths: ['data'] }, { data });
    data = { name: 'Unsaved' };
    return { status, prompts, navigated, navigate: async () => { let prevented = false; await listeners.get('livewire:navigate')({ detail: { url: '/other' }, preventDefault() { prevented = true; } }); return prevented; } };
}
test('a failed draft save allows an explicit leave instead of trapping navigation', async () => {
    const app = editor({ saved: false, leave: true });
    assert.equal(await app.navigate(), true);
    assert.deepEqual(app.navigated, ['/other']);
    assert.equal(app.prompts.length, 1);
});
test('declining to leave preserves the unsaved editor and permits another navigation attempt', async () => {
    const app = editor({ saved: false, leave: false });
    await app.navigate(); await app.navigate();
    assert.equal(app.prompts.length, 2);
    assert.deepEqual(app.navigated, []);
    assert.match(app.status.textContent, /could not be saved/);
});
test('a successful save navigates without a warning', async () => {
    const app = editor({ saved: true, leave: false });
    await app.navigate();
    assert.equal(app.prompts.length, 0);
    assert.deepEqual(app.navigated, ['/other']);
});
