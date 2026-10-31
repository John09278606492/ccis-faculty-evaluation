// ID-only evaluation entry has no staff login. Refresh only its assignment list;
// an active evaluation's answers and the student ID form are never replaced.
const list = document.getElementById('evaluation-assignments');
if (list) {
    let loading = false;
    let leaving = false;
    document.addEventListener('submit', () => { leaving = true; });
    async function update() {
        if (loading || leaving || document.hidden || !navigator.onLine) return;
        loading = true;
        try {
            const response = await fetch(location.href, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' } });
            if (!response.ok) return;
            const next = new DOMParser().parseFromString(await response.text(), 'text/html').getElementById('evaluation-assignments');
            if (!next) { location.reload(); return; }
            if (list.innerHTML !== next.innerHTML) list.innerHTML = next.innerHTML;
        } catch { /* A temporary connection failure is retried automatically. */ }
        finally { loading = false; }
    }
    setInterval(update, 10000);
    document.addEventListener('visibilitychange', update);
    window.addEventListener('online', update);
}
