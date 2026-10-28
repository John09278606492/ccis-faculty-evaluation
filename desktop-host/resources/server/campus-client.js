/* An open page keeps its original server version for mutations and Livewire. */
(() => {
    const id = document.currentScript?.dataset.release;
    if (!/^[a-f0-9]{40}$/.test(id ?? '')) return;
    window.__campusPageRelease = id;
    if (window.__campusVersionHook) return;
    window.__campusVersionHook = true;
    const tagged = value => {
        const u = new URL(value || location.href, location.href);
        if (u.origin !== location.origin) return u.href;
        const raw=u.href.slice(0,u.href.length-u.hash.length);
        return (/[?&]__campus_release=/.test(raw) ? raw.replace(/([?&]__campus_release=)[^&#]*/g,'$1'+window.__campusPageRelease)
            : raw+(u.search?'&':'?')+'__campus_release='+window.__campusPageRelease)+u.hash;
    };
    const nativeFetch = window.fetch.bind(window);
    window.fetch = (input, options = {}) => {
        const method = (options.method ?? input?.method ?? 'GET').toUpperCase();
        const url = new URL(typeof input === 'string' || input instanceof URL ? input : input.url, location.href);
        if (url.origin === location.origin && method !== 'GET' && method !== 'HEAD') {
            const headers = new Headers(options.headers ?? input?.headers);
            headers.set('X-Campus-Release', window.__campusPageRelease);
            options = {...options, headers};
        }
        return nativeFetch(input, options);
    };
    document.addEventListener('submit', event => {
        const form = event.target;
        if (form instanceof HTMLFormElement && form.method.toUpperCase() !== 'GET') form.action = tagged(form.action);
    }, true);
    const open = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function(method, url, ...rest) {
        const result = open.call(this, method, url, ...rest);
        if (new URL(url, location.href).origin === location.origin && !/^(GET|HEAD)$/i.test(method)) this.setRequestHeader('X-Campus-Release', window.__campusPageRelease);
        return result;
    };
    const heartbeat = () => nativeFetch('/_campus/lease/'+window.__campusPageRelease, {method:'POST', credentials:'same-origin'}).catch(() => {});
    heartbeat();
    setInterval(heartbeat, 60000);
})();
