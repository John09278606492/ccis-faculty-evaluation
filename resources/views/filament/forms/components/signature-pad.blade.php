<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    {{-- A phone held sideways has little height: the dialog gives nearly all of it to the pad. --}}
    <style>
        /* Undo and Clear look like the panel's normal grey buttons. */
        [data-signature-pad] .pad-btn { min-height: 40px; padding: 0 14px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer;
            background: #fff; border: 1px solid rgba(3, 7, 18, .12); box-shadow: 0 1px 2px rgba(0, 0, 0, .05); }
        [data-signature-pad] .pad-btn:not(:disabled):hover { background: #f9fafb; }
        [data-signature-pad] .pad-btn:disabled { opacity: .45; cursor: default; }
        [data-signature-pad] .pad-btn.is-clear { color: #b91c1c; }
        .dark [data-signature-pad] .pad-btn { background: rgba(255, 255, 255, .05); border-color: rgba(255, 255, 255, .2); color: #e5e7eb; }
        .dark [data-signature-pad] .pad-btn.is-clear { color: #fca5a5; }
        @media (max-height: 500px) {
            .fi-modal-window:has([data-signature-pad]) { max-height: 100dvh; }
            .fi-modal-window:has([data-signature-pad]) .fi-modal-header { padding-block: 6px !important; }
            .fi-modal-window:has([data-signature-pad]) .fi-modal-description { display: none; }
            .fi-modal-window:has([data-signature-pad]) .fi-modal-content { padding-block: 6px !important; gap: 4px !important; }
            .fi-modal-window:has([data-signature-pad]) .fi-modal-footer { padding-block: 6px !important; }
            .fi-modal-window:has([data-signature-pad]) .fi-fo-field-wrp:has(.fi-fo-placeholder-hide-short) { display: none; }
            [data-signature-pad] canvas { height: calc(100dvh - 212px) !important; min-height: 110px; }
            [data-signature-pad] [data-pad-hint] { display: none; }
        }
    </style>
    {{-- Strokes stay out of Alpine's reactivity so a long signature does not slow the pen. --}}
    <div
        data-signature-pad
        wire:ignore
        {{-- Every dialog starts blank, so one person's drawing can never reach the next. --}}
        x-on:open-modal.window="reset()"
        x-data="(() => {
            let strokes = [], current = null, ctx = null, width = 0, height = 0;
            return {
                state: $wire.$entangle(@js($getStatePath())),
                hasInk: false,
                init() {
                    // A dialog built on the fly has no canvas yet when this runs.
                    this.$nextTick(() => {
                        ctx = this.$refs.canvas.getContext('2d');
                        new ResizeObserver(() => this.fit()).observe(this.$refs.canvas);
                        this.fit();
                    });
                    this.$watch('state', (value) => { if (! value && strokes.length) this.reset(); });
                },
                // Turning the phone resizes the pad: the drawing scales with it instead of being lost.
                fit() {
                    const canvas = this.$refs.canvas, ratio = Math.max(window.devicePixelRatio || 1, 2);
                    if (! canvas) return;
                    const nextWidth = canvas.clientWidth, nextHeight = canvas.clientHeight;
                    if (! nextWidth || ! nextHeight) return;
                    if (width && (width !== nextWidth || height !== nextHeight)) {
                        strokes.forEach((stroke) => stroke.forEach((point) => {
                            point[0] = Math.round(point[0] * nextWidth / width * 10) / 10;
                            point[1] = Math.round(point[1] * nextHeight / height * 10) / 10;
                        }));
                    }
                    width = nextWidth; height = nextHeight;
                    canvas.width = nextWidth * ratio;
                    canvas.height = nextHeight * ratio;
                    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                    ctx.lineWidth = 2.3; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111';
                    this.redraw();
                },
                redraw() {
                    if (! ctx) return;
                    ctx.clearRect(0, 0, width, height);
                    strokes.forEach((stroke) => this.paint(stroke));
                },
                paint(stroke) {
                    ctx.beginPath();
                    ctx.moveTo(stroke[0][0], stroke[0][1]);
                    for (let i = 1; i < stroke.length; i++) {
                        ctx.quadraticCurveTo(stroke[i - 1][0], stroke[i - 1][1], (stroke[i - 1][0] + stroke[i][0]) / 2, (stroke[i - 1][1] + stroke[i][1]) / 2);
                    }
                    ctx.lineTo(stroke[stroke.length - 1][0], stroke[stroke.length - 1][1]);
                    ctx.stroke();
                },
                point(event) {
                    const box = this.$refs.canvas.getBoundingClientRect();
                    return [Math.round((event.clientX - box.left) * 10) / 10, Math.round((event.clientY - box.top) * 10) / 10];
                },
                start(event) {
                    event.preventDefault();
                    this.$refs.canvas.setPointerCapture?.(event.pointerId);
                    current = [this.point(event)];
                    strokes.push(current);
                    this.hasInk = true;
                },
                move(event) {
                    if (! current) return;
                    event.preventDefault();
                    const events = event.getCoalescedEvents?.() ?? [];
                    // One event can carry several pen points: each one is drawn, or fast strokes show gaps.
                    (events.length ? events : [event]).forEach((item) => {
                        const c = this.point(item), b = current[current.length - 1], a = current[current.length - 2];
                        current.push(c);
                        ctx.beginPath();
                        if (a) {
                            ctx.moveTo((a[0] + b[0]) / 2, (a[1] + b[1]) / 2);
                            ctx.quadraticCurveTo(b[0], b[1], (b[0] + c[0]) / 2, (b[1] + c[1]) / 2);
                        } else {
                            ctx.moveTo(b[0], b[1]);
                            ctx.lineTo((b[0] + c[0]) / 2, (b[1] + c[1]) / 2);
                        }
                        ctx.stroke();
                    });
                },
                end() {
                    if (! current) return;
                    current = null;
                    this.redraw();
                    this.save();
                },
                save() {
                    this.hasInk = strokes.length > 0;
                    this.state = strokes.length ? JSON.stringify(strokes) : null;
                },
                undo() {
                    strokes.pop();
                    this.redraw();
                    this.save();
                },
                reset() {
                    strokes = []; current = null;
                    this.redraw();
                    this.save();
                },
            };
        })()"
    >
        <canvas
            x-ref="canvas"
            x-on:pointerdown="start($event)"
            x-on:pointermove="move($event)"
            x-on:pointerup="end()"
            x-on:pointercancel="end()"
            x-on:contextmenu.prevent
            aria-label="Signature pad: draw your signature here"
            style="display:block;width:100%;height:clamp(150px,38vh,260px);background:linear-gradient(to bottom,transparent 69%,rgba(0,0,0,.25) 69%,rgba(0,0,0,.25) 70%,transparent 70%),#fff;border:1px solid #6b7280;border-radius:8px;touch-action:none;overscroll-behavior:contain;cursor:crosshair;-webkit-user-select:none;user-select:none;-webkit-touch-callout:none;"
        ></canvas>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:4px 12px;margin-top:6px;">
            <span data-pad-hint style="flex:1 1 180px;font-size:12px;line-height:1.35;color:#6b7280;">Sign above the line with your finger, stylus or mouse.</span>
            <span style="display:flex;gap:8px;margin-left:auto;">
                <button type="button" class="pad-btn" x-on:click="undo()" x-bind:disabled="! hasInk">Undo</button>
                <button type="button" class="pad-btn is-clear" x-on:click="reset()" x-bind:disabled="! hasInk">Clear</button>
            </span>
        </div>
    </div>
</x-dynamic-component>
