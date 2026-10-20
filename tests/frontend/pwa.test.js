import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { runInNewContext } from 'node:vm';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const publicDirectory = resolve(process.cwd(), 'public');
const workerPath = resolve(publicDirectory, 'service-worker.js');
const manifestPath = resolve(publicDirectory, 'manifest.webmanifest');
const iconsDirectory = resolve(publicDirectory, 'icons');
let workerSource;

function loadServiceWorker({ cache, fetchMock = vi.fn() }) {
    const listeners = new Map();
    const selfRef = {
        location: new URL('https://keypro.example/'),
        addEventListener: (name, callback) => listeners.set(name, callback),
        skipWaiting: vi.fn(),
        clients: { claim: vi.fn() },
        registration: { showNotification: vi.fn() },
    };
    const cacheStorage = {
        open: vi.fn().mockResolvedValue(cache),
        keys: vi.fn().mockResolvedValue([]),
        delete: vi.fn(),
    };
    runInNewContext(workerSource, {
        self: selfRef,
        caches: cacheStorage,
        fetch: fetchMock,
        Response,
        URL,
        console,
    });
    return { listeners, cacheStorage, selfRef };
}

describe('PWA installation assets and service worker policy', () => {
    beforeEach(async () => {
        workerSource = await readFile(workerPath, 'utf8');
    });

    it('declares valid install icons and a Persian standalone manifest', async () => {
        const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
        expect(manifest.lang).toBe('fa');
        expect(manifest.dir).toBe('rtl');
        expect(manifest.display).toBe('standalone');

        for (const [name, dimensions] of [
            ['keypro-192.png', [192, 192]],
            ['keypro-512.png', [512, 512]],
            ['apple-touch-icon.png', [180, 180]],
        ]) {
            const bytes = await readFile(resolve(iconsDirectory, name));
            expect(bytes.subarray(0, 8)).toEqual(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]));
            expect([bytes.readUInt32BE(16), bytes.readUInt32BE(20)]).toEqual(dimensions);
        }
    });

    it('does not intercept API requests and falls back to the static offline page', async () => {
        const cache = { match: vi.fn().mockResolvedValue(new Response('app shell')) };
        const fetchMock = vi.fn().mockRejectedValue(new Error('offline'));
        const { listeners } = loadServiceWorker({ cache, fetchMock });

        const apiEvent = {
            request: { method: 'GET', url: 'https://keypro.example/api/payments', mode: 'cors' },
            respondWith: vi.fn(),
        };
        listeners.get('fetch')(apiEvent);
        expect(apiEvent.respondWith).not.toHaveBeenCalled();
        expect(fetchMock).not.toHaveBeenCalled();

        const pageEvent = {
            request: { method: 'GET', url: 'https://keypro.example/orders', mode: 'navigate' },
            respondWith: vi.fn(),
        };
        listeners.get('fetch')(pageEvent);
        await expect(pageEvent.respondWith.mock.calls[0][0]).resolves.toBeInstanceOf(Response);
        expect(cache.match).toHaveBeenCalledWith('/offline.html');
    });

    it('caches only versioned local build assets and the public manifest', async () => {
        const cache = { match: vi.fn().mockResolvedValue(null), put: vi.fn() };
        const fetchMock = vi.fn().mockResolvedValue(new Response('static'));
        const { listeners } = loadServiceWorker({ cache, fetchMock });

        const callFetch = (pathname) => {
            const event = {
                request: { method: 'GET', url: `https://keypro.example${pathname}`, mode: 'cors' },
                respondWith: vi.fn(),
            };
            listeners.get('fetch')(event);
            return event;
        };

        const versionedAsset = callFetch('/build/assets/app-AbCdEf123456.js');
        expect(versionedAsset.respondWith).toHaveBeenCalled();
        await versionedAsset.respondWith.mock.calls[0][0];

        const unversionedAsset = callFetch('/build/assets/app.js');
        expect(unversionedAsset.respondWith).not.toHaveBeenCalled();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        const manifest = callFetch('/manifest.webmanifest');
        expect(manifest.respondWith).toHaveBeenCalled();
    });

    it('does not cache a private response even when a static asset URL is requested', async () => {
        const cache = { match: vi.fn().mockResolvedValue(null), put: vi.fn() };
        const fetchMock = vi.fn().mockResolvedValue(new Response('private', {
            headers: { 'Cache-Control': 'private, no-store' },
        }));
        const { listeners } = loadServiceWorker({ cache, fetchMock });
        const event = {
            request: {
                method: 'GET',
                url: 'https://keypro.example/build/assets/app-AbCdEf123456.js',
                mode: 'cors',
            },
            respondWith: vi.fn(),
        };

        listeners.get('fetch')(event);
        await event.respondWith.mock.calls[0][0];

        expect(cache.put).not.toHaveBeenCalled();
    });

    it('never fetches or caches the Laravel HTML shell during installation', async () => {
        const cache = { put: vi.fn() };
        const fetchMock = vi.fn(async (path) => {
            if (path === '/build/manifest.json') {
                return new Response(JSON.stringify({
                    'resources/js/vanilla/app.js': {
                        file: 'assets/app-AbCdEf123456.js',
                        css: ['assets/app-AbCdEf123456.css'],
                    },
                }), { headers: { 'Content-Type': 'application/json' } });
            }
            return new Response('public asset');
        });
        const { listeners } = loadServiceWorker({ cache, fetchMock });
        const installEvent = { waitUntil: vi.fn() };

        listeners.get('install')(installEvent);
        await installEvent.waitUntil.mock.calls[0][0];

        const requestedPaths = fetchMock.mock.calls.map(([path]) => path);
        expect(requestedPaths).not.toContain('/');
        expect(requestedPaths).toContain('/build/assets/app-AbCdEf123456.js');
        expect(cache.put).not.toHaveBeenCalledWith('/', expect.anything());
    });
});
