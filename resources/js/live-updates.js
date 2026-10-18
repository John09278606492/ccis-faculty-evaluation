import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echo;
let activeChannel;
let booted = false;
window.Pusher = Pusher;

function status(message, connected = false) {
    const indicator = document.getElementById('classroom-live-status');
    if (indicator) {
        indicator.textContent = message;
        indicator.dataset.connected = String(connected);
    }
}

function refreshRelatedViews(event = { kind: 'reconnected', quizId: null, courseId: null }) {
    if (window.Livewire) window.Livewire.dispatch('classroom-push', event);
}

function connect() {
    const configElement = document.getElementById('classroom-live-config');
    if (!configElement) {
        echo?.disconnect();
        echo = null;
        activeChannel = null;
        return;
    }
    const config = JSON.parse(configElement.textContent);
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
