// Geometry is kept independent of the DOM so small screens and edge cases can be tested.
export function placeGuide(target, popup, viewport, gap = 14, margin = 12) {
    const clamp = (value, min, max) => Math.max(min, Math.min(value, Math.max(min, max)));
    const x = clamp(target.left, margin, viewport.width - popup.width - margin);
    const y = clamp(target.top, margin, viewport.height - popup.height - margin);
    const candidates = [
        { side: 'bottom', left: x, top: target.bottom + gap },
        { side: 'top', left: x, top: target.top - popup.height - gap },
        { side: 'right', left: target.right + gap, top: y },
        { side: 'left', left: target.left - popup.width - gap, top: y },
    ];
    return candidates.find(p => p.left >= margin && p.top >= margin
        && p.left + popup.width <= viewport.width - margin
        && p.top + popup.height <= viewport.height - margin) ?? null;
}

export function freeGuideHeight(target, viewport, gap = 14, margin = 12) {
    return Math.floor(Math.max(target.top - margin - gap, viewport.height - target.bottom - margin - gap));
}
