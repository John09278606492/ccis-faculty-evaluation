const editors = new Set();
let navigating = false, bypassNavigation = false;
async function guardDraftNavigation(event) {
    if (bypassNavigation) { bypassNavigation = false; return; }
    const changed = [...editors].filter(editor => editor.connected() && editor.needsWait());
    if (!changed.length || event.defaultPrevented) return;
    event.preventDefault();
    if (navigating) return;
    navigating = true;
    const url = event.detail.url.toString();
    try {
        if ((await Promise.all(changed.map(editor => editor.flush()))).every(Boolean)) {
            // Livewire resolves a call before its queued DOM morph completes.
            // Let that finish before replacing the page containing the editor.
            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            bypassNavigation = true;
            window.Livewire.navigate(url);
        }
    } finally { navigating = false; }
}

// One instance per Livewire editor. Kept in memory only; the durable copy is
// encrypted on the server and authorized again before restoration/submission.
export function mountWorkspaceDraft(wire, component, config, initialState) {
    let timer, pending, destroyed = false, paused = false, inFlight = 0, inputDeadline = 0;
    const clone = value => JSON.parse(JSON.stringify(value ?? null));
    const collect = () => Object.fromEntries(config.paths.map(path => {
        const raw = clone(wire.$get(path));
        const allowed = config.keys?.[path];
        return [path, allowed && raw ? Object.fromEntries(allowed.filter(key => key in raw).map(key => [key, raw[key]])) : raw];
    }));
    let last = JSON.stringify(initialState ?? collect());
    const status = message => {
        const el = component.el.querySelector('[data-draft-status]');
        if (el) el.textContent = ` — ${message}`;
    };
    const active = () => !config.action || wire.mountedTableActions?.at(-1) === config.action;
    const dirty = () => active() && JSON.stringify(collect()) !== last;
    const captureInput = event => {
        const el = event.target;
        if (!el.matches?.('input:not([type="file"]):not([type="password"]), textarea') || el.closest('[wire\\:id]') !== component.el) return;
        const binding = [...el.attributes].find(attr => attr.name.startsWith('wire:model') && attr.name.includes('.debounce'));
        if (!binding || !config.paths.some(path => binding.value === path || binding.value.startsWith(path + '.'))) return;
        // Alpine's explicit .debounce delays the model setter itself. Capture the
        // typed value now so a menu click cannot mistake it for an untouched form.
        const delay = binding.name.match(/\.debounce\.(\d+)(ms|s)/);
        inputDeadline = Date.now() + (delay ? Number(delay[1]) * (delay[2] === 's' ? 1000 : 1) : 250) + 40;
        wire.$set(binding.value, el.type === 'checkbox' ? el.checked : el.value, false);
    };
    const settleInputs = async () => {
        const started = Date.now();
        while (!destroyed && (Date.now() < inputDeadline || inFlight)) {
            if (Date.now() - started > 15000) return false;
            await new Promise(resolve => setTimeout(resolve, 30));
        }
        return !destroyed;
    };
    async function flush() {
        clearTimeout(timer);
        if (pending) { const ok = await pending; return ok && dirty() ? flush() : ok; }
        // Serialize the whole operation, including pending input commits. A menu
        // click and the debounce timer can otherwise both begin a draft write.
        pending = (async () => {
            if (destroyed || !component.el.isConnected) return false;
            if (!await settleInputs()) { status('Still saving. Keep this page open and retry.'); return false; }
            if (wire.workspaceDraftAvailable && dirty()) {
                status('Resume or discard the existing draft before editing this form.');
                return false;
            }
            if (paused || !dirty()) return !dirty();
            const state = collect();
            status('Saving draft…');
            try {
                const result = await wire.persistWorkspaceDraft(state);
                if (!result?.saved) throw new Error('Draft not saved');
                last = JSON.stringify(state);
                status(result.at ? 'Draft saved. You can leave and resume here.' : 'All changes saved.');
                return true;
            } catch {
                status('Draft could not be saved. Keep this page open and retry.');
                return false;
            }
        })();
        try { return await pending; } finally { pending = null; }
    }
    const schedule = () => {
        clearTimeout(timer);
        if (paused || wire.workspaceDraftAvailable || !dirty()) return;
        status('Unsaved draft changes…');
        timer = setTimeout(flush, 800);
    };
    const unwatch = window.Alpine.watch(() => JSON.stringify(collect()), schedule);
    const readyControls = ready => {
        if (destroyed || !component.el.isConnected) return;
        const banner = component.el.querySelector('[data-workspace-draft]');
        if (banner) window.Alpine.$data(banner).draftReady = ready;
    };
    const changeDraft = async discard => {
        if (discard && !window.confirm('Discard your unfinished changes here and use the saved version?')) return;
        readyControls(false);
        try {
            if (config.action) {
                const table = component.el.querySelector('.fi-ta');
                // On phones the table may start below the viewport. Bring it into
                // view on this explicit click so Filament can load its controls.
                if (table && typeof window.Alpine.$data(table).mountAction !== 'function') table.scrollIntoView({ block: 'nearest' });
                const started = Date.now();
                while (table && typeof window.Alpine.$data(table).mountAction !== 'function') {
                    if (destroyed || Date.now() - started > 10000) {
                        status('The evaluator list is still loading. Please try again.');
                        return;
                    }
                    await new Promise(resolve => setTimeout(resolve, 60));
                }
                await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            }
            await (discard ? wire.discardWorkspaceDraft() : wire.resumeWorkspaceDraft());
        } catch { status('Draft could not be opened. Review the message here and try again.'); }
        finally { readyControls(true); }
    };
    const reset = event => {
        if (event.detail.component !== component.id) return;
        clearTimeout(timer);
        last = JSON.stringify(event.detail.state ?? collect());
        status(wire.workspaceDraftSavedAt ? 'Draft resumed. Review and save when ready.' : 'All changes saved.');
        queueMicrotask(schedule);
    };
    const unload = event => {
        if (destroyed || component.effects?.redirect || (!dirty() && !pending && !inFlight)) return;
        event.preventDefault(); event.returnValue = true;
    };
    // Pause autosave while a real Save/Create call is executing. The server
    // version marker also rejects an obsolete in-flight draft after a Save.
    wire.$hook('commit', ({ commit, succeed, fail }) => {
        inFlight++;
        const finished = () => { inFlight = Math.max(0, inFlight - 1); };
        succeed(() => queueMicrotask(() => queueMicrotask(finished)));
        fail(finished);
        const editing = commit.calls.some(call => !['persistWorkspaceDraft', 'resumeWorkspaceDraft', 'discardWorkspaceDraft', '$refresh'].includes(call.method));
        if (!editing) return;
        clearTimeout(timer); paused = true;
        succeed(() => queueMicrotask(() => { paused = false; schedule(); }));
        fail(() => { paused = false; schedule(); });
    });
    component.workspaceDraft = { flush, dirty, active, resume: () => changeDraft(false), discard: () => changeDraft(true), hasPending: () => Boolean(pending) };
    readyControls(true);
    const editor = { flush, needsWait: () => dirty() || inFlight > 0 || Date.now() < inputDeadline, connected: () => !destroyed && component.el.isConnected && !component.effects?.redirect };
    if (!editors.size) document.addEventListener('livewire:navigate', guardDraftNavigation);
    editors.add(editor);
    document.addEventListener('workspace-draft-reset', reset);
    document.addEventListener('workspace-draft-resumed', reset);
    document.addEventListener('workspace-draft-focus', reset);
    window.addEventListener('beforeunload', unload);
    component.el.addEventListener('input', captureInput, true);
    component.addCleanup(() => {
        destroyed = true; clearTimeout(timer); unwatch?.();
        editors.delete(editor);
        if (!editors.size) document.removeEventListener('livewire:navigate', guardDraftNavigation);
        document.removeEventListener('workspace-draft-reset', reset);
        document.removeEventListener('workspace-draft-resumed', reset);
        document.removeEventListener('workspace-draft-focus', reset);
        window.removeEventListener('beforeunload', unload);
        component.el.removeEventListener('input', captureInput, true);
        delete component.workspaceDraft;
    });
    queueMicrotask(schedule);
}

window.mountWorkspaceDraft = mountWorkspaceDraft;
