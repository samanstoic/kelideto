import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createOfflineMapManager } from '../../resources/js/vanilla/location/offline-map-manager.js';

const serviceMocks = vi.hoisted(() => ({
    catalog: vi.fn(),
    installed: vi.fn(),
    install: vi.fn(),
    remove: vi.fn(),
    totalSize: vi.fn(),
    installedPackages: [],
}));

vi.mock('../../resources/js/services/OfflineMapService.js', () => ({
    getOfflineMapCatalog: serviceMocks.catalog,
    getStoredOfflinePackages: serviceMocks.installed,
    installOfflineMap: serviceMocks.install,
    removeOfflineMap: serviceMocks.remove,
}));

vi.mock('../../resources/js/services/OfflineStorageService.js', () => ({
    getTotalPackagesSize: serviceMocks.totalSize,
}));

const packageEntry = {
    id: 'tehran-core',
    name: 'مرکز تهران',
    regionLabel: 'تهران',
    centerLat: 35.6892,
    centerLng: 51.389,
    zoomMin: 12,
    zoomMax: 15,
    boundsSW: [35.5, 51.2],
    boundsNE: [35.9, 51.6],
    tileCount: 1200,
    sizeBytes: 1_500_000,
    version: 1,
    source: 'Geofabrik OSM regional extract',
    sourceUrl: 'https://download.geofabrik.de/asia/iran.html',
    license: 'ODbL-1.0',
    licenseUrl: 'https://www.openstreetmap.org/copyright',
    attribution: '© OpenStreetMap contributors',
    dataUpdatedAt: '2026-01-01T00:00:00.000Z',
    validUntil: '2030-01-01T00:00:00.000Z',
    indexUrl: 'https://kelideto.com/offline-maps/tehran-core-v1/index.json',
    indexSizeBytes: 1024,
    indexSha256: 'a'.repeat(64),
};

describe('vanilla offline map package manager', () => {
    let manager;

    beforeEach(() => {
        serviceMocks.installedPackages = [];
        serviceMocks.catalog.mockResolvedValue([packageEntry]);
        serviceMocks.installed.mockImplementation(async () => [...serviceMocks.installedPackages]);
        serviceMocks.totalSize.mockReturnValue('۰ بایت');
        serviceMocks.install.mockImplementation(async (entry, onProgress) => {
            onProgress?.({
                completed: entry.tileCount,
                total: entry.tileCount,
                bytesDownloaded: entry.sizeBytes,
                totalBytes: entry.sizeBytes,
            });
            serviceMocks.installedPackages = [{
                ...entry,
                tileKeys: [],
                cacheName: 'keypro-map-tiles-tehran-core-v1',
            }];
        });
        serviceMocks.remove.mockImplementation(async (id) => {
            serviceMocks.installedPackages = serviceMocks.installedPackages.filter((pkg) => pkg.id !== id);
        });
        vi.clearAllMocks();
    });

    afterEach(() => {
        manager?.destroy();
        manager = undefined;
        document.body.replaceChildren();
    });

    it('reports missing distributor catalogs and never fabricates packages', async () => {
        serviceMocks.catalog.mockRejectedValue(new Error('فهرست بسته‌های نقشه در دسترس نیست.'));
        serviceMocks.installed.mockResolvedValue([]);
        manager = createOfflineMapManager();
        document.body.append(manager.element);

        await vi.waitFor(() => expect(manager.element.querySelector('[role="alert"]')).not.toBeNull());
        expect(manager.element.textContent).toContain('فهرست بسته‌های نقشه در دسترس نیست.');
        expect(manager.element.textContent).toContain('هیچ بسته آفلاینی نصب نشده');
        expect(manager.element.querySelectorAll('.kp-vanilla-package-card')).toHaveLength(0);
    });

    it('shows package validity and prevents installing expired map data', async () => {
        serviceMocks.catalog.mockResolvedValue([{
            ...packageEntry,
            validUntil: '2020-01-01T00:00:00.000Z',
        }]);
        manager = createOfflineMapManager();
        document.body.append(manager.element);

        await vi.waitFor(() => expect(manager.element.querySelector('.kp-vanilla-package-card')).not.toBeNull());
        const button = manager.element.querySelector('.kp-vanilla-package-card button');
        expect(button.disabled).toBe(true);
        expect(button.textContent).toContain('اعتبار داده پایان یافته');
        expect(manager.element.textContent).toContain('اعتبار داده تا:');
    });

    it('shows ODbL attribution, installs from the existing same-origin package service, and removes packages', async () => {
        manager = createOfflineMapManager();
        document.body.append(manager.element);
        await vi.waitFor(() => expect(manager.element.querySelector('.kp-vanilla-package-card')).not.toBeNull());

        const card = manager.element.querySelector('.kp-vanilla-package-card');
        expect(card.textContent).toContain('مرکز تهران');
        expect(card.textContent).toContain('OpenStreetMap contributors');
        expect(card.querySelector('a[href="https://www.openstreetmap.org/copyright"]')?.rel).toContain('noreferrer');
        expect(card.querySelector('a[href="https://download.geofabrik.de/asia/iran.html"]')?.textContent)
            .toBe('Geofabrik OSM regional extract');
        expect(card.querySelector('.kp-vanilla-package-attribution').textContent).toContain('OpenStreetMap');
        expect(card.textContent).toContain('۱٬۲۰۰');

        card.querySelector('button').click();
        await vi.waitFor(() => expect(serviceMocks.install).toHaveBeenCalledWith(
            packageEntry,
            expect.any(Function),
        ));
        await vi.waitFor(() => expect(manager.element.textContent).toContain('۱ بسته نصب‌شده'));
        expect(manager.element.textContent).toContain('با موفقیت نصب شد');

        manager.element.querySelector('.kp-vanilla-package-card button').click();
        await vi.waitFor(() => expect(serviceMocks.remove).toHaveBeenCalledWith('tehran-core'));
        await vi.waitFor(() => expect(manager.element.textContent).toContain('هیچ بسته آفلاینی نصب نشده'));
    });
});
