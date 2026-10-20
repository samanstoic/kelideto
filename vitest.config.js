import { defineConfig } from 'vitest/config';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDirectory = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
    test: {
        globals: true,
        environment: 'jsdom',
        include: ['tests/frontend/**/*.{test,spec}.js'],
    },
    resolve: {
        alias: {
            '@': path.resolve(rootDirectory, './resources/js'),
        },
    },
});
