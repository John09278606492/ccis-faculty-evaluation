import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { PanelRefreshQueue } from './live-panel-refresh';

let echo;
let activeChannel;
let booted = false;
let staff = false;
let liveReady = false;
let subscribedChannels = new Set();
const panelRefresh = new PanelRefreshQueue({
    components: () => window.Livewire?.all() || [],
    focused: () => document.activeElement,
    visible: () => !document.hidden && navigator.onLine,
    refreshed: component => {
        component.el.querySelectorAll('iframe[data-live-report]').forEach(frame => {
            frame.contentWindow?.postMessage({ type: 'campus-report-refresh' }, window.location.origin);
        });
    },
});
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
    if (!['focus', 'progress'].includes(event.kind)) panelRefresh.mark({ skipPushHandlers: true, kind: event.kind });
}

const schedulePanelRefresh = event => panelRefresh.mark({ kind: event?.kind });

function connect() {
    const configElement = document.getElementById('classroom-live-config');
    if (!configElement) {
        panelRefresh.reset();
        echo?.disconnect();
        echo = null;
        window.Echo = undefined;
        activeChannel = null;
        liveReady = false;
        return;
    }
    const config = JSON.parse(configElement.textContent);
    staff = Boolean(config.staff);
    if (echo && activeChannel === config.channel) {
        // A page change (also fired once on the first load) brings fresh data from the server,
        // so nothing to refresh. Refreshing here re-rendered tables before their scripts loaded.
        status(liveReady ? 'Live updates connected' : 'Live updates reconnecting…', liveReady);
        return;
    }
    echo?.disconnect();
    liveReady = false;
    subscribedChannels = new Set();
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
        if (current !== 'connected') {
            subscribedChannels.clear();
            liveReady = false;
            status('Live updates disconnected — reconnecting…');
        }
    });
    const channelReady = channel => {
        subscribedChannels.add(channel);
        liveReady = [config.channel, ...(config.panelChannels || [])].every(name => subscribedChannels.has(name));
        status(liveReady ? 'Live updates connected' : 'Connecting live updates…', liveReady);
    };
    echo.private(config.channel)
        .subscribed(() => { channelReady(config.channel); refreshRelatedViews(); })
        .error(() => { liveReady = false; status('Live updates unavailable — retrying automatically'); })
        .listen('.classroom.changed', refreshRelatedViews);
    for (const channel of config.panelChannels || []) {
        echo.private(channel)
            .subscribed(() => { channelReady(channel); schedulePanelRefresh(); })
            .error(() => { subscribedChannels.delete(channel); liveReady = false; status('Live updates unavailable — retrying automatically'); })
            .listen('.panel.changed', schedulePanelRefresh);
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
    document.addEventListener('livewire:navigating', () => panelRefresh.reset());
    document.addEventListener('focusout', () => panelRefresh.schedule(300));
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshRelatedViews(); });
    window.addEventListener('online', () => { echo?.connector.pusher.connect(); refreshRelatedViews(); });
    // Reverb is primary; a temporary socket outage still catches up automatically.
    setInterval(() => {
        if (!document.hidden && navigator.onLine && activeChannel && !liveReady) {
            status('Auto updates active — reconnecting live connection…');
            refreshRelatedViews();
        }
    }, 20000);
}
document.addEventListener('livewire:initialized', boot);
boot();
