import { driver } from 'driver.js';
import 'driver.js/dist/driver.css';
import '../css/guided-tours.css';
import { freeGuideHeight, placeGuide } from './guided-tour-layout';

let active;
let frame;
const tours = new Map();
const visible = element => element instanceof HTMLElement && element.getClientRects().length > 0
    && getComputedStyle(element).visibility !== 'hidden';
const visibleModal = () => [...document.querySelectorAll('.fi-modal-window')].filter(visible).at(-1);
const firstVisible = (root, selector) => [...root.querySelectorAll(selector)].find(visible);

function targetFor(selector, context) {
    const hosts = [...document.querySelectorAll(`[data-guided-help="${context}"]`)];
    const host = hosts.find(visible) || hosts[0];
    // A draft save can briefly hide a dialog during its morph. Keep that tour
    // scoped to its own host rather than jumping to a guide behind the dialog.
    const modal = host?.closest('.fi-modal-window') || visibleModal();
    const root = modal || document;
    // Page guides never highlight a duplicate control hidden behind an open dialog.
    let target = selector ? firstVisible(root, selector === '.fi-header-actions'
        ? '.fi-header-actions, .fi-header .fi-ac' : selector) : null;
    if (!target) target = firstVisible(root, `[data-guided-help="${context}"] button, [data-guided-help] button`);
    if (!target) target = firstVisible(root, '.fi-modal-heading, .fi-header-heading');
    if (!target) return null;

    // A whole form/table can exceed the viewport, leaving no room beside the highlight.
    // Point at a representative control/header instead of covering that entire region.
    if (target.matches('.fi-ta')) target = firstVisible(target, '.fi-ta-header-toolbar, thead, .fi-ta-search-field') || target;
    if (target.matches('form')) target = firstVisible(target, '.fi-fo-field-wrp') || target;
    const bounds = target.getBoundingClientRect();
    if (bounds.height > innerHeight * .45 || (bounds.height > 64 && freeGuideHeight(bounds, { height: innerHeight }) < 180)) {
        target = firstVisible(target, '.filepond--drop-label') || firstVisible(target, '.fi-input-wrp')
            || firstVisible(target, '[role="combobox"], [role="switch"]') || firstVisible(target, 'label, th, h2, h3') || target;
    }
    return target;
}

function position() {
    frame = null;
    if (!active?.isActive()) return;
    const target = active.getActiveElement();
    const popup = document.querySelector('.campus-guide-popover');
    if (!popup) return;
    if (!target?.isConnected || !target.classList.contains('driver-active-element')) {
        // Livewire may replace a field or reset its classes while saving a draft.
        // Resolve the current step again instead of leaving a hole over stale DOM.
        active.moveTo(active.getActiveIndex());
        return;
    }
    let bounds = target.getBoundingClientRect();
    const viewport = { width: innerWidth, height: innerHeight };
    const style = getComputedStyle(popup);
    const footer = popup.querySelector('.driver-popover-footer');
    const chrome = popup.querySelector('.driver-popover-title').offsetHeight + footer.offsetHeight
        + parseFloat(getComputedStyle(footer).marginTop) + parseFloat(style.paddingTop) + parseFloat(style.paddingBottom)
        + parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth) + 6;
    const roomAtSide = () => Math.max(bounds.left, innerWidth - bounds.right) >= popup.offsetWidth + 26;
    // A short landscape viewport may need the field near an edge, rather than
    // centered, to keep both the description and the navigation buttons visible.
    if (!roomAtSide() && freeGuideHeight(bounds, viewport) < chrome + 42) {
        for (const block of ['end', 'start']) {
            const previous = [target.style.scrollMarginTop, target.style.scrollMarginBottom];
            target.style.scrollMarginTop = '80px'; target.style.scrollMarginBottom = '16px';
            target.scrollIntoView({ block, inline: 'nearest', behavior: 'instant' });
            [target.style.scrollMarginTop, target.style.scrollMarginBottom] = previous;
            bounds = target.getBoundingClientRect();
            if (freeGuideHeight(bounds, viewport) >= chrome + 42) break;
        }
    }
    const available = roomAtSide() ? innerHeight - 24 : freeGuideHeight(bounds, viewport);
    popup.style.maxHeight = `${Math.max(chrome, Math.min(innerHeight - 24, available))}px`;
    let placement = placeGuide(bounds, popup.getBoundingClientRect(), viewport);
    if (!placement) {
        target.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'instant' });
        const next = target.getBoundingClientRect();
        popup.style.maxHeight = `${Math.max(112, Math.min(innerHeight - 24, freeGuideHeight(next, viewport)))}px`;
        active.refresh();
        placement = placeGuide(next, popup.getBoundingClientRect(), viewport);
    }
    if (!placement) return;
    // Keep Driver's own scroll/resize positioning on the same side as this fit.
    // Competing sides can otherwise move a button between pointer-down and click.
    active.getActiveStep().popover.side = placement.side;
    active.refresh();
    Object.assign(popup.style, { left: `${placement.left}px`, top: `${placement.top}px`, right: 'auto', bottom: 'auto' });
    popup.dataset.placement = placement.side;
    popup.querySelector('.driver-popover-arrow').style.display = 'none';
    // The small accent identifies the side without an arrow drifting away from its field.
    popup.setAttribute('aria-label', 'Evaluation system guide');
}

