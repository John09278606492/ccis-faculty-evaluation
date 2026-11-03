{{--
    Liquid glass behind button rows, like a phone's dock and the printout's Print bar: the buttons stay as they
    are; the row floats on clear glass with a bright rim, an inner glow and a slight lens bend of what scrolls
    underneath. The bend (an SVG filter, see liquid-glass-filter) works in Chrome and Edge; other browsers keep the
    clear glass and rim. Plain CSS, so no asset build is needed.
--}}
<style>
    /* Dialogs: the window scrolls as one, with its button row floating over the content. */
    .fi-modal-window:not(.fi-modal-slide-over-window) { max-height: calc(100dvh - 2rem); overflow-y: auto; overscroll-behavior: contain; }

    .fi-modal-window > .fi-modal-footer,
    .fi-form-actions {
        position: sticky; z-index: 20; isolation: isolate; box-sizing: border-box; padding: 10px 12px; border-radius: 26px;
        border: 1px solid rgba(255, 255, 255, .55);
        background: linear-gradient(135deg, rgba(255, 255, 255, .26) 0%, rgba(255, 255, 255, .06) 45%, rgba(255, 255, 255, .18) 100%);
        /* Safari keeps this line; Chrome and Edge use the next one, which adds the lens bend. */
        -webkit-backdrop-filter: blur(10px) saturate(190%) brightness(1.06);
        backdrop-filter: blur(10px) saturate(190%) brightness(1.06);
        backdrop-filter: url(#sinag-liquid-glass) blur(1.5px) saturate(180%) brightness(1.05);
        box-shadow:
            inset 1.5px 1.5px 0 rgba(255, 255, 255, .85),
            inset -1px -1px 0 rgba(255, 255, 255, .45),
            inset 0 0 18px rgba(255, 255, 255, .35),
            0 12px 32px rgba(15, 23, 42, .18),
            0 2px 6px rgba(15, 23, 42, .08);
    }
    .fi-modal-window > .fi-modal-footer { bottom: 10px; width: auto; margin: 4px 10px 10px; }
    .fi-form-actions { bottom: 12px; width: fit-content; max-width: 100%; }

    /* The curved highlights at the glass's edges, top-left bright and bottom-right soft. */
    .fi-modal-window > .fi-modal-footer::before,
    .fi-form-actions::before {
        content: ""; position: absolute; inset: 0; z-index: -1; border-radius: inherit; pointer-events: none;
        background:
            radial-gradient(120% 140% at 8% -20%, rgba(255, 255, 255, .75) 0%, rgba(255, 255, 255, 0) 42%),
            radial-gradient(90% 120% at 100% 130%, rgba(255, 255, 255, .45) 0%, rgba(255, 255, 255, 0) 45%);
        mix-blend-mode: soft-light;
    }

    .dark .fi-modal-window > .fi-modal-footer,
    .dark .fi-form-actions {
        border-color: rgba(255, 255, 255, .18);
        background: linear-gradient(135deg, rgba(255, 255, 255, .12) 0%, rgba(255, 255, 255, .04) 45%, rgba(255, 255, 255, .09) 100%);
        box-shadow: inset 1.5px 1.5px 0 rgba(255, 255, 255, .25), inset -1px -1px 0 rgba(255, 255, 255, .1), inset 0 0 18px rgba(255, 255, 255, .06), 0 12px 32px rgba(0, 0, 0, .5);
    }

    @supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
        .fi-modal-window > .fi-modal-footer, .fi-form-actions { background: rgba(255, 255, 255, .95); }
        .dark .fi-modal-window > .fi-modal-footer, .dark .fi-form-actions { background: rgba(17, 24, 39, .95); }
    }
    @media (prefers-reduced-transparency: reduce) {
        .fi-modal-window > .fi-modal-footer, .fi-form-actions { background: #fff; -webkit-backdrop-filter: none; backdrop-filter: none; }
        .dark .fi-modal-window > .fi-modal-footer, .dark .fi-form-actions { background: #111827; }
    }
</style>
