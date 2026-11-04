{{--
    Buttons keep Filament's default look. The only change: a dialog never grows past the screen, so on a phone
    its buttons stay reachable while the content scrolls (a sticky footer keeps Filament's own styling).
--}}
<style>
    .fi-modal-window:not(.fi-modal-slide-over-window) { max-height: calc(100dvh - 2rem); overflow-y: auto; overscroll-behavior: contain; }
</style>
