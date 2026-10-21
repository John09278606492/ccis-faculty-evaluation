/**
 * Automatic checking for code questions. Runs the instructor's reference
 * solution and a student's answer on the same test cases, in the grader's own
 * browser with the same runners students use (see code-runner.js), and
 * compares what they output. How the code is written does not matter.
 *
 *  - php: each test case opens a page (with a query string or POST fields) and
 *    compares the text of the page it returns
 *  - sql: each test case runs a check query after the student's SQL and
 *    compares its rows (column names are ignored)
 *  - web: each test case runs JavaScript on the loaded page and compares its
 *    result; with no script, the page's text and console output
 *
 * The server stores the results and turns them into a suggested score
 * (AttemptsRelationManager::recordAutoCheck()).
 */
import { buildDocument, pagesFor, resolveProjectHref, runPhp, runSqlCheck, shellDocument } from './code-runner.js'

const WEB_TIMEOUT_MS = 8_000
const SETTLE_MS = 300
const MAX_OUTPUT = 2_000

/** One comparable text: trimmed lines, runs of spaces collapsed, blank lines dropped. */
export function normalizeOutput(text, ignoreCase = false) {
    const lines = String(text ?? '').replace(/\r\n?/g, '\n').split('\n')
        .map((line) => line.replace(/[ \t ]+/g, ' ').trim())
        .filter((line) => line !== '')
    const joined = lines.join('\n')

    return ignoreCase ? joined.toLowerCase() : joined
}

/** Outputs match when their normalized text does, or when only spacing differs. */
export function sameOutput(expected, actual, ignoreCase = false) {
    const a = normalizeOutput(expected, ignoreCase)
    const b = normalizeOutput(actual, ignoreCase)

    return a === b || a.replace(/\s+/g, '') === b.replace(/\s+/g, '')
}

/** The visible text of an HTML page, one line per block, without scripts and styles. */
export function pageText(html) {
    const marked = String(html).replace(/<(br|hr)\b[^>]*>|<\/(p|div|li|tr|h[1-6]|table|ul|ol|pre|section|article|header|footer|form|label|option|dt|dd|blockquote)>/gi, '$&\n')
        .replace(/<\/(td|th)>/gi, '$& ')
    const doc = new DOMParser().parseFromString(marked, 'text/html')
    doc.querySelectorAll('script, style, template').forEach((node) => node.remove())

    return doc.body?.textContent ?? ''
}

function tablesText(tables) {
    return tables.map((table) => table.values.map((row) => row.map((value) => (value === null ? 'NULL' : String(value))).join(' | ')).join('\n')).join('\n\n')
}

/** "index.php?n=5" plus optional name=value lines (sent as a POST). */
export function phpRequest(input, files) {
    const lines = String(input ?? '').split(/\r?\n/).map((line) => line.trim()).filter(Boolean)
    const entry = pagesFor(files, 'php')[0] ?? 'index.php'
    const isPage = /^\/?[\w./-]*\.(php|html?)(\?.*)?$/i.test(lines[0] ?? '') || (lines[0] ?? '').startsWith('?')
    const target = (isPage ? resolveProjectHref(lines.shift(), entry) : null) ?? { path: entry, query: '' }
    const url = `/${target.path || entry}${target.query ? `?${target.query}` : ''}`
    if (! lines.length) {
        return { method: 'GET', url }
    }
    const body = new URLSearchParams(lines.map((line) => {
        const at = line.indexOf('=')

        return at === -1 ? [line, ''] : [line.slice(0, at), line.slice(at + 1)]
    })).toString()

    return { method: 'POST', url, body, headers: { 'content-type': 'application/x-www-form-urlencoded' } }
}

async function phpOutput(baseUrl, files, input) {
    let request = phpRequest(input, files)
    for (let redirects = 0; redirects < 5; redirects++) {
        const response = await runPhp(baseUrl, files, request)
        if (response.location && response.status >= 300 && response.status < 400) {
            const target = resolveProjectHref(response.location, request.url.slice(1).split('?')[0])
            if (! target) {
                break
            }
            request = { method: 'GET', url: `/${target.path}${target.query ? `?${target.query}` : ''}` }
            continue
        }
        const text = pageText(response.body)

        return response.status === 200 ? text : `HTTP ${response.status}\n${text}`
    }

    return 'Too many redirects, or a redirect outside this project.'
}

async function sqlOutput(baseUrl, files, input) {
    const { tables, error } = await runSqlCheck(baseUrl, files, String(input ?? ''))
    const text = tablesText(tables)

    return error ? `${text}\nError: ${error}`.trim() : text
}

