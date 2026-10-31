import { defineConfig } from "vite";
import laravel, { refreshPaths } from "laravel-vite-plugin";

export default defineConfig({
    plugins: [
        laravel({
            input: ["resources/css/app.css", "resources/css/filament/admin/theme.css", "resources/js/live-updates.js", "resources/js/evaluation-entry-live.js", "resources/js/workspace-drafts.js"],
            refresh: [
                ...refreshPaths,
                "app/Livewire/**",
                "app/Filament/**",
                "app/Providers/**",
            ],
        }),
    ],
    build: {
        // Raise the warning threshold slightly — Filament theme is legitimately large
        chunkSizeWarningLimit: 1000,
        // Don't recalculate compressed sizes during build (faster builds)
        reportCompressedSize: false,
        rollupOptions: {
            output: {
                // Split vendor JS into a separate cacheable chunk
                manualChunks(id) {
                    if (id.includes('node_modules')) {
                        return 'vendor';
                    }
                },
                // Consistent file naming with content hash for long-term caching
                chunkFileNames: 'assets/js/[name]-[hash].js',
                entryFileNames: 'assets/js/[name]-[hash].js',
                assetFileNames: 'assets/[ext]/[name]-[hash].[ext]',
            },
        },
        // Use esbuild for fast minification (default in Vite)
        minify: 'esbuild',
        // Enable CSS code splitting — only loads CSS needed per entry
        cssCodeSplit: true,
    },
});
