/**
 * Exam supervision on the take-assessment page (see App\Services\ExamSupervision),
 * as an async Alpine component loaded with Filament's x-load. It reports:
 *
 *  - when the student leaves the exam page (another tab, window or app) and comes
 *    back, ignoring absences shorter than minAwaySeconds;
 *  - text pasted or dropped into an answer that did not come from the exam page:
 *    not copied inside it, not already in the student's files and not shown on it.
 *
 * Nothing else is read. Browsers do not reveal which other tab, site or app the
 * student switched to, and the clipboard is only seen when the student pastes.
 * Built by `npm run build:code-editor`, like the code editor.
 */
const MAX_REMEMBERED = 30
const MAX_REMEMBERED_LENGTH = 20_000

const normalize = (text) => String(text ?? '').replace(/\s+/g, ' ').trim()

const lineCount = (text) => text.replace(/\r\n/g, '\n').trimEnd().split('\n').length

export default function examSupervisionComponent({
    attemptId,
    minAwaySeconds = 2,
    minPasteChars = 100,
    minPasteLines = 3,
    maxPasteStored = 10_000,
}) {
    const storageKey = `exam-supervision:${attemptId}:copied`
    const listeners = []
    let copied = []
    let awaySince = null
    let reportedLeft = false
    let leftTimer = null
    let poll = null
    let via = null
    let viaUntil = 0
    let pickingFile = false
    let inDialog = false
    let dialogs = null

    const on = (target, type, handler, options) => {
        target.addEventListener(type, handler, options)
        listeners.push(() => target.removeEventListener(type, handler, options))
    }

    // Copies made inside the exam survive a page reload for this attempt.
    const load = () => {
        try {
            const saved = JSON.parse(sessionStorage.getItem(storageKey) ?? '[]')

            return Array.isArray(saved) ? saved.filter((item) => typeof item === 'string') : []
        } catch {
            return []
        }
    }

    const remember = (text) => {
        const value = normalize(text).slice(0, MAX_REMEMBERED_LENGTH)
        if (! value) {
            return
        }
        copied = [value, ...copied.filter((item) => item !== value)].slice(0, MAX_REMEMBERED)
        try {
            sessionStorage.setItem(storageKey, JSON.stringify(copied))
        } catch {}
    }

    const transferText = (transfer) => {
        try {
            return transfer?.getData('text/plain') ?? ''
        } catch {
            return ''
        }
    }

    return {
        init() {
            copied = load()

            on(window, 'blur', () => setTimeout(() => this.check()))
            on(window, 'focus', () => {
                pickingFile = false
                this.check()
            })
            on(document, 'visibilitychange', () => this.check())
            // Leaving from inside the Run preview (an iframe) fires no event on this page.
            poll = setInterval(() => this.check(), 1000)

            // The file chooser of a file-upload question takes focus without leaving.
            on(document, 'click', (event) => {
                if (event.target instanceof HTMLInputElement && event.target.type === 'file') {
                    pickingFile = true
                }
                const link = event.target instanceof Element ? event.target.closest('[data-supervision-via]') : null
                if (link) {
                    via = link.dataset.supervisionVia
                    viaUntil = Date.now() + 5000
                }
            }, true)
            on(document, 'change', (event) => {
                if (event.target instanceof HTMLInputElement && event.target.type === 'file') {
                    pickingFile = false
                }
            }, true)

            // So do alert/confirm dialogs, such as "Submit this attempt?".
            dialogs = Object.fromEntries(['alert', 'confirm', 'prompt'].map((name) => [name, window[name]]))
            for (const [name, original] of Object.entries(dialogs)) {
                window[name] = function (...args) {
                    inDialog = true
                    try {
                        return original.apply(window, args)
                    } finally {
                        inDialog = false
                    }
                }
            }

            on(document, 'copy', (event) => remember(this.copiedText(event)))
            on(document, 'cut', (event) => remember(this.copiedText(event)))
            on(document, 'dragstart', (event) => remember(transferText(event.dataTransfer) || String(document.getSelection() ?? '')))
            on(document, 'paste', (event) => this.received(event.target, transferText(event.clipboardData)), true)
            on(document, 'drop', (event) => this.received(event.target, transferText(event.dataTransfer)), true)

            this.check()
        },

        destroy() {
            clearTimeout(leftTimer)
            clearInterval(poll)
            listeners.forEach((remove) => remove())
            for (const [name, original] of Object.entries(dialogs ?? {})) {
                window[name] = original
            }
        },

        // ---- Leaving the page --------------------------------------------------------

        isPresent() {
            if (document.visibilityState !== 'visible') {
                return false
            }

            // Focus inside the Run preview (an iframe on this page) is still on the exam page.
            return document.hasFocus() || document.activeElement?.tagName === 'IFRAME'
        },

        check() {
            if (pickingFile || inDialog) {
                return
            }
            const present = this.isPresent()
            if (! present && awaySince === null) {
                awaySince = Date.now()
                const reason = Date.now() < viaUntil ? via : null
                leftTimer = setTimeout(() => {
                    reportedLeft = true
                    this.send('left', { secondsAgo: Math.round((Date.now() - awaySince) / 1000), via: reason })
                }, minAwaySeconds * 1000)
            } else if (present && awaySince !== null) {
                clearTimeout(leftTimer)
                const seconds = Math.round((Date.now() - awaySince) / 1000)
                if (reportedLeft || seconds >= minAwaySeconds) {
                    this.send('returned', { seconds })
                }
                awaySince = null
                reportedLeft = false
            }
        },

        // ---- Pasting -------------------------------------------------------------------

        copiedText(event) {
            // The code editor puts its own copy on the clipboard before this runs.
            const text = transferText(event.clipboardData)
            if (text) {
                return text
            }
            const target = event.target
            if ((target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) && target.selectionStart !== null) {
                return target.value.slice(target.selectionStart, target.selectionEnd)
            }

            return String(document.getSelection() ?? '')
        },

        received(target, text) {
            const question = target instanceof Element ? target.closest('[data-question-id]') : null
            if (! question || ! text) {
                return
            }
            const lines = lineCount(text)
            if (text.length < minPasteChars && lines < minPasteLines) {
                return
            }
            const editorElement = target.closest('.cq-editor')
            const editor = editorElement && window.Alpine ? window.Alpine.$data(editorElement) : null
            if (this.isFromExam(text, editor)) {
                return
            }
            this.send('paste', {
                questionId: Number(question.dataset.questionId),
                file: editor?.activeName ?? null,
                text: text.slice(0, maxPasteStored),
                chars: text.length,
                lines,
            })
        },

        isFromExam(text, editor) {
            const needle = normalize(text)
            if (! needle || copied.some((item) => item.includes(needle))) {
                return true
            }
            if (editor?.containsText?.(needle)) {
                return true
            }

            // Text shown on the exam page, such as the question itself.
            return normalize(document.querySelector('[data-exam-page]')?.innerText).includes(needle)
        },

        send(type, data) {
            this.$wire.superviseEvent(type, data).catch?.(() => {})
        },
    }
}
