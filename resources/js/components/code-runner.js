/**
 * Runs a code question's files in the student's own browser, never on the server:
 *
 *  - web: HTML/CSS/JS rendered in a sandboxed iframe (no same-origin access)
 *  - php: PHP 8.3 compiled to WebAssembly, in a Web Worker (public/code-runner/php-worker.js)
 *  - sql: SQLite compiled to WebAssembly, in a Web Worker (public/code-runner/sql/)
 *
 * Workers are shared by every editor on the page and restarted after a timeout,
 * so an infinite loop only stops that run.
 */

export const RUN_TIMEOUT_MS = 10_000

const extensionOf = (name) => (name.split('.').pop() || '').toLowerCase()

/** Same rules as App\Support\CodeProject::runner(). */
export function runnerFor(files) {
    const extensions = files.map((file) => extensionOf(file.name))

    if (extensions.includes('php') && files.some((file) => hasPhpCode(file))) {
        return 'php'
    }
    if (extensions.some((extension) => ['html', 'htm', 'css', 'js', 'php'].includes(extension))) {
        return 'web'
    }

    return extensions.includes('sql') ? 'sql' : null
}

/**
 * Does this file actually run PHP? A .php file that holds only HTML is a web
 * page: it is shown in the preview straight away, without waiting for the PHP
 * runtime to start, and it updates as the student types.
 */
export function hasPhpCode(file) {
    return /<\?php|<\?=/.test(file.content ?? '')
}

/** Files that can be opened as a page in the preview, entry page first. */
export function pagesFor(files, runner) {
    const pageExtensions = runner === 'php' ? ['php', 'html', 'htm'] : ['html', 'htm']
    const pages = files.map((file) => file.name).filter((name) => pageExtensions.includes(extensionOf(name)))
    const entry = ['index.php', 'index.html', 'index.htm'].find((name) => pages.includes(name))

    return entry ? [entry, ...pages.filter((name) => name !== entry)] : pages
}

class WorkerPool {
    constructor(url, type) {
        this.url = url
        this.type = type
        this.worker = null
        this.nextId = 0
        this.pending = new Map()
    }

    start() {
        if (this.worker) {
            return
        }
        this.worker = new Worker(this.url, { type: this.type })
        this.worker.addEventListener('message', ({ data }) => {
            this.pending.get(data.id)?.resolve(data)
            this.pending.delete(data.id)
        })
        this.worker.addEventListener('error', (event) => {
            this.reset(new Error(event.message || 'The code runner failed to load.'))
        })
    }

    call(message, timeoutMs = RUN_TIMEOUT_MS) {
        this.start()
        const id = ++this.nextId

        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                this.reset(new Error(`Stopped after ${timeoutMs / 1000} seconds. Check for an infinite loop.`))
            }, timeoutMs)
            this.pending.set(id, {
                resolve: (data) => {
                    clearTimeout(timer)
                    resolve(data)
                },
                reject: (error) => {
                    clearTimeout(timer)
                    reject(error)
                },
            })
            this.worker.postMessage({ ...message, id })
        })
    }

    reset(error) {
        this.worker?.terminate()
        this.worker = null
        for (const { reject } of this.pending.values()) {
            reject(error)
        }
        this.pending.clear()
    }
}

const pools = new Map()

function pool(url, type = 'module') {
    if (! pools.has(url)) {
        pools.set(url, new WorkerPool(url, type))
    }

    return pools.get(url)
}

/**
 * @returns {Promise<{ status: number, body: string, location: string|null }>}
 */
export async function runPhp(baseUrl, files, request) {
    // The first run downloads PHP (~18 MB), so it gets extra time.
    const phpPool = pool(`${baseUrl}/php-worker.js`)
    const timeout = phpPool.worker ? RUN_TIMEOUT_MS : RUN_TIMEOUT_MS * 6
    const result = await phpPool.call({ files, request }, timeout)
    if (! result.ok) {
        throw new Error(result.error)
    }

    return {
        status: result.status,
        body: result.body,
        location: result.headers?.location?.[0] ?? null,
    }
}

/**
 * Runs every .sql file in order against a fresh in-memory SQLite database.
 *
 * @returns {Promise<Array<{ columns: string[], values: any[][] }>>}
 */
export async function runSql(baseUrl, files) {
    // sql.js ships a classic (non-module) worker script.
    const sqlPool = pool(`${baseUrl}/sql/worker.sql-wasm.js`, 'classic')
    const sql = files.filter((file) => extensionOf(file.name) === 'sql').map((file) => file.content).join('\n;\n')
    await sqlPool.call({ action: 'open' })
    const result = await sqlPool.call({ action: 'exec', sql })
    if (result.error) {
        throw new Error(result.error)
    }

    return result.results ?? []
}

