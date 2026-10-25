const readOnlyActions = new Set(['view', 'view_details', 'view_submission', 'view_scores', 'enrolledSubjects']);

export function isBusy(component, focused) {
    const data = component.ephemeral || {};
    for (const key of ['mountedActions', 'mountedTableActions', 'mountedTableBulkAction', 'mountedFormComponentActions', 'mountedInfolistActions']) {
        const value = data[key];
        const actions = Array.isArray(value) ? value : value ? [value] : [];
        if (actions.some(action => !readOnlyActions.has(action))) return true;
    }
    return Boolean(focused && component.el.contains(focused) && focused.matches('input, textarea, select, [contenteditable]')
        && !focused.hasAttribute('data-live-readonly-control'));
}

const owns = (component, selector) => component.el.matches(selector) || [...component.el.querySelectorAll(selector)]
    .some(element => element.closest('[wire\\:id]') === component.el);

export function refreshable(component) {
    if (!component.el?.isConnected) return false;
    // Classroom tables also depend on panel setup changes. Active exam pages and
    // assessment editors keep their own handlers, which preserve answer/edit state.
    return (component.effects?.listeners || []).includes('classroom-push')
        ? owns(component, '.fi-ta')
        : owns(component, '.fi-ta, .fi-wi-widget, [data-live-panel]');
}

/** Coalesce events, retain blocked updates, and flush automatically once editing ends. */
export class PanelRefreshQueue {
    constructor({ components, focused, visible, timer = (...args) => setTimeout(...args), cancel = id => clearTimeout(id), now = Date.now, refreshed = () => {} }) {
        Object.assign(this, { components, focused, visible, timer, cancel, now, refreshed });
        this.pending = new Set();
        this.inFlight = new Set();
        this.last = 0;
        this.timeout = null;
    }

    mark({ skipPushHandlers = false, kind = null } = {}) {
        for (const component of this.components()) {
            if (skipPushHandlers && (component.effects?.listeners || []).includes('classroom-push')) continue;
            const kinds = component.el?.getAttribute?.('data-live-kinds');
            if (kind && kind !== 'reconnected' && kinds && !kinds.split(' ').includes(kind)) continue;
            if (refreshable(component)) this.pending.add(component.id);
        }
        this.schedule();
    }

    schedule(delay = Math.max(400, 2000 - (this.now() - this.last))) {
        if (this.timeout !== null || !this.pending.size) return;
        this.timeout = this.timer(() => { this.timeout = null; this.flush(); }, delay);
    }

    async flush() {
        if (!this.visible()) return;
        const current = new Map(this.components().map(component => [component.id, component]));
        const work = [];
        for (const id of [...this.pending]) {
            const component = current.get(id);
            if (!component || !refreshable(component)) { this.pending.delete(id); continue; }
            if (this.inFlight.has(id) || isBusy(component, this.focused())) continue;
            this.pending.delete(id);
            this.inFlight.add(id);
            work.push(Promise.resolve().then(() => component.el.getAttribute?.('data-live-refresh-method') === 'refreshDisplayedRecord'
                ? component.$wire.refreshDisplayedRecord() : component.$wire.$refresh()).then(() => this.refreshed(component))
                .catch(() => { this.pending.add(id); })
                .finally(() => this.inFlight.delete(id)));
        }
        if (work.length) this.last = this.now();
        await Promise.all(work);
        if (this.pending.size) this.schedule(work.length ? 5000 : 1000);
    }

    reset() {
        if (this.timeout !== null) this.cancel(this.timeout);
        this.timeout = null;
        this.pending.clear();
    }
}