function schedulePosition() {
    if (frame) cancelAnimationFrame(frame);
    frame = requestAnimationFrame(position);
}

function openTour(id) {
    const tour = tours.get(id.startsWith('tour_') ? id : `tour_${id}`);
    if (!tour) return;
    active?.destroy();
    const context = tour.id.replace(/^tour_campus-/, '');
    const steps = JSON.parse(tour.steps).map(step => ({
        element: () => targetFor(step.element, context),
        popover: { ...step.popover, side: 'bottom', align: 'start' },
        skipMissingElement: true, waitForElement: 800,
    }));
    const sourceButton = document.activeElement;
    active = driver({
        steps, animate: false, smoothScroll: false, allowClose: true,
        overlayColor: '#21113d', overlayOpacity: .48, stagePadding: 5, stageRadius: 7,
        overlayClickBehavior: 'none',
        popoverOffset: 14, popoverClass: 'campus-guide-popover', disableActiveInteraction: true,
        nextBtnText: tour.nextButtonLabel, prevBtnText: tour.previousButtonLabel,
        doneBtnText: tour.doneButtonLabel, closeBtnLabel: 'Close guide',
        onHighlightStarted: element => {
            element?.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'instant' });
        },
        onHighlighted: schedulePosition,
        onPopoverRender: popover => {
            popover.wrapper.setAttribute('role', 'dialog');
            schedulePosition();
        },
        onDestroyed: () => {
            if (frame) cancelAnimationFrame(frame);
            sourceButton?.isConnected && sourceButton.focus({ preventScroll: true });
        },
    });
    active.drive();
}

function boot() {
    if (window.campusGuidesReady) return;
    window.campusGuidesReady = true;
    Livewire.on('filament-tour::loaded-elements', ({ tours: loaded }) => {
        for (const tour of loaded) tours.set(tour.id, tour);
    });
    Livewire.on('filament-tour::open-tour', params => openTour(Array.isArray(params) ? params[0] : params.id || params));
    Livewire.hook('morphed', ({ el }) => {
        if (!active?.isActive()) return;
        const target = active.getActiveElement();
        if (!target?.isConnected || el?.contains(target)) {
            active.moveTo(active.getActiveIndex());
            schedulePosition();
        }
    });
    for (const type of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click']) {
        window.addEventListener(type, event => {
            if (active?.isActive() && !event.target.closest?.('.campus-guide-popover')) {
                // An explanatory tour must not click through to a modal's Cancel,
                // submit, backdrop or wizard buttons as the highlight moves.
                event.preventDefault(); event.stopImmediatePropagation();
            }
        }, true);
    }
    // Keep Filament's dialog focus trap from pulling keyboard users behind the popover.
    document.addEventListener('focusin', event => {
        if (event.target.closest?.('.campus-guide-popover')) event.stopImmediatePropagation();
    }, true);
    window.addEventListener('keydown', event => {
        if (!active?.isActive()) return;
        if (event.key === 'Escape') {
            event.preventDefault(); event.stopImmediatePropagation(); active.destroy();
        } else if (event.key === 'Tab') {
            const buttons = [...document.querySelectorAll('.campus-guide-popover button')].filter(visible);
            if (!buttons.length) return;
            event.preventDefault(); event.stopImmediatePropagation();
            const current = buttons.indexOf(document.activeElement);
            buttons[(current + (event.shiftKey ? -1 : 1) + buttons.length) % buttons.length].focus();
        }
    }, true);
    document.addEventListener('livewire:navigating', () => active?.destroy());
    document.addEventListener('scroll', event => {
        if (!event.target.closest?.('.campus-guide-popover')) schedulePosition();
    }, true);
    window.addEventListener('resize', () => {
        if (active?.isActive()) active.moveTo(active.getActiveIndex());
        schedulePosition();
    });
}

document.addEventListener('livewire:initialized', boot, { once: true });
if (window.Livewire) boot();
