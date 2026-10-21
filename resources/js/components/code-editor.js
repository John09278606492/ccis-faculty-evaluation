/**
 * A VS Code-style workspace — Explorer, tabs, editor, Run panel and status bar —
 * as an async Alpine component (loaded with Filament's x-load). Everything is
 * bundled into local files by `npm run build:code-editor`, so it works on a
 * classroom LAN with no internet access.
 *
 * State is a JSON string {"files": [{"name", "content"}], "folders": [...]},
 * see App\Support\CodeProject. File names are paths such as css/style.css.
 */
import { acceptCompletion, autocompletion, closeBrackets, closeBracketsKeymap, completionKeymap, completionStatus } from '@codemirror/autocomplete'
import { defaultKeymap, history, historyKeymap, indentLess, indentMore } from '@codemirror/commands'
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
    foldGutter,
    foldKeymap,
    indentOnInput,
    indentUnit,
    StreamLanguage,
} from '@codemirror/language'
import { csharp } from '@codemirror/legacy-modes/mode/clike'
import { highlightSelectionMatches, searchKeymap } from '@codemirror/search'
import { Compartment, EditorState } from '@codemirror/state'
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
    unclosedTag,
} from './code-runner.js'
import { vscodeDark, vscodeLight } from './vscode-theme.js'
import { htmlCompletion, javascriptCompletion } from './js-completions.js'
import { unclosedTags } from './html-tag-check.js'

/**
 * The language support for each file type.
 *
 * The HTML one keeps the package's own nesting, so the JavaScript and CSS
 * inside a <script> or <style> block are highlighted and completed as
 * themselves - without that, writing a tag's body in a .html file gives no
 * suggestions.
 */
const languages = {
    python: () => python(),
    java: () => java(),
    c: () => cpp(),
    cpp: () => cpp(),
    csharp: () => StreamLanguage.define(csharp),
    javascript: () => javascript(),
    php: () => php(),
    sql: () => sql(),
    html: () => html({
        matchClosingTags: true,
        autoCloseTags: true,
    }),
    css: () => css(),
    plaintext: () => [],
}

const languageNames = {
    python: 'Python', java: 'Java', c: 'C', cpp: 'C++', csharp: 'C#', javascript: 'JavaScript',
    php: 'PHP', sql: 'SQL', html: 'HTML', css: 'CSS', plaintext: 'Plain Text',
}

/** Same rules as App\Support\CodeProject. */
const extensions = {
    html: 'html', htm: 'html', css: 'css', js: 'javascript', json: 'javascript', php: 'php', sql: 'sql',
    py: 'python', java: 'java', c: 'c', h: 'c', cpp: 'cpp', cc: 'cpp', hpp: 'cpp', cs: 'csharp',
    xml: 'html', svg: 'html', txt: 'plaintext', md: 'plaintext', csv: 'plaintext',
}
const MAX_FILES = 30
const MAX_DEPTH = 4
// How long the live preview waits after the last keystroke before it updates.
const LIVE_PREVIEW_DELAY = 250
const SEGMENT_PATTERN = /^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/

/** Small coloured labels in the Explorer and tabs, like VS Code's file icons. */
const fileIcons = {
    html: ['<>', '#e44d26'], css: ['#', '#42a5f5'], javascript: ['JS', '#f5de19'], php: ['php', '#a074c4'],
    sql: ['db', '#e38c00'], python: ['py', '#3d7ab3'], java: ['J', '#e76f00'], c: ['C', '#5c8dbc'],
    cpp: ['C++', '#5c8dbc'], csharp: ['C#', '#9b4f96'], plaintext: ['≡', '#8a8a8a'],
}

const extensionOf = (name) => (name.split('/').pop().split('.').pop() || '').toLowerCase()
const languageOf = (name) => extensions[extensionOf(name)] ?? 'plaintext'
const baseName = (path) => path.split('/').pop()
const parentOf = (path) => path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : ''
const isDark = () => document.documentElement.classList.contains('dark')

function ancestors(path) {
    const parts = path.split('/')
    parts.pop()

    return parts.map((_, index) => parts.slice(0, index + 1).join('/'))
}