/**
 * Runs the .sql files, then `checkSql` on the same database, for automatic
 * checking. Returns the rows of the check query, or of the files' own queries
 * when there is no check query; an error in the files is reported in `error`
 * and the check query still runs.
 *
 * @returns {Promise<{ tables: Array<{ columns: string[], values: any[][] }>, error: string|null }>}
 */
export async function runSqlCheck(baseUrl, files, checkSql = '') {
    const sqlPool = pool(`${baseUrl}/sql/worker.sql-wasm.js`, 'classic')
    const sql = files.filter((file) => extensionOf(file.name) === 'sql').map((file) => file.content).join('\n;\n')
    await sqlPool.call({ action: 'open' })
    const own = await sqlPool.call({ action: 'exec', sql })
    if (! checkSql.trim()) {
        return { tables: own.results ?? [], error: own.error ?? null }
    }
    const check = await sqlPool.call({ action: 'exec', sql: checkSql })

    return { tables: check.results ?? [], error: check.error ?? own.error ?? null }
}

const escapeRegExp = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

/**
 * Finds a tag that was never closed, like "</form" without its ">".
 *
 * A tag left open swallows everything after it, so a <script> that follows
 * shows up as plain text in the preview instead of running. The student is
 * told about it here, rather than left wondering why their code does nothing.
 *
 * @returns {string|null} the name of the tag that is not closed, or null
 */
export function unclosedTag(html) {
    // A close tag with no ">" after it: "</form" followed by something that
    // is not the end of the tag.
    const match = html.match(/<\/([a-zA-Z][a-zA-Z0-9-]*)\b[^<>]*?(?=<|$)/g)
    if (! match) {
        return null
    }

    for (const tag of match) {
        const name = tag.match(/^<\/([a-zA-Z][a-zA-Z0-9-]*)/)?.[1]
        if (name && ! tag.endsWith('>')) {
            return name
        }
    }

    return null
}

/**
 * A link's target inside the project, resolved from the page it appears on:
 * from pages/about.html, "../css/site.css" is css/site.css. Null for links
 * that leave the project (other sites, mailto:, #anchors).
 *
 * @returns {{ path: string, query: string } | null}
 */
export function resolveProjectHref(href, fromFile = '') {
    if (! href || href.startsWith('#') || /^[a-z][a-z0-9+.-]*:/i.test(href) || href.startsWith('//')) {
        return null
    }
    const base = new URL(fromFile || 'index.html', 'http://project.invalid/')
    const url = new URL(href, base)

    return url.origin === 'http://project.invalid'
        ? { path: decodeURIComponent(url.pathname.slice(1)), query: url.search.slice(1) }
        : null
}

const projectPath = (href, fromFile) => resolveProjectHref(href, fromFile)?.path

/**
 * Inline the project's own stylesheets and scripts (the preview has no server
 * to fetch them from) and add the bridge that reports console output, link
 * clicks and form submissions back to the editor. Nothing here adds lines, so
 * errors keep the line numbers of the student's own files.
 */
