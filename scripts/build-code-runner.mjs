/**
 * Builds the quiz code editor and its in-browser runners into local files so
 * code questions work on a classroom LAN without internet:
 *
 *  - resources/js/dist/components/{code-editor,exam-supervision}.js  (Filament publishes them to public/js/app)
 *  - public/code-runner/php-worker.js + 8_3_33/php_8_3.wasm  (PHP 8.3 as WebAssembly)
 *  - public/code-runner/sql/  (SQLite as WebAssembly)
 *
 * Run with `npm run build:code-editor`, then `php artisan filament:assets`.
 */
import { build } from 'esbuild'
import { cpSync, mkdirSync, readdirSync, rmSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const runnerDir = join(root, 'public/code-runner')
const phpBuildDir = join(root, 'node_modules/@php-wasm/web-8-3/asyncify')

for (const component of ['code-editor', 'exam-supervision']) {
    await build({
        entryPoints: [join(root, `resources/js/components/${component}.js`)],
        outfile: join(root, `resources/js/dist/components/${component}.js`),
        bundle: true,
        format: 'esm',
        minify: true,
        target: 'es2020',
    })
}

rmSync(runnerDir, { recursive: true, force: true })
mkdirSync(join(runnerDir, 'sql'), { recursive: true })

// The PHP loader finds its binary with new URL('./8_3_xx/php_8_3.wasm', import.meta.url),
// so the version folder is copied next to the worker bundle.
await build({
    entryPoints: [join(root, 'resources/js/runners/php-worker.js')],
    outfile: join(runnerDir, 'php-worker.js'),
    bundle: true,
    format: 'esm',
    minify: true,
    target: 'es2022',
    // The worker never loads optional extensions (intl), so their assets are not shipped.
    loader: { '.dat': 'empty', '.so': 'empty' },
    alias: { 'php-wasm-loader': join(phpBuildDir, 'php_8_3.js') },
    // Only imported on Node.js code paths that never run in a browser worker.
    external: ['worker_threads'],
})
for (const entry of readdirSync(phpBuildDir, { withFileTypes: true })) {
    if (entry.isDirectory() && /^\d+_\d+_\d+$/.test(entry.name)) {
        cpSync(join(phpBuildDir, entry.name), join(runnerDir, entry.name), { recursive: true })
    }
}

for (const file of ['worker.sql-wasm.js', 'sql-wasm.wasm']) {
    cpSync(join(root, 'node_modules/sql.js/dist', file), join(runnerDir, 'sql', file))
}

console.log('Built code editor and runners.')
