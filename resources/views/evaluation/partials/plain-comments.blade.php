<script>
(() => {
    const emoji = /[0-9#*]\uFE0F?\u20E3|[\p{Extended_Pictographic}\u{1F1E6}-\u{1F1FF}\u{1F3FB}-\u{1F3FF}\uFE0F\u200D\u{E0020}-\u{E007F}]/gu;
    const clean = input => {
        const original = input.value;
        const start = input.selectionStart;
        const end = input.selectionEnd;
        const value = original.replace(emoji, '');
        if (value === original) return;
        const nextStart = original.slice(0, start).replace(emoji, '').length;
        const nextEnd = original.slice(0, end).replace(emoji, '').length;
        input.value = value;
        input.setSelectionRange(nextStart, nextEnd);
        // Ensure draft listeners retain the cleaned value as well.
        input.dispatchEvent(new Event('input', { bubbles: true }));
    };
    document.querySelectorAll('textarea[name="overall_comment"]').forEach(input => {
        input.addEventListener('input', event => { if (!event.isComposing) clean(input); });
        input.addEventListener('compositionend', () => clean(input));
        input.form?.addEventListener('submit', () => clean(input));
    });
})();
</script>