function parseProject(value, fallback) {
    const copy = (project) => ({ files: project.files.map((file) => ({ ...file })), folders: [...project.folders] })
    if (typeof value !== 'string' || value === '') {
        return copy(fallback)
    }
    try {
        const decoded = JSON.parse(value)
        if (Array.isArray(decoded?.files) && decoded.files.every((file) => typeof file?.name === 'string')) {
            return {
                files: decoded.files.map((file) => ({ name: file.name, content: String(file.content ?? '') })),
                folders: Array.isArray(decoded.folders) ? decoded.folders.filter((folder) => typeof folder === 'string') : [],
            }
        }
    } catch {}

    // Code saved before file tabs existed: one plain string.
    const project = copy(fallback)
    if (! project.files.length) {
        project.files.push({ name: 'main.txt', content: '' })
    }
    project.files[0].content = value

    return project
}

export default function codeEditorComponent({
    statePath = null,
    state,
    initialValue = null,
    readOnly = false,
    canManageFiles = false,
    canRun = false,
    autocomplete = false,
    runnerBaseUrl = '/code-runner',
    commitDebounce = null,
    placeholder = '',
    maxHeight = '32rem',
}) {
    const starter = parseProject(initialValue, { files: [{ name: 'main.txt', content: '' }], folders: [] })
    let view = null
    let theme = null
    let editorStates = new Map()
    let themeObserver = null
    let resizeObserver = null
    let commitTimer = null
    let retryTimer = null
    let liveTimer = null
    let messageListener = null
    // Livewire, taken from the root: $wire from a handler inside the Explorer (a name input that is
    // removed once it is confirmed) resolves to no component, so those changes were never saved.
    let wire = null
    let rejected = false

    return {
        state,

        files: [],

        folders: [],

        openTabs: [],

        activeName: null,

        selected: null,

        collapsed: {},

        creating: null,

        renaming: null,

        fileError: '',

        sidebarOpen: true,

        maximized: false,

        compact: false,

        quickOpen: { open: false, query: '', index: 0 },

        cursor: { line: 1, column: 1, selected: 0 },

        saveState: 'saved',

        // A save is in flight; another one waits for it.
        saving: false,

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

        // The preview sits next to the editor instead of below it.
        sideBySide: false,

        // The preview follows the code as it is typed.
        livePreview: false,

        init() {
            wire = this.$wire
            if (statePath && wire) {
                wire.$on('code-save-rejected', ({ path }) => {
                    if (path === statePath) {
                        rejected = true
                        this.saveState = 'rejected'
                    }
                })
            }
            const project = parseProject(this.state, starter)
            this.files = project.files
            this.folders = project.folders
            theme = new Compartment()
            const first = this.files[0]
            this.openTabs = first ? [first.name] : []
            this.activeName = first?.name ?? null
            this.selected = this.activeName
            view = new EditorView({ parent: this.$refs.editor, state: this.editorStateFor(first ?? { name: 'main.txt', content: '' }) })

            // Follow Filament's light/dark toggle, which flips the `dark` class on <html>.
            themeObserver = new MutationObserver(() => this.applyTheme())
            themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })

            // Narrow places (the builder's side panel, phones) start with the Explorer closed.
            resizeObserver = new ResizeObserver(([entry]) => {
                const compact = entry.contentRect.width < 640
                if (compact !== this.compact) {
                    this.compact = compact
                    this.sidebarOpen = ! compact && (this.canManageFiles || this.files.length > 1)
                }
            })
            resizeObserver.observe(this.$root)
            this.sidebarOpen = this.canManageFiles || this.files.length > 1

            // A web or PHP project is shown next to its preview from the start,
            // and the preview follows the code as it is typed. SQL needs the
            // server, so it keeps the output panel below the editor.
            this.sideBySide = this.runner !== 'sql' && this.canRun
            this.livePreview = this.sideBySide
            if (this.sideBySide) {
                // Show the page as it is, before the student changes anything.
                this.$nextTick(() => this.refreshLivePreview())
            }

            this.$watch('state', (value) => {
                if (typeof value !== 'string' || value === this.serialize()) {
                    return
                }
                this.loadProject(parseProject(value, starter))
            })

            messageListener = (event) => this.onPreviewMessage(event)
            window.addEventListener('message', messageListener)
        },

        destroy() {
            clearTimeout(commitTimer)
            clearTimeout(retryTimer)
            clearTimeout(liveTimer)
            themeObserver?.disconnect()
            resizeObserver?.disconnect()
            window.removeEventListener('message', messageListener)
            view?.destroy()
        },

        // ---- Derived state ------------------------------------------------------

        get canManageFiles() {
            return canManageFiles && ! readOnly
        },

        get readOnly() {
            return readOnly
        },

        get workspaceHeight() {
            return this.maximized ? '100%' : maxHeight
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

        get activeFile() {
            return this.files.find((file) => file.name === this.activeName) ?? null
        },

        get activeLanguage() {
            return this.activeName ? languageNames[languageOf(this.activeName)] : ''
        },

        get breadcrumbs() {
            return this.activeName ? this.activeName.split('/') : []
        },

        get allFolders() {
            const all = new Set(this.folders)
            for (const file of this.files) {
                ancestors(file.name).forEach((folder) => all.add(folder))
            }

            return [...all]
        },

        /**
         * The Explorer tree as rows with an indent depth: folders first, then
         * files, each sorted by name, skipping the contents of collapsed folders.
         */
        get explorerRows() {
            const folders = this.allFolders
            const rows = []
            const walk = (parent, depth) => {
                if (this.creating && this.creating.parent === parent) {
                    rows.push({ key: `new:${parent}`, type: 'new', depth, kind: this.creating.type })
                }
                const byName = (a, b) => a.localeCompare(b, undefined, { sensitivity: 'base' })
                folders.filter((folder) => parentOf(folder) === parent).sort(byName).forEach((folder) => {
                    rows.push({ key: `d:${folder}`, type: 'folder', path: folder, name: baseName(folder), depth, open: ! this.collapsed[folder] })
                    if (! this.collapsed[folder]) {
                        walk(folder, depth + 1)
                    }
                })
                this.files.map((file) => file.name).filter((name) => parentOf(name) === parent).sort(byName).forEach((name) => {
                    rows.push({ key: `f:${name}`, type: 'file', path: name, name: baseName(name), depth })
                })
            }
            walk('', 0)

            return rows
        },

        get quickOpenResults() {
            const query = this.quickOpen.query.toLowerCase()

            return this.files.map((file) => file.name)
                .filter((name) => name.toLowerCase().includes(query))
                .slice(0, 12)
        },

        icon(path) {
            return fileIcons[languageOf(path)] ?? fileIcons.plaintext
        },

        // ---- Editor ---------------------------------------------------------------

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
            const language = languageOf(name)
            return [
                lineNumbers(),
                highlightActiveLineGutter(),
                highlightSpecialChars(),
                history(),
                foldGutter(),
                drawSelection(),
                indentOnInput(),
                indentUnit.of(['html', 'css'].includes(language) ? '  ' : '    '),
                EditorState.tabSize.of(4),
                bracketMatching(),
                // Marks the tags that are not closed, so a forgotten ">" is seen
                // right away instead of quietly swallowing the rest of the page.
                language === 'html' ? unclosedTags() : [],
                closeBrackets(),
                // Suggestions are off unless the instructor allows them for this
                // question. The language's own completion sources are always
                // registered, so turning suggestions on shows them straight away.
                autocomplete ? autocompletion({ activateOnTyping: true }) : [],
                // The browser globals, for JavaScript and for the <script> blocks
                // of an HTML file.
                autocomplete && language === 'javascript' ? javascriptCompletion : [],
                autocomplete && language === 'html' ? [htmlCompletion, javascriptCompletion] : [],
                highlightActiveLine(),
                highlightSelectionMatches(),
                keymap.of([
                    { key: 'Mod-s', preventDefault: true, run: () => { this.saveNow(); return true } },
                    { key: 'Mod-Enter', preventDefault: true, run: () => { this.runCode(); return true } },
                    // Tab takes the highlighted suggestion when the list is open,
                    // and indents the line when it is not. Without this, Tab
                    // always indents and the suggestion can only be taken with
                    // Enter or Ctrl-Space.
                    {
                        key: 'Tab',
                        preventDefault: true,
                        run: (view) => {
                            const status = completionStatus(view.state)
                            if (status === 'active' || status === 'pending') {
                                acceptCompletion(view)
                                return true
                            }

                            return indentMore(view)
                        },
                    },
                    { key: 'Shift-Tab', preventDefault: true, run: (view) => indentLess(view) },
                    ...closeBracketsKeymap,
                    ...(autocomplete ? completionKeymap : []),
                    ...defaultKeymap,
                    ...searchKeymap,
                    ...historyKeymap,
                    ...foldKeymap,
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
                    '&': { height: '100%', fontSize: '14px' },
                    '.cm-scroller': {
                        overflow: 'auto',
                        fontFamily: "Consolas, 'Cascadia Code', 'Courier New', ui-monospace, monospace",
                        lineHeight: '19px',
                    },
                    '&.cm-focused': { outline: 'none' },
                }),
                theme.of(isDark() ? vscodeDark : vscodeLight),
                EditorView.updateListener.of((update) => {
                    if (update.selectionSet || update.docChanged) {
                        const selection = update.state.selection.main
                        const line = update.state.doc.lineAt(selection.head)
                        this.cursor = { line: line.number, column: selection.head - line.from + 1, selected: Math.abs(selection.to - selection.from) }
                    }
                    if (! update.docChanged) {
                        return
                    }
                    const file = this.files.find((candidate) => candidate.name === name)
                    if (file) {
                        file.content = update.state.doc.toString()
                    }
                    this.changed()
                    this.scheduleLivePreview()
                }),
            ]
        },

        applyTheme() {
            view?.dispatch({ effects: theme.reconfigure(isDark() ? vscodeDark : vscodeLight) })
        },

        openFile(path) {
            const file = this.files.find((candidate) => candidate.name === path)
            if (! file) {
                return
            }
            if (this.activeName && this.activeName !== path && editorStates.has(this.activeName)) {
                editorStates.set(this.activeName, view.state)
            }
            if (! this.openTabs.includes(path)) {
                this.openTabs.push(path)
            }
            this.activeName = path
            this.selected = path
            ancestors(path).forEach((folder) => { this.collapsed[folder] = false })
            view.setState(this.editorStateFor(file))
            this.applyTheme()
            this.$nextTick(() => view.focus())
        },

        closeTab(path) {
            const index = this.openTabs.indexOf(path)
            if (index === -1) {
                return
            }
            if (path === this.activeName) {
                editorStates.set(path, view.state)
            }
            this.openTabs.splice(index, 1)
            if (path === this.activeName) {
                const next = this.openTabs[Math.min(index, this.openTabs.length - 1)]
                this.activeName = null
                if (next) {
                    this.openFile(next)
                }
            }
        },

        loadProject(project) {
            editorStates = new Map()
            this.files = project.files
            this.folders = project.folders
            this.openTabs = this.openTabs.filter((name) => this.files.some((file) => file.name === name))
            const active = this.openTabs.includes(this.activeName) ? this.activeName : (this.openTabs[0] ?? this.files[0]?.name ?? null)
            this.activeName = null
            if (active) {
                this.openFile(active)
            }
        },

        // Only where answers are saved (a student's attempt), not in the instructor's own editors.
        get canReset() {
            return ! readOnly && commitDebounce !== null
        },

        resetToStarter() {
            if (! this.canReset || ! confirm('Start over with the starter files? Your changes to this question will be lost.')) {
                return
            }
            const project = parseProject(initialValue, starter)
            this.openTabs = project.files[0] ? [project.files[0].name] : []
            this.loadProject(project)
            this.selected = this.activeName
            this.changed()
        },

        // ---- Explorer -------------------------------------------------------------

        clickRow(row) {
            if (row.type === 'folder') {
                this.collapsed[row.path] = row.open
                this.selected = row.path
            } else if (row.type === 'file') {
                this.openFile(row.path)
            }
        },

        targetFolder() {
            if (! this.selected) {
                return ''
            }

            return this.allFolders.includes(this.selected) ? this.selected : parentOf(this.selected)
        },

        startCreate(type) {
            if (! this.canManageFiles) {
                return
            }
            const parent = this.targetFolder()
            this.collapsed[parent] = false
            this.sidebarOpen = true
            this.fileError = ''
            this.creating = { type, parent }
        },

        pathIssue(path, ignore = null) {
            const segments = path.split('/')
            if (path.length > 120 || segments.length > MAX_DEPTH || ! segments.every((segment) => segment.length <= 64 && SEGMENT_PATTERN.test(segment))) {
                return 'Use letters, numbers, - or _ in names, and / between folders.'
            }
            const taken = [...this.files.map((file) => file.name), ...this.allFolders]
                .filter((existing) => existing !== ignore && ! (ignore && existing.startsWith(`${ignore}/`)))
            if (taken.some((existing) => existing.toLowerCase() === path.toLowerCase())) {
                return `${path} already exists.`
            }

            return ''
        },

        confirmCreate(rawName) {
            const creating = this.creating
            const name = (rawName ?? '').trim().replace(/^\/+|\/+$/g, '')
            if (! creating || name === '') {
                this.creating = null

                return
            }
            const path = creating.parent ? `${creating.parent}/${name}` : name
            if ((this.fileError = this.pathIssue(path))) {
                return
            }
            if (creating.type === 'file') {
                if (! (extensionOf(path) in extensions) || ! baseName(path).includes('.')) {
                    this.fileError = 'Add an extension such as .html, .css, .js, .php, .py or .sql.'

                    return
                }
                if (this.files.length >= MAX_FILES) {
                    this.fileError = `A workspace can have at most ${MAX_FILES} files.`

                    return
                }
                // A file deleted earlier under the same name must not bring back its old text.
                editorStates.delete(path)
                this.files.push({ name: path, content: '' })
                this.creating = null
                this.openFile(path)
            } else {
                this.folders.push(path)
                this.creating = null
                this.selected = path
            }
            this.changed()
        },

        startRename(path) {
            if (this.canManageFiles) {
                this.fileError = ''
                this.renaming = path
            }
        },

        confirmRename(path, rawName) {
            const name = (rawName ?? '').trim()
            this.renaming = null
            if (name === '' || name === baseName(path)) {
                return
            }
            const parent = parentOf(path)
            const target = parent ? `${parent}/${name}` : name
            if ((this.fileError = this.pathIssue(target, path))) {
                return
            }
            const isFolder = this.allFolders.includes(path)
            if (! isFolder && (! (extensionOf(target) in extensions) || ! name.includes('.'))) {
                this.fileError = 'Keep an extension such as .html, .css, .js, .php, .py or .sql.'

                return
            }
            const move = (old) => old === path ? target : (old.startsWith(`${path}/`) ? target + old.slice(path.length) : old)
            if (this.activeName) {
                editorStates.set(this.activeName, view.state)
            }
            // A new extension needs a new language, so moved files get fresh editor states.
            for (const file of this.files) {
                if (move(file.name) !== file.name) {
                    editorStates.delete(file.name)
                    file.name = move(file.name)
                }
            }
            this.folders = this.folders.map(move)
            this.openTabs = this.openTabs.map(move)
            Object.keys(this.collapsed).forEach((folder) => {
                if (move(folder) !== folder) {
                    this.collapsed[move(folder)] = this.collapsed[folder]
                }
            })
            const active = this.activeName ? move(this.activeName) : null
            this.activeName = null
            this.selected = move(path)
            if (active) {
                this.openFile(active)
            }
            this.changed()
        },

        remove(path) {
            if (! this.canManageFiles) {
                return
            }
            const isFolder = this.allFolders.includes(path)
            const inside = (name) => name === path || name.startsWith(`${path}/`)
            const doomed = this.files.filter((file) => inside(file.name))
            if (doomed.length >= this.files.length) {
                this.fileError = 'A workspace needs at least one file.'

                return
            }
            if (! confirm(isFolder ? `Delete the folder ${path} and the ${doomed.length} file(s) in it?` : `Delete ${path}?`)) {
                return
            }
            doomed.forEach((file) => editorStates.delete(file.name))
            this.files = this.files.filter((file) => ! inside(file.name))
            this.folders = this.folders.filter((folder) => ! inside(folder))
            this.openTabs.filter(inside).forEach((name) => this.closeTab(name))
            doomed.forEach((file) => editorStates.delete(file.name))
            if (! this.activeName && this.files[0]) {
                this.openFile(this.files[0].name)
            }
            this.selected = this.activeName
            this.changed()
        },

        // ---- Quick open (Ctrl+P) ---------------------------------------------------

        openQuickOpen() {
            this.quickOpen = { open: true, query: '', index: 0 }
            this.$nextTick(() => this.$refs.quickOpenInput?.focus())
        },

        quickOpenKey(event) {
            const results = this.quickOpenResults
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault()
                const step = event.key === 'ArrowDown' ? 1 : -1
                this.quickOpen.index = (this.quickOpen.index + step + results.length) % Math.max(results.length, 1)
            } else if (event.key === 'Enter') {
                event.preventDefault()
                if (results[this.quickOpen.index]) {
                    this.quickOpen.open = false
                    this.openFile(results[this.quickOpen.index])
                }
            } else if (event.key === 'Escape') {
                this.quickOpen.open = false
                view.focus()
            }
        },

        /** Workspace shortcuts that also work outside the editor text. */
        shortcut(event) {
            if (! (event.ctrlKey || event.metaKey)) {
                return
            }
            const key = event.key.toLowerCase()
            if (key === 'p') {
                event.preventDefault()
                this.openQuickOpen()
            } else if (key === 'b') {
                event.preventDefault()
                this.sidebarOpen = ! this.sidebarOpen
            } else if (key === 's') {
                event.preventDefault()
                this.saveNow()
            }
        },

        // ---- Saving -----------------------------------------------------------------

        serialize() {
            const project = { files: this.files.map(({ name, content }) => ({ name, content })) }
            const empty = this.folders.filter((folder) => ! this.files.some((file) => file.name.startsWith(`${folder}/`)))
            if (empty.length) {
                project.folders = empty
            }

            return JSON.stringify(project)
        },

        changed() {
            this.fileError = this.fileError && this.creating ? this.fileError : ''
            this.state = this.serialize()
            this.scheduleCommit()
        },

        // Deferred entangled state only reaches the server with the next request;
        // commitDebounce sends it on its own once the student pauses typing.
        scheduleCommit() {
            if (commitDebounce === null) {
                return
            }
            this.saveState = 'unsaved'
            clearTimeout(commitTimer)
            commitTimer = setTimeout(() => this.saveNow(), commitDebounce)
        },

        async saveNow() {
            if (commitDebounce === null || readOnly) {
                return
            }
            clearTimeout(commitTimer)
            // A save that is already running is not started again; the state is
            // saved either way, so wait for this one to finish.
            if (this.saving) {
                return
            }
            this.saving = true
            this.saveState = 'saving'
            rejected = false
            try {
                await wire.$commit()
                this.saveState = rejected ? 'rejected' : 'saved'
            } catch {
                // The request failed: keep the work and try again shortly, so a
                // dropped connection does not lose what the student typed.
                this.saveState = 'unsaved'
                clearTimeout(retryTimer)
                retryTimer = setTimeout(() => this.saveNow(), 2000)
            } finally {
                this.saving = false
            }
        },

        /**
         * Save when the student leaves the editor or the page, so the last
         * words are never lost to a closed tab.
         */
        saveOnLeave() {
            if (this.saveState === 'unsaved') {
                this.saveNow()
            }
        },

        // ---- Running ------------------------------------------------------------

        async runCode(page = null, request = null) {
            if (! this.canRun || this.run.running) {
                return
            }
            // Save first: a student's own infinite loop must not cost them their latest edits.
            this.saveNow()
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
                    this.renderWebPreview(files)
                }
            } catch (error) {
                this.run.status = ''
                this.run.console.push({ level: 'error', text: error.message })
            } finally {
                this.run.running = false
            }
        },

        /**
         * Show the web project in the preview frame. Used by Run and by the
         * live preview, which calls it again as the student types.
         */
        renderWebPreview(files) {
            const page = this.run.page
            const source = page ? files.find((file) => file.name === page)?.content : null
            this.showPreview(source ?? shellDocument(files), files, page)
            this.run.status = page ? `Showing ${page}` : 'Running scripts and styles on an empty page.'
        },

        /**
         * A tag the student left open swallows everything after it, so the
         * script that follows shows up as plain text. Say so plainly, because
         * the preview alone looks like the code simply did not run.
         */
        warnAboutUnclosedTag(html) {
            const tag = unclosedTag(html)
            if (! tag) {
                return false
            }
            const message = `Your <${tag}> tag is not closed. Add a ">" after it, otherwise everything after it is treated as part of the tag and your code will not run.`
            if (! this.run.console.some((line) => line.text === message)) {
                this.run.console.push({ level: 'error', text: message })
            }

            return true
        },

        /**
         * Refresh the preview while the student types.
         *
         * The web runner is quick. PHP runs in the browser too, so it follows
         * as well once the runtime has started; SQL needs the server and is
         * left to the Run button.
         */
        scheduleLivePreview() {
            if (! this.livePreview || ! this.canRun || this.runner === 'sql' || this.run.running) {
                return
            }
            clearTimeout(liveTimer)
            liveTimer = setTimeout(() => this.refreshLivePreview(), LIVE_PREVIEW_DELAY)
        },

        async refreshLivePreview() {
            if (! this.livePreview || this.run.running) {
                return
            }
            const files = this.files.map(({ name, content }) => ({ name, content }))
            if (! this.run.page) {
                this.run.page = this.pages[0] ?? null
            }
            if (! this.run.page) {
                return
            }
            this.run.visible = true
            this.run.console = []
            try {
                if (this.runner === 'php') {
                    await this.runPhpPage(files, { method: 'GET', url: `/${this.run.page}` })
                } else {
                    this.renderWebPreview(files)
                }
            } catch (error) {
                this.run.status = ''
                this.run.console.push({ level: 'error', text: error.message })
            }
        },

        async runPhpPage(files, request) {
            this.run.status = 'Running PHP… (the first run loads PHP and can take a moment)'
            for (let redirects = 0; redirects < 5; redirects++) {
                const response = await runPhp(runnerBaseUrl, files, request)
                if (response.location && response.status >= 300 && response.status < 400) {
                    const target = resolveProjectHref(response.location, request.url.slice(1).split('?')[0])
                    if (! target) {
                        break
                    }
                    request = { method: 'GET', url: `/${target.path}${target.query ? `?${target.query}` : ''}` }
                    this.run.page = target.path
                    continue
                }
                this.warnAboutUnclosedTag(response.body)
                this.showPreview(response.body, files, request.url.slice(1).split('?')[0])
                this.run.status = `${request.method} ${request.url.slice(1)} → ${response.status}`

                return
            }
            this.run.console.push({ level: 'error', text: 'Too many redirects, or a redirect outside this project.' })
        },

        /**
         * The preview frame. It is created once and moved between the panel
         * below the editor and the side-by-side host next to it, so switching
         * the layout never reloads the page.
         */
        previewFrame() {
            if (! this.$refs.preview) {
                const frame = document.createElement('iframe')
                frame.className = 'cq-preview'
                frame.title = 'Program output'
                frame.setAttribute('sandbox', 'allow-scripts allow-forms allow-modals')
                this.$refs.preview = frame
            }

            return this.$refs.preview
        },

        /**
         * Put the preview frame where it belongs for the current layout.
         */
        placePreview() {
            const frame = this.previewFrame()
            const host = this.sideBySide ? this.$refs.splitHost : this.$refs.panelHost
            if (host && frame.parentElement !== host) {
                host.appendChild(frame)
            }
            frame.classList.toggle('cq-preview-split', this.sideBySide)
        },

        /**
         * Show the preview next to the code, or below it again.
         */
        toggleSideBySide() {
            this.sideBySide = ! this.sideBySide
            this.run.visible = true
            this.$nextTick(() => this.placePreview())
        },

        /**
         * Update the preview as the student types.
         */
        toggleLivePreview() {
            this.livePreview = ! this.livePreview
            if (this.livePreview) {
                this.run.visible = true
                this.refreshLivePreview()
            }
        },

        showPreview(source, files, pageName = null) {
            this.run.nonce = Math.random().toString(36).slice(2)
            this.run.hasPreview = true
            this.placePreview()
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
            const target = resolveProjectHref(href, this.run.page ?? '')
            if (! target || ! this.files.some((file) => file.name === target.path)) {
                this.run.console.push({ level: 'info', text: `The preview only opens this project's files, not ${href}.` })

                return
            }
            if (this.runner === 'php') {
                this.runCode(target.path, { method: 'GET', url: `/${target.path}${target.query ? `?${target.query}` : ''}` })
            } else if (this.pages.includes(target.path)) {
                this.runCode(target.path)
            }
        },

        submitForm({ method, action, fields }) {
            if (this.runner !== 'php') {
                this.run.console.push({ level: 'info', text: 'Form submitted. Forms need a PHP file to receive them.' })

                return
            }
            const target = resolveProjectHref(action || this.run.page || 'index.php', this.run.page ?? '') ?? { path: this.run.page ?? 'index.php', query: '' }
            const query = new URLSearchParams(fields).toString()
            const request = method === 'POST'
                ? {
                    method: 'POST',
                    url: `/${target.path}${target.query ? `?${target.query}` : ''}`,
                    body: query,
                    headers: { 'content-type': 'application/x-www-form-urlencoded' },
                }
                : { method: 'GET', url: `/${target.path}${query ? `?${query}` : ''}` }
            this.runCode(target.path, request)
        },
    }
}
