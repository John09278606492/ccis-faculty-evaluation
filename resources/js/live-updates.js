import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echo;
let activeChannel;
let booted = false;
let staff = false;
let panelTimer = null;
let lastPanelRefresh = 0;
window.Pusher = Pusher;

function status(message, connected = false) {
    const indicator = document.getElementById('classroom-live-status');
    if (indicator) {
        indicator.textContent = message;
        indicator.dataset.connected = String(connected);
    }
}

function refreshRelatedViews(event = { kind: 'reconnected', quizId: null, courseId: null }) {
    // Exam progress and tab-leave updates only matter to instructors watching attempts.
    if (!staff && ['focus', 'progress'].includes(event.kind)) return;
    if (window.Livewire) window.Livewire.dispatch('classroom-push', event);
}

// True while the viewer is in the middle of something a re-render could disturb.
function busy(component) {
    const data = component.ephemeral || {};
    const open = (name) => { const value = data[name]; return Array.isArray(value) ? value.length > 0 : Boolean(value); };
    if (['mountedActions', 'mountedTableActions', 'mountedTableBulkAction', 'mountedFormComponentActions', 'mountedInfolistActions'].some(open)) return true;
    const focused = document.activeElement;
    return Boolean(focused && focused !== document.body && component.el.contains(focused) && focused.matches('input, textarea, select, [contenteditable]'));
}

const ownedBy = (component, selector) => [...component.el.querySelectorAll(selector)]
    .some((element) => element.closest('[wire\\:id]') === component.el);

// Reload the tables and widgets on screen. Pages with their own classroom handler
// (exams, quiz editor, class records) are left to it.
function refreshPanelViews() {
    if (!window.Livewire) return;
    for (const component of window.Livewire.all()) {
        if (!component.el?.isConnected) continue;
        if ((component.effects?.listeners || []).includes('classroom-push')) continue;
        if (!ownedBy(component, '.fi-ta, .fi-wi-widget')) continue;
        if (busy(component)) continue;
        component.$wire.$refresh();
    }
    lastPanelRefresh = Date.now();
}

// At most one reload every two seconds, always including the latest change.
function schedulePanelRefresh() {
    if (panelTimer) return;
    panelTimer = setTimeout(() => { panelTimer = null; refreshPanelViews(); }, Math.max(400, 2000 - (Date.now() - lastPanelRefresh)));
}

function connect() {
    const configElement = document.getElementById('classroom-live-config');
    if (!configElement) {
        echo?.disconnect();
        echo = null;
        window.Echo = undefined;
        activeChannel = null;
        return;
    }
    const config = JSON.parse(configElement.textContent);
    staff = Boolean(config.staff);
    if (echo && activeChannel === config.channel) {
        // A page change (also fired once on the first load) brings fresh data from the server,
        // so nothing to refresh. Refreshing here re-rendered tables before their scripts loaded.
        status(echo.connector.pusher.connection.state === 'connected' ? 'Live updates connected' : 'Live updates reconnecting…', echo.connector.pusher.connection.state === 'connected');
        return;
    }
    echo?.disconnect();
    activeChannel = config.channel;
    echo = new Echo({
        broadcaster: 'reverb', key: config.key,
        wsHost: config.host || window.location.hostname,
        wsPort: config.port, wssPort: config.port,
        forceTLS: (config.scheme || window.location.protocol.replace(':', '')) === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: { headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' } },
    });
    echo.connector.pusher.connection.bind('state_change', ({ current }) => {
        if (current !== 'connected') status('Live updates disconnected — reconnecting…');
    });
    echo.private(config.channel)
        .subscribed(() => { status('Live updates connected', true); refreshRelatedViews(); })
        .error(() => status('Live updates unavailable — check your connection or sign in again'))
        .listen('.classroom.changed', refreshRelatedViews);
    for (const channel of config.panelChannels || []) {
        echo.private(channel).listen('.panel.changed', schedulePanelRefresh);
    }
    // Filament's notification bell listens on window.Echo once it announces itself.
    window.Echo = echo;
    window.dispatchEvent(new CustomEvent('EchoLoaded'));
}

function boot() {
    if (!window.Livewire || booted) return;
    booted = true;
    window.Livewire.hook('request', ({ options }) => {
        if (echo?.socketId()) options.headers['X-Socket-ID'] = echo.socketId();
    });
    connect();
    document.addEventListener('livewire:navigated', connect);
    window.addEventListener('online', () => echo?.connector.pusher.connect());
}
document.addEventListener('livewire:initialized', boot);
boot();
