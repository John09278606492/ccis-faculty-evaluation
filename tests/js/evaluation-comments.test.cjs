const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function commentBox() {
    const handlers = {};
    const input = {
        value: '', selectionStart: 0, selectionEnd: 0,
        addEventListener(name, action) { handlers[name] = action; },
        setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; },
        dispatchEvent(event) { handlers[event.type]?.(event); },
        form: { addEventListener() {} },
    };
    const source = fs.readFileSync('resources/views/evaluation/partials/plain-comments.blade.php', 'utf8').replace(/<\/?script>/g, '');
    vm.runInNewContext(source, { document: { querySelectorAll: () => [input] }, Event: class { constructor(type, options) { this.type = type; Object.assign(this, options); } } });
    return { input, enter(text, composing = false) { input.value = text; input.selectionStart = input.selectionEnd = text.length; handlers.input({ isComposing: composing }); }, finish() { handlers.compositionend(); } };
}

test('typing and pasting complex emoji leaves ordinary English and Bisaya text intact', () => {
    const box = commentBox();
    box.enter('Salamat Niño 😊 🇵🇭 👍🏽 👨‍👩‍👧‍👦 1️⃣ — 99.36%!');
    assert.equal(box.input.value, 'Salamat Niño      — 99.36%!');
    assert.equal(box.input.selectionStart, box.input.value.length);
});

test('emoji-only input becomes empty so required validation still applies', () => {
    const box = commentBox(); box.enter('😊👍🏽'); assert.equal(box.input.value, '');
});

test('composition is preserved until the input method finishes', () => {
    const box = commentBox(); box.enter('Niño 😊', true);
    assert.equal(box.input.value, 'Niño 😊');
    box.finish(); assert.equal(box.input.value, 'Niño ');
});
