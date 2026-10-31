<script>
    // The panel once defaulted to "system". Move browsers still on that old default to light once,
    // then respect every later choice (Light, Dark or System) on every page and reload.
    try {
        if (localStorage.getItem('theme-light-first') !== '1') {
            if (localStorage.getItem('theme') === 'system') {
                localStorage.setItem('theme', 'light');
                document.documentElement.classList.remove('dark');
            }
            localStorage.setItem('theme-light-first', '1');
        }
    } catch (_) {}
</script>
