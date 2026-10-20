import { createHash, webcrypto } from 'node:crypto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const storage = vi.hoisted(() => ({
    deleteTilePackage: vi.fn(),
    getAllTilePackages: vi.fn(),
    getTilePackage: vi.fn(),
    saveTilePackage: vi.fn(),
}));

vi.mock('../../resources/js/services/OfflineStorageService.js', () => storage);

import {
    getOfflineMapCatalog,
    installOfflineMap,
} from '../../resources/js/services/OfflineMapService.js';

const sha256 = (bytes) => createHash('sha256').update(bytes).digest('hex');

function packageEntry(overrides = {}) {
    return {
        id: 'test-region',
        name: 'آزمون منطقه',
        regionLabel: 'محدودهٔ آزمایشی',
        centerLat: -40,
        centerLng: 90,
        boundsSW: [-90, 0],
        boundsNE: [0, 180],
        zoomMin: 1,
        zoomMax: 1,
        tileCount: 1,
        sizeBytes: 4,
        version: 1,
        source: 'Example licensed raster distributor',
        sourceUrl: 'https://maps.example.org/region',
        license: 'ODbL-1.0',
        licenseUrl: 'https://www.openstreetmap.org/copyright',
        attribution: '© OpenStreetMap contributors',
        dataUpdatedAt: new Date(Date.now() - 86_400_000).toISOString(),
        validUntil: new Date(Date.now() + 86_400_000).toISOString(),
        indexUrl: '/offline-maps/test-region/v1/index.json',
        indexSizeBytes: 1,
        indexSha256: 'a'.repeat(64),
        ...overrides,
    };
}

function signedIndex(entry, tileBytes) {
    const tile = {
        z: 1,
        x: 1,
        y: 1,
        sizeBytes: tileBytes.byteLength,
        sha256: sha256(tileBytes),
        url: '/offline-maps/test-region/v1/tiles/1/1/1.png',
    };
    const indexBytes = new TextEncoder().encode(JSON.stringify({ tiles: [tile] }));
    return {
        entry: packageEntry({
            sizeBytes: tileBytes.byteLength,
            indexSizeBytes: indexBytes.byteLength,
            indexSha256: sha256(indexBytes),
        }),
        indexBytes,
    };
}

describe('offline map package service', () => {
    let cache;
    let cacheStorage;

    beforeEach(() => {
        cache = { put: vi.fn().mockResolvedValue(undefined), match: vi.fn() };
        cacheStorage = {
            open: vi.fn().mockResolvedValue(cache),
            delete: vi.fn().mockResolvedValue(true),
        };
        Object.defineProperty(window, 'caches', { configurable: true, value: cacheStorage });
        vi.stubGlobal('caches', cacheStorage);
        vi.stubGlobal('crypto', webcrypto);
        storage.getTilePackage.mockResolvedValue(undefined);
        storage.saveTilePackage.mockResolvedValue(undefined);
        storage.deleteTilePackage.mockResolvedValue(undefined);
    });

    afterEach(() => {
        delete window.caches;
        vi.unstubAllGlobals();
        vi.clearAllMocks();
    });

    it('reports an unconfigured local catalog instead of leaking an HTML parse error', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(
            '<!doctype html><html><body>KeyPro</body></html>',
            { status: 200, headers: { 'Content-Type': 'text/html' } },
        )));

        await expect(getOfflineMapCatalog()).rejects.toThrow(
            'فهرست بسته‌های نقشهٔ آفلاین روی این میزبان پیکربندی نشده است.',
        );
    });

    it('reports malformed JSON as a user-readable catalog error', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(
            '{broken',
            { status: 200, headers: { 'Content-Type': 'application/json' } },
        )));

        await expect(getOfflineMapCatalog()).rejects.toThrow(
            'پاسخ فهرست بسته‌های نقشه JSON معتبر نیست.',
        );
    });

    it('rejects an unlicensed catalog package', async () => {
        const invalid = packageEntry({ license: 'unknown' });
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(
            JSON.stringify({ packages: [invalid] }),
            { status: 200, headers: { 'Content-Type': 'application/json' } },
        )));

        await expect(getOfflineMapCatalog()).rejects.toThrow('مشخصات یا مجوز بستهٔ نقشه معتبر نیست.');
    });

    it('rejects a package catalog entry that exceeds the maximum install size', async () => {
        const oversized = packageEntry({ sizeBytes: 40 * 1024 * 1024 + 1 });
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(
            JSON.stringify({ packages: [oversized] }),
            { status: 200, headers: { 'Content-Type': 'application/json' } },
        )));

        await expect(getOfflineMapCatalog()).rejects.toThrow('مقدار sizeBytes');
    });

    it('checks the package and index digest before storing tile bytes', async () => {
        const tileBytes = new Uint8Array([1, 2, 3, 4]);
        const { entry, indexBytes } = signedIndex(packageEntry(), tileBytes);
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(new Response(indexBytes, {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }))
            .mockResolvedValueOnce(new Response(tileBytes, {
                status: 200,
                headers: { 'Content-Type': 'image/png' },
            })));

        await installOfflineMap(entry);

        expect(cache.put).toHaveBeenCalledOnce();
        expect(storage.saveTilePackage).toHaveBeenCalledWith(expect.objectContaining({
            id: entry.id,
            version: entry.version,
            tileKeys: ['1/1/1'],
        }));
        expect(cacheStorage.delete).not.toHaveBeenCalled();
    });

    it('rejects a tile that exceeds its manifest size before caching it', async () => {
        const tileBytes = new Uint8Array([1, 2, 3, 4]);
        const { entry, indexBytes } = signedIndex(packageEntry(), tileBytes);
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(new Response(indexBytes, {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }))
            .mockResolvedValueOnce(new Response(new Uint8Array([1, 2, 3, 4, 5]), {
                status: 200,
                headers: { 'Content-Type': 'image/png', 'Content-Length': '5' },
            })));

        await expect(installOfflineMap(entry)).rejects.toThrow('از سقف حجم مجاز بیشتر است');
        expect(cache.put).not.toHaveBeenCalled();
        expect(cacheStorage.delete).toHaveBeenCalledOnce();
    });

    it('rejects tile data whose SHA-256 differs from the package index', async () => {
        const tileBytes = new Uint8Array([1, 2, 3, 4]);
        const tamperedBytes = new Uint8Array([1, 2, 3, 5]);
        const { entry, indexBytes } = signedIndex(packageEntry(), tileBytes);
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(new Response(indexBytes, {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }))
            .mockResolvedValueOnce(new Response(tamperedBytes, {
                status: 200,
                headers: { 'Content-Type': 'image/png' },
            })));

        await expect(installOfflineMap(entry)).rejects.toThrow('اعتبارسنجی کاشی 1/1/1 ناموفق بود');
        expect(cache.put).not.toHaveBeenCalled();
        expect(cacheStorage.delete).toHaveBeenCalledOnce();
    });

    it('refuses to install a package after its data-validity date', async () => {
        const expired = packageEntry({
            validUntil: new Date(Date.now() - 1).toISOString(),
        });
        vi.stubGlobal('fetch', vi.fn());

        await expect(installOfflineMap(expired)).rejects.toThrow('اعتبار دادهٔ این بستهٔ نقشه پایان یافته است');
        expect(fetch).not.toHaveBeenCalled();
        expect(cacheStorage.open).not.toHaveBeenCalled();
    });
});
