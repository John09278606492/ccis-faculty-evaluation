<style>@media print{[data-report-live-notice]{display:none!important}}</style>
<script>
(() => {
    if (window.parent === window) return;
    const state = () => JSON.stringify([...document.querySelectorAll('input:not([type="hidden"]),textarea,select')].map(input => [input.name || input.id, input.value, input.checked]));
    const original = state();
    let pending = false, printing = false, timer;
    const notice = document.createElement('div');
    notice.className = 'no-print';
    notice.setAttribute('data-report-live-notice', '');
    notice.style.cssText = 'display:none;position:fixed;bottom:12px;left:12px;right:12px;padding:10px;border:1px solid #c7d2fe;border-radius:8px;background:#eef2ff;color:#312e81;font:13px Arial;z-index:40';
    notice.textContent = 'New report data received. Your edits are preserved; save them to update this preview.';
    document.body.appendChild(notice);
    const flush = () => {
        if (!pending || printing || document.hidden) return;
        const dirty = original !== state();
        notice.style.display = dirty ? 'block' : 'none';
        if (!dirty) window.location.reload();
    };
    window.addEventListener('message', event => {
        if (event.origin !== location.origin || event.source !== window.parent || event.data?.type !== 'campus-report-refresh') return;
        pending = true;
        clearTimeout(timer);
        timer = setTimeout(flush, 700);
    });
    document.addEventListener('input', flush);
    document.addEventListener('change', flush);
    document.addEventListener('visibilitychange', flush);
    window.addEventListener('beforeprint', () => { printing = true; notice.style.display = 'none'; });
    window.addEventListener('afterprint', () => { printing = false; flush(); });
})();
</script>
