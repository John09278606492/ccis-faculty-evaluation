/**
 * CodeMirror 6 editor with file tabs and an in-browser Run panel, as an async
 * Alpine component (loaded with Filament's x-load). Everything is bundled into
 * local files by `npm run build:code-editor`, so it works on a classroom LAN
 * with no internet access.
 *
 * State is a JSON string {"files": [{"name", "content"}]}, see App\Support\CodeProject.
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
import {
    buildDocument,
    pagesFor,
    resolveProjectHref,
    runnerFor,
    runPhp,
    runSql,
    shellDocument,
} from './code-runner.js'

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

/** Same lists as App\Support\CodeProject. */
const extensions = {
    html: 'html', htm: 'html', css: 'css', js: 'javascript', json: 'javascript', php: 'php', sql: 'sql',
    py: 'python', java: 'java', c: 'c', h: 'c', cpp: 'cpp', cc: 'cpp', hpp: 'cpp', cs: 'csharp',
    txt: 'plaintext', md: 'plaintext',
}
const MAX_FILES = 10
const FILE_NAME_PATTERN = /^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*\.[A-Za-z0-9]+$/

const extensionOf = (name) => (name.split('.').pop() || '').toLowerCase()
const languageOf = (name) => extensions[extensionOf(name)] ?? 'plaintext'
const isDark = () => document.documentElement.classList.contains('dark')

function parseFiles(value, fallback) {
    if (typeof value !== 'string' || value === '') {
        return fallback.map((file) => ({ ...file }))
    }
    try {
        const decoded = JSON.parse(value)
        if (Array.isArray(decoded?.files) && decoded.files.every((file) => typeof file?.name === 'string')) {
            return decoded.files.map((file) => ({ name: file.name, content: String(file.content ?? '') }))
        }
    } catch {}

    // Code saved before file tabs existed: one plain string.
    const files = fallback.length ? fallback.map((file) => ({ ...file })) : [{ name: 'main.txt', content: '' }]
    files[0].content = value

    return files
}

