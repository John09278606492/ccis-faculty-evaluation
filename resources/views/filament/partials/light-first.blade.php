<script>
    try {
        if (localStorage.getItem('theme') === 'system') {
            localStorage.setItem('theme', 'light');
            document.documentElement.classList.remove('dark');
        }
    } catch (_) {}
</script>
