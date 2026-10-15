/**
 * Runs a student's PHP project inside this Web Worker with PHP compiled to
 * WebAssembly. Nothing reaches the server: the code runs in the student's own
 * browser, and the page terminates this worker if a script runs too long.
 *
 * Message in:  { id, files: [{ name, content }], request: { method, url, headers, body } }
 * Message out: { id, ok, status, headers, body, error }
 */
import { loadPHPRuntime, PHP, PHPRequestHandler, setPhpIniEntries } from '@php-wasm/universal'
// Aliased by scripts/build-code-runner.mjs to @php-wasm/web-8-3's asyncify build,
// which runs in every current browser (the JSPI build needs newer Chrome).
import * as phpLoader from 'php-wasm-loader'

const DOCUMENT_ROOT = '/www'

let handlerPromise = null

function handler() {
    handlerPromise ??= (async () => {
        const runtime = await loadPHPRuntime(phpLoader, {
            phpWasmAsyncMode: 'asyncify',
            // Student code has no network access; make socket creation a no-op.
            websocket: {
                decorator: (WebSocket) => class extends WebSocket {
                    constructor() {
                        try {
                            super()
                        } catch {}
                    }

                    send() {
                        return null
                    }
                },
            },
        })
        const php = new PHP(runtime)
        php.mkdir(DOCUMENT_ROOT)
        await setPhpIniEntries(php, {
            display_errors: 'On',
            display_startup_errors: 'On',
            error_reporting: 'E_ALL',
            html_errors: 'Off',
        })

        return new PHPRequestHandler({
            phpFactory: async () => php,
            documentRoot: DOCUMENT_ROOT,
            absoluteUrl: 'http://localhost/',
        })
    })()

    return handlerPromise
}

async function writeProject(requestHandler, files) {
    const php = await requestHandler.getPrimaryPhp()
    if (php.fileExists(DOCUMENT_ROOT)) {
        php.rmdir(DOCUMENT_ROOT, { recursive: true })
    }
    php.mkdir(DOCUMENT_ROOT)
    for (const file of files) {
        php.writeFile(`${DOCUMENT_ROOT}/${file.name}`, file.content ?? '')
    }
}

self.addEventListener('message', async ({ data }) => {
    const { id, files, request } = data
    try {
        const requestHandler = await handler()
        await writeProject(requestHandler, files)
        const response = await requestHandler.request({
            method: request.method ?? 'GET',
            url: request.url,
            headers: request.headers ?? {},
            body: request.body,
        })

        self.postMessage({
            id,
            ok: true,
            status: response.httpStatusCode,
            headers: response.headers,
            body: response.text,
            error: response.errors || null,
        })
    } catch (error) {
        self.postMessage({ id, ok: false, error: String(error?.message ?? error) })
    }
})

self.postMessage({ ready: true })
