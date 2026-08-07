import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// Builds the workbench assets for the browser test suite. The manifest keys
// must be exactly 'resources/css/app.css' / 'resources/js/app.js' — that is
// what the package layouts request via @vite() — so the inputs live at the
// package root, not under workbench/.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            publicDirectory: 'workbench/public',
            buildDirectory: 'build',
        }),
        tailwindcss(),
    ],
});
