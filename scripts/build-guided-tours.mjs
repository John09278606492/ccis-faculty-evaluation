import { build } from 'esbuild';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
const root = join(dirname(fileURLToPath(import.meta.url)), '..');
await build({
    entryPoints: [join(root, 'resources/js/guided-tours.js')],
    outfile: join(root, 'public/js/app/campus-guided-tours.js'),
    bundle: true, minify: true, format: 'iife', target: 'es2022',
});
