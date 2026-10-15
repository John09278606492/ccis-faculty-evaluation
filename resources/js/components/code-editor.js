/**
 * CodeMirror 6 editor as an async Alpine component (loaded with Filament's
 * x-load). Everything is bundled into one local file by `npm run build:code-editor`,
 * so the editor works on a classroom LAN with no internet access.
 *
 * Autocompletion is intentionally left out: this editor is used during quizzes.
 */
import { closeBrackets, closeBracketsKeymap } from '@codemirror/autocomplete'
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands'
import { cpp } from '@codemirror/lang-cpp'
import { css } from '@codemirror/lang-css'
import { html } from '@codemirror/lang-html'
import { java } from '@codemirror/lang-java'
import { javascript } from '@codemirror/lang-javascript'
import { php } from '@codemirror/lang-php'
import { python } from '@codemirror/lang-python'
import { sql } from '@codemirror/lang-sql'
import {
    bracketMatching,
    defaultHighlightStyle,
    foldGutter,
    foldKeymap,
    indentOnInput,
    indentUnit,
    StreamLanguage,
    syntaxHighlighting,
} from '@codemirror/language'
import { csharp } from '@codemirror/legacy-modes/mode/clike'
import { highlightSelectionMatches, searchKeymap } from '@codemirror/search'
import { Compartment, EditorState } from '@codemirror/state'
import { oneDark } from '@codemirror/theme-one-dark'
import {
    drawSelection,
    EditorView,
    highlightActiveLine,
    highlightActiveLineGutter,
    highlightSpecialChars,
    keymap,
    lineNumbers,
    placeholder as placeholderExtension,
} from '@codemirror/view'

const languages = {
    python: () => python(),
    java: () => java(),
    c: () => cpp(),
    cpp: () => cpp(),
    csharp: () => StreamLanguage.define(csharp),
    javascript: () => javascript(),
    php: () => php(),
    sql: () => sql(),
    html: () => html(),
    css: () => css(),
    plaintext: () => [],
}

const isDark = () => document.documentElement.classList.contains('dark')

export default function codeEditorComponent({
    state,
    initialValue = '',
    language = 'plaintext',
    readOnly = false,
    placeholder = '',
    minHeight = '12rem',
    maxHeight = '32rem',
    commitDebounce = null,
}) {
    return {
        state,

        view: null,

        themeObserver: null,

        commitTimer: null,

        init() {
            const theme = new Compartment()
            const doc = typeof this.state === 'string' ? this.state : (initialValue ?? '')

            this.view = new EditorView({
                parent: this.$refs.editor,
                state: EditorState.create({
                    doc,
                    extensions: [
                        lineNumbers(),
                        highlightActiveLineGutter(),
                        highlightSpecialChars(),
                        history(),
                        foldGutter(),
                        drawSelection(),
                        indentOnInput(),
                        indentUnit.of('    '),
                        EditorState.tabSize.of(4),
                        syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
                        bracketMatching(),
                        closeBrackets(),
                        highlightActiveLine(),
                        highlightSelectionMatches(),
                        keymap.of([
                            ...closeBracketsKeymap,
                            ...defaultKeymap,
                            ...searchKeymap,
                            ...historyKeymap,
                            ...foldKeymap,
                            indentWithTab,
                        ]),
                        (languages[language] ?? languages.plaintext)(),
                        placeholder ? placeholderExtension(placeholder) : [],
                        EditorState.readOnly.of(readOnly),
                        EditorView.editable.of(! readOnly),
                        EditorView.contentAttributes.of({
                            spellcheck: 'false',
                            autocorrect: 'off',
                            autocapitalize: 'off',
                            'aria-label': 'Code editor',
                        }),
                        EditorView.theme({
                            '&': { minHeight, maxHeight, fontSize: '0.875rem' },
                            '.cm-scroller': {
                                overflow: 'auto',
                                fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
                            },
                            '&.cm-focused': { outline: 'none' },
                        }),
                        theme.of(isDark() ? oneDark : []),
                        EditorView.updateListener.of((update) => {
                            if (! update.docChanged) {
                                return
                            }

                            this.state = update.state.doc.toString()
                            this.scheduleCommit()
                        }),
                    ],
                }),
            })

            // Follow Filament's light/dark toggle, which flips the `dark` class on <html>.
            this.themeObserver = new MutationObserver(() => {
                this.view.dispatch({ effects: theme.reconfigure(isDark() ? oneDark : []) })
            })
            this.themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })

            this.$watch('state', (value) => {
                if (typeof value !== 'string' || value === this.view.state.doc.toString()) {
                    return
                }

                this.view.dispatch({
                    changes: { from: 0, to: this.view.state.doc.length, insert: value },
                })
            })
        },

        // Deferred entangled state only reaches the server with the next request;
        // commitDebounce sends it on its own once the student pauses typing.
        scheduleCommit() {
            if (commitDebounce === null) {
                return
            }

            clearTimeout(this.commitTimer)
            this.commitTimer = setTimeout(() => this.$wire.$commit(), commitDebounce)
        },

        destroy() {
            clearTimeout(this.commitTimer)
            this.themeObserver?.disconnect()
            this.view?.destroy()
        },
    }
}
