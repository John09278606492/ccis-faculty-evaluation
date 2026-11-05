<x-filament-panels::page>
    {{-- A frame keeps the form's own A4 styles apart from the panel's, and lets Print print only the document. --}}
    {{-- The frame scrolls inside itself, so the document's floating Print bar stays in view. --}}
    <div wire:poll.20s="checkForNewSignatures">
        <div
            wire:ignore
            x-data="{
                reload() {
                    const win = this.$refs.frame.contentWindow, top = win.scrollY;
                    this.$refs.frame.addEventListener('load', () => win.scrollTo(0, top), { once: true });
                    win.location.reload();
                },
            }"
            x-on:acknowledgement-receipt-changed.window="reload()"
        >
            <iframe
                x-ref="frame"
                src="{{ $this->getPrintUrl() }}"
                title="Preview of the acknowledgement receipt"
                style="display:block;width:100%;height:calc(100dvh - 12rem);min-height:480px;border:1px solid #d1d5db;border-radius:12px;background:#eef1f4;"
            ></iframe>
        </div>
    </div>
</x-filament-panels::page>