export default function codeEditorComponent({
    state,
    initialValue = null,
    readOnly = false,
    canManageFiles = false,
    canRun = false,
    runnerBaseUrl = '/code-runner',
    commitDebounce = null,
    placeholder = '',
    minHeight = '12rem',
    maxHeight = '32rem',
}) {
    const starterFiles = parseFiles(initialValue, [{ name: 'main.txt', content: '' }])
    let view = null
    let theme = null
    let editorStates = new Map()
    let themeObserver = null
    let commitTimer = null
    let messageListener = null

    return {
        state,

        files: [],

        active: 0,

        newFileName: null,

        renaming: null,

        fileError: '',

        run: {
            visible: false,
            running: false,
            status: '',
            page: null,
            console: [],
            tables: [],
            hasPreview: false,
            nonce: null,
        },

        init() {
            this.files = parseFiles(this.state, starterFiles)
            theme = new Compartment()
            view = new EditorView({ parent: this.$refs.editor, state: this.editorStateFor(this.files[0]) })

            // Follow Filament's light/dark toggle, which flips the `dark` class on <html>.
            themeObserver = new MutationObserver(() => {
                view.dispatch({ effects: theme.reconfigure(isDark() ? oneDark : []) })
            })
            themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })

            this.$watch('state', (value) => {
                if (typeof value !== 'string' || value === this.serialize()) {
                    return
                }
                this.loadFiles(parseFiles(value, starterFiles))
            })

            messageListener = (event) => this.onPreviewMessage(event)
            window.addEventListener('message', messageListener)
        },

        destroy() {
            clearTimeout(commitTimer)
            themeObserver?.disconnect()
            window.removeEventListener('message', messageListener)
            view?.destroy()
        },

        // ---- Files -------------------------------------------------------------

        get showTabs() {
            return canManageFiles || this.files.length > 1
        },

        get canManageFiles() {
            return canManageFiles && ! readOnly
        },

        get runner() {
            return runnerFor(this.files)
        },

        get canRun() {
            return canRun && this.runner !== null
        },

        get pages() {
            return pagesFor(this.files, this.runner)
        },

        editorStateFor(file) {
            if (! editorStates.has(file.name)) {
                editorStates.set(file.name, EditorState.create({
                    doc: file.content,
                    extensions: this.extensionsFor(file.name),
                }))
            }

            return editorStates.get(file.name)
        },

        extensionsFor(name) {
            return [
                lineNumbers(),
                highlightActiveLineGutter(),
                highlightSpecialChars(),
                history(),
                foldGutter(),
                drawSelection(),
                indentOnInput(),
                indentUnit.of(extensionOf(name) === 'html' || extensionOf(name) === 'css' ? '  ' : '    '),
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
                languages[languageOf(name)](),
                placeholder ? placeholderExtension(placeholder) : [],
                EditorState.readOnly.of(readOnly),
                EditorView.editable.of(! readOnly),
                EditorView.contentAttributes.of({
                    spellcheck: 'false',
                    autocorrect: 'off',
                    autocapitalize: 'off',
                    'aria-label': `Code editor: ${name}`,
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
                    const file = this.files.find((candidate) => candidate.name === name)
                    if (file) {
                        file.content = update.state.doc.toString()
                    }
                    this.changed()
                }),
            ]
        },

        openFile(index) {
            if (index === this.active || ! this.files[index]) {
                return
            }
            editorStates.set(this.files[this.active].name, view.state)
            this.active = index
            view.setState(this.editorStateFor(this.files[index]))
            view.dispatch({ effects: theme.reconfigure(isDark() ? oneDark : []) })
        },

        loadFiles(files) {
            editorStates = new Map()
            this.files = files
            this.active = 0
            view.setState(this.editorStateFor(this.files[0]))
        },

        fileNameIssue(name, ignoreIndex = null) {
            if (! FILE_NAME_PATTERN.test(name) || name.length > 64 || ! (extensionOf(name) in extensions)) {
                return 'Use letters, numbers, - or _ and an extension such as .html, .css, .js, .php or .sql.'
            }
            if (this.files.some((file, index) => index !== ignoreIndex && file.name.toLowerCase() === name.toLowerCase())) {
                return `There is already a file named ${name}.`
            }

            return ''
        },

        addFile() {
            const name = (this.newFileName ?? '').trim()
            if (this.files.length >= MAX_FILES) {
                this.fileError = `A question can have at most ${MAX_FILES} files.`

                return
            }
            if ((this.fileError = this.fileNameIssue(name))) {
                return
            }
            this.files.push({ name, content: '' })
            this.newFileName = null
            this.openFile(this.files.length - 1)
            this.changed()
        },

        renameFile(index, rawName) {
            const name = rawName.trim()
            const file = this.files[index]
            this.renaming = null
            if (name === file.name) {
                return
            }
            if ((this.fileError = this.fileNameIssue(name, index))) {
                return
            }
            if (index === this.active) {
                editorStates.set(file.name, view.state)
            }
            // A new extension needs a new language, so the editor state is rebuilt.
            const content = file.content
            editorStates.delete(file.name)
            file.name = name
            if (index === this.active) {
                view.setState(this.editorStateFor({ name, content }))
            }
            this.changed()
        },

        removeFile(index) {
            if (this.files.length <= 1 || ! confirm(`Delete ${this.files[index].name}?`)) {
                return
            }
            editorStates.delete(this.files[index].name)
            this.files.splice(index, 1)
            this.active = Math.min(this.active, this.files.length - 1)
            view.setState(this.editorStateFor(this.files[this.active]))
            this.changed()
        },

        serialize() {
            return JSON.stringify({ files: this.files.map(({ name, content }) => ({ name, content })) })
        },

        changed() {
            this.fileError = ''
            this.state = this.serialize()
            this.scheduleCommit()
        },

        // Deferred entangled state only reaches the server with the next request;
        // commitDebounce sends it on its own once the student pauses typing.
        scheduleCommit() {
            if (commitDebounce === null) {
                return
            }
            clearTimeout(commitTimer)
            commitTimer = setTimeout(() => this.$wire.$commit(), commitDebounce)
        },

        // ---- Running ------------------------------------------------------------

        async runCode(page = null, request = null) {
            if (! this.canRun || this.run.running) {
                return
            }
            if (commitDebounce !== null) {
                // Save first: a student's own infinite loop must not cost them their latest edits.
                clearTimeout(commitTimer)
                this.$wire.$commit()
            }
            const files = this.files.map(({ name, content }) => ({ name, content }))
            Object.assign(this.run, { visible: true, running: true, console: [], tables: [] })
            this.run.page = page ?? (this.pages.includes(this.run.page) ? this.run.page : this.pages[0] ?? null)

            try {
                if (this.runner === 'sql') {
                    this.run.status = 'Running SQL…'
                    this.run.hasPreview = false
                    this.run.tables = await runSql(runnerBaseUrl, files)
                    this.run.status = this.run.tables.length ? 'Done.' : 'Done. No statement returned rows.'
                } else if (this.runner === 'php') {
                    await this.runPhpPage(files, request ?? { method: 'GET', url: `/${this.run.page ?? 'index.php'}` })
                } else {
                    const source = this.run.page ? files.find((file) => file.name === this.run.page).content : shellDocument(files)
                    this.showPreview(source, files, this.run.page)
                    this.run.status = this.run.page ? `Showing ${this.run.page}` : 'Running scripts and styles on an empty page.'
                }
            } catch (error) {
                this.run.status = ''
                this.run.console.push({ level: 'error', text: error.message })
            } finally {
                this.run.running = false
            }
        },

        async runPhpPage(files, request) {
            this.run.status = 'Running PHP… (the first run loads PHP and can take a moment)'
            for (let redirects = 0; redirects < 5; redirects++) {
                const response = await runPhp(runnerBaseUrl, files, request)
                if (response.location && response.status >= 300 && response.status < 400) {
                    const target = resolveProjectHref(response.location)
                    if (! target) {
                        break
                    }
                    request = { method: 'GET', url: `/${target}` }
                    continue
                }
                this.showPreview(response.body, files)
                this.run.status = `${request.method} ${request.url.slice(1)} → ${response.status}`

                return
            }
            this.run.console.push({ level: 'error', text: 'Too many redirects, or a redirect outside this project.' })
        },

        showPreview(source, files, pageName = null) {
            this.run.nonce = Math.random().toString(36).slice(2)
            this.run.hasPreview = true
            this.$refs.preview.srcdoc = buildDocument(source, files, this.run.nonce, pageName)
        },

        onPreviewMessage(event) {
            const data = event.data
            if (! this.$refs.preview || event.source !== this.$refs.preview.contentWindow || data?.nonce !== this.run.nonce) {
                return
            }
            if (data.type === 'console') {
                this.run.console.push({ level: data.level, text: String(data.text) })
            } else if (data.type === 'navigate') {
                this.followLink(data.href)
            } else if (data.type === 'submit') {
                this.submitForm(data)
            }
        },

        followLink(href) {
            const target = resolveProjectHref(href)
            const [path, query = ''] = (target ?? '').split('?')
            if (! target || ! this.files.some((file) => file.name === path)) {
                this.run.console.push({ level: 'info', text: `The preview only opens this project's files, not ${href}.` })

                return
            }
            if (this.runner === 'php') {
                this.runCode(path, { method: 'GET', url: `/${path}${query ? `?${query}` : ''}` })
            } else if (this.pages.includes(path)) {
                this.runCode(path)
            }
        },

        submitForm({ method, action, fields }) {
            if (this.runner !== 'php') {
                this.run.console.push({ level: 'info', text: 'Form submitted. Forms need a PHP file to receive them.' })

                return
            }
            const target = resolveProjectHref(action) ?? this.run.page ?? 'index.php'
            const path = target.split('?')[0]
            const query = new URLSearchParams(fields).toString()
            const request = method === 'POST'
                ? {
                    method: 'POST',
                    url: `/${target}`,
                    body: query,
                    headers: { 'content-type': 'application/x-www-form-urlencoded' },
                }
                : { method: 'GET', url: `/${path}${query ? `?${query}` : ''}` }
            this.runCode(path, request)
        },
    }
}
