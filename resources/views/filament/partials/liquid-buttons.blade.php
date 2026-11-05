{{--
    Buttons keep Filament's default look. The only change: a dialog never grows past the screen, so on a phone
    its buttons stay reachable while the content scrolls (a sticky footer keeps Filament's own styling).
--}}
<style>
    .fi-modal-window:not(.fi-modal-slide-over-window) { max-height: calc(100dvh - 2rem); overflow-y: auto; overscroll-behavior: contain; }
</style>
{{--
    A page left open after its session ended (phones, in-app browsers) used to show a full-screen
    "restricted" error on the next background update. Reload instead: a signed-out visitor lands on sign-in.
--}}
<script>
    document.addEventListener('livewire:init', () => {
        Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                if (! [401, 403, 419].includes(status)) return;
                let last = 0;
                try { last = Number(sessionStorage.getItem('sinag-expired-reload') || 0); } catch (e) {}
                if (Date.now() - last < 20000) return;
                try { sessionStorage.setItem('sinag-expired-reload', String(Date.now())); } catch (e) {}
                preventDefault();
                window.location.reload();
            });
        });
    });
</script>
