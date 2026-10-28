<script data-navigate-once>
    document.addEventListener('alpine:init', () => {
        // A phone's table can be below the fold while its header upload button is
        // already usable. Initialize it through Filament's own eager-load strategy
        // before an action or background refresh morphs its modal containers.
        window.Alpine.interceptInit(element => {
            if (element.matches?.('.fi-ta[x-load="visible"]')) {
                element.setAttribute('x-load', 'eager');
            }
        });
    }, { once: true });
</script>
