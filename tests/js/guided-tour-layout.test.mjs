import test from 'node:test';
import assert from 'node:assert/strict';
import { freeGuideHeight, placeGuide } from '../../resources/js/guided-tour-layout.js';

const overlap = (target, popup, placement) => placement.left < target.right && placement.left + popup.width > target.left
    && placement.top < target.bottom && placement.top + popup.height > target.top;

for (const [name, target, popup, viewport] of [
    ['top field', { left: 20, right: 370, top: 80, bottom: 130 }, { width: 340, height: 210 }, { width: 390, height: 844 }],
    ['bottom field', { left: 20, right: 370, top: 650, bottom: 730 }, { width: 340, height: 210 }, { width: 390, height: 844 }],
    ['small phone', { left: 24, right: 296, top: 320, bottom: 400 }, { width: 296, height: 220 }, { width: 320, height: 740 }],
    ['landscape phone', { left: 120, right: 724, top: 190, bottom: 230 }, { width: 340, height: 150 }, { width: 844, height: 390 }],
    ['sidebar', { left: 20, right: 100, top: 350, bottom: 390 }, { width: 340, height: 210 }, { width: 1440, height: 900 }],
    ['right edge', { left: 1120, right: 1400, top: 380, bottom: 460 }, { width: 340, height: 210 }, { width: 1440, height: 900 }],
]) {
    test(`places guide outside its highlight and inside the viewport: ${name}`, () => {
        const placement = placeGuide(target, popup, viewport);
        assert.ok(placement);
        assert.equal(overlap(target, popup, placement), false);
        assert.ok(placement.left >= 12 && placement.top >= 12);
        assert.ok(placement.left + popup.width <= viewport.width - 12);
        assert.ok(placement.top + popup.height <= viewport.height - 12);
    });
}

test('reports insufficient space rather than placing a guide over its highlight', () => {
    const target = { left: 12, right: 308, top: 12, bottom: 300 };
    assert.equal(placeGuide(target, { width: 296, height: 180 }, { width: 320, height: 320 }), null);
    assert.ok(freeGuideHeight(target, { height: 320 }) < 180);
});