/** Loads the page in a hidden sandboxed frame (no access to this page) and runs the check in it. */
function webOutput(container, files, input) {
    const page = pagesFor(files, 'web')[0] ?? null
    const source = page ? files.find((file) => file.name === page).content : shellDocument(files)
    const nonce = Math.random().toString(36).slice(2)
    const script = String(input ?? '').trim()
    const frame = document.createElement('iframe')
    frame.setAttribute('sandbox', 'allow-scripts allow-forms')
    frame.setAttribute('aria-hidden', 'true')
    frame.tabIndex = -1
    frame.style.cssText = 'position:fixed;left:-12000px;top:0;width:1024px;height:768px;border:0;'
    const consoleLines = []

    return new Promise((resolve) => {
        let done = false
        const finish = (text) => {
            if (done) {
                return
            }
            done = true
            clearTimeout(timer)
            window.removeEventListener('message', onMessage)
            frame.remove()
            resolve(text)
        }
        const timer = setTimeout(() => finish(`Timed out after ${WEB_TIMEOUT_MS / 1000} seconds.`), WEB_TIMEOUT_MS)
        const onMessage = (event) => {
            const data = event.data
            if (event.source !== frame.contentWindow || data?.nonce !== nonce) {
                return
            }
            if (data.type === 'console') {
                consoleLines.push(data.level === 'error' ? `Error: ${data.text}` : String(data.text))
            } else if (data.type === 'loaded') {
                setTimeout(() => frame.contentWindow?.postMessage({
                    type: 'check',
                    nonce,
                    code: script || "document.body ? document.body.innerText : ''",
                }, '*'), SETTLE_MS)
            } else if (data.type === 'check-result') {
                const value = data.error !== undefined ? `Error: ${data.error}` : String(data.value)
                finish(script ? value : [value, ...(consoleLines.length ? ['Console:', ...consoleLines] : [])].join('\n'))
            }
        }
        window.addEventListener('message', onMessage)
        frame.srcdoc = buildDocument(source, files, nonce, page)
        container.appendChild(frame)
    })
}

/** What the files output for one test case input. */
export async function projectOutput({ baseUrl, runner, files, input, container }) {
    try {
        const output = runner === 'php'
            ? await phpOutput(baseUrl, files, input)
            : runner === 'sql'
                ? await sqlOutput(baseUrl, files, input)
                : await webOutput(container, files, input)

        return output.length > MAX_OUTPUT ? `${output.slice(0, MAX_OUTPUT)}…` : output
    } catch (error) {
        return `Error: ${error.message ?? error}`
    }
}

/**
 * single: checks one answer in the grading form and fills in its score when
 *         it has none yet.
 * bulk:   checks every submitted answer, one at a time, and lets the server
 *         fill in scores the instructor has not set.
 */
export default function codeCheckerComponent({ mode, runnerBaseUrl, questions, answers, pointsPath = null }) {
    return {
        mode,
        questions,
        state: 'idle',
        rows: [],
        checked: 0,
        total: answers.length,
        applied: 0,
        failed: 0,
        expected: {},
        error: '',

        init() {
            if (this.mode === 'single') {
                this.start()
            }
        },

        async referenceOutputs(questionId) {
            this.expected[questionId] ??= (async () => {
                const question = questions[questionId]
                const outputs = []
                for (const testCase of question.cases) {
                    outputs.push(await projectOutput({ baseUrl: runnerBaseUrl, runner: question.runner, files: question.reference, input: testCase.input, container: this.$refs.frames }))
                }

                return outputs
            })()

            return this.expected[questionId]
        },

        async checkAnswer(answer) {
            const question = questions[answer.questionId]
            const files = answer.files ?? await this.$wire.codeCheckFiles(answer.id)
            const expected = await this.referenceOutputs(answer.questionId)
            const cases = []
            for (const [index, testCase] of question.cases.entries()) {
                const actual = files === null
                    ? 'No code submitted.'
                    : await projectOutput({ baseUrl: runnerBaseUrl, runner: question.runner, files, input: testCase.input, container: this.$refs.frames })
                cases.push({
                    label: testCase.label || `Test ${index + 1}`,
                    passed: files !== null && sameOutput(expected[index], actual, question.ignoreCase),
                    expected: expected[index],
                    actual,
                })
            }

            return cases
        },

        async start() {
            if (this.state === 'running') {
                return
            }
            Object.assign(this, { state: 'running', rows: [], checked: 0, applied: 0, failed: 0, error: '' })
            try {
                for (const answer of answers) {
                    const cases = await this.checkAnswer(answer)
                    const result = await this.$wire.recordAutoCheck(answer.id, cases, this.mode === 'bulk')
                    this.checked++
                    this.applied += result.applied ? 1 : 0
                    this.failed += result.passed < result.total ? 1 : 0
                    this.rows.push({ ...answer, cases, ...result })
                    if (this.mode === 'single' && pointsPath && [null, undefined, ''].includes(this.$wire.get(pointsPath))) {
                        this.$wire.set(pointsPath, result.suggested, false)
                    }
                }
                this.state = 'done'
            } catch (error) {
                this.error = error.message ?? String(error)
                this.state = 'error'
            }
        },

        useSuggested(row) {
            this.$wire.set(pointsPath, row.suggested, false)
        },
    }
}