export function buildDocument(html, files, nonce, pageName = null) {
    const byName = new Map(files.map((file) => [file.name, file.content]))
    const scriptNames = {}

    html = html.replace(/<link\b[^>]*>/gi, (tag) => {
        const href = tag.match(/\bhref\s*=\s*["']([^"']+)["']/i)?.[1]
        const css = href && /\brel\s*=\s*["']?stylesheet/i.test(tag) ? byName.get(projectPath(href, pageName)) : undefined

        // CSS never depends on line breaks, so one line keeps the page's line numbers.
        return css === undefined ? tag : `<style>${css.replace(/<\/style/gi, '<\/style').replace(/\r?\n/g, ' ')}</style>`
    })
    html = html.replace(/<script\b([^>]*)\bsrc\s*=\s*["']([^"']+)["']([^>]*)>\s*<\/script>/gi, (tag, before, src, after) => {
        const js = byName.get(projectPath(src, pageName))
        if (js === undefined) {
            return tag
        }
        // The script's own text is put in the tag rather than in a data: URL:
        // Chrome refuses to run data: URL scripts inside a sandboxed srcdoc
        // frame, which is exactly how the preview is shown, so a separate .js
        // file would never run at all.
        const name = projectPath(src, pageName)
        // The file name is kept for the error message the bridge reports.
        scriptNames[name] = name

        return `<script${before}${after}>${js.replace(/<\/script/gi, '<\\/script')}</script>`
    })

    const bridge = `<script>${bridgeSource(nonce, scriptNames, pageName).replace(/\n\s*/g, ' ')}</script>`
    for (const pattern of [/<head\b[^>]*>/i, /<html\b[^>]*>/i, /<!doctype[^>]*>/i]) {
        if (pattern.test(html)) {
            return html.replace(pattern, (match) => match + bridge)
        }
    }

    return bridge + html
}

/** A page for projects without an HTML file: their CSS and JS on an empty body. */
export function shellDocument(files) {
    const styles = files.filter((file) => extensionOf(file.name) === 'css')
        .map((file) => `<link rel="stylesheet" href="${file.name}">`).join('')
    const scripts = files.filter((file) => extensionOf(file.name) === 'js')
        .map((file) => `<script src="${file.name}"></script>`).join('')

    return `<!DOCTYPE html><html><head><meta charset="utf-8">${styles}</head><body>${scripts}</body></html>`
}


function bridgeSource(nonce, scriptNames, pageName) {
    // Runs first inside the sandboxed preview. Kept in ES5 so it works whatever
    // the student's own code does.
    return `(function () {
    var nonce = ${JSON.stringify(nonce)};
    var scriptNames = ${JSON.stringify(scriptNames)};
    var pageName = ${JSON.stringify(pageName)};
    function send(message) { message.nonce = nonce; parent.postMessage(message, '*'); }
    function format(value) {
        if (typeof value === 'string') return value;
        if (value instanceof Error) return value.name + ': ' + value.message;
        try { return JSON.stringify(value); } catch (e) { return String(value); }
    }
    ['log', 'info', 'warn', 'error', 'debug'].forEach(function (level) {
        var original = console[level];
        console[level] = function () {
            try { send({ type: 'console', level: level, text: Array.prototype.map.call(arguments, format).join(' ') }); } catch (e) {}
            if (original) original.apply(console, arguments);
        };
    });
    window.addEventListener('error', function (event) {
        var file = scriptNames[event.filename] || pageName;
        var where = event.lineno ? ' (' + (file ? file + ', ' : '') + 'line ' + event.lineno + ')' : '';
        send({ type: 'console', level: 'error', text: event.message + where });
    });
    window.addEventListener('unhandledrejection', function (event) {
        send({ type: 'console', level: 'error', text: 'Uncaught (in promise) ' + format(event.reason) });
    });
    window.addEventListener('load', function () { send({ type: 'loaded' }); });
    window.addEventListener('message', function (event) {
        var data = event.data;
        if (event.source !== parent || ! data || data.nonce !== nonce || data.type !== 'check') return;
        var code = String(data.code).replace(/[;\\s]+$/, ''), run;
        try { run = new Function('return (async function () { return (' + code + '\\n); })();'); }
        catch (e) {
            try { run = new Function('return (async function () { ' + code + '\\n })();'); }
            catch (error) { send({ type: 'check-result', error: format(error) }); return; }
        }
        Promise.resolve().then(run).then(function (value) {
            send({ type: 'check-result', value: value === undefined || value === null ? '' : format(value) });
        }, function (error) { send({ type: 'check-result', error: format(error) }); });
    });
    function memoryStorage() {
        var entries = Object.create(null);
        return {
            getItem: function (key) { return Object.prototype.hasOwnProperty.call(entries, key) ? entries[key] : null; },
            setItem: function (key, value) { entries[key] = String(value); },
            removeItem: function (key) { delete entries[key]; },
            clear: function () { entries = Object.create(null); },
            key: function (index) { return Object.keys(entries)[index] || null; },
            get length() { return Object.keys(entries).length; }
        };
    }
    ['localStorage', 'sessionStorage'].forEach(function (name) {
        /* Initialize the closure before probing the sandbox's storage getter. */
        var fallback = memoryStorage();
        try { window[name].length; } catch (e) {
            Object.defineProperty(window, name, { value: fallback, configurable: true });
        }
    });
    document.addEventListener('click', function (event) {
        var link = event.target.closest && event.target.closest('a[href]');
        if (! link) return;
        var href = link.getAttribute('href');
        if (href.charAt(0) === '#') return;
        event.preventDefault();
        send({ type: 'navigate', href: href });
    }, true);
    document.addEventListener('submit', function (event) {
        var form = event.target;
        event.preventDefault();
        var fields = [];
        new FormData(form, event.submitter).forEach(function (value, key) {
            if (typeof value === 'string') fields.push([key, value]);
        });
        send({ type: 'submit', method: (form.getAttribute('method') || 'GET').toUpperCase(), action: form.getAttribute('action') || '', fields: fields });
    }, true);
})();`
}
