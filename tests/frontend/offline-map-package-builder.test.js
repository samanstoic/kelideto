import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import { buildOfflineMapPackage } from '../../scripts/build-offline-map-package.js';

const pngBytes = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j2ioAAAAASUVORK5CYII=',
    'base64',
);

describe('offline map package publishing pipeline', () => {
    let temporaryRoot;
    let bundleIndex = 0;

    async function createBundle(overrides = {}, tileBytes = pngBytes) {
        temporaryRoot ??= await mkdtemp(path.join(os.tmpdir(), 'keypro-map-package-'));
        bundleIndex += 1;
        const sourceDir = path.join(temporaryRoot, `source-${bundleIndex}`);
        const outputDir = path.join(temporaryRoot, `published-${bundleIndex}`);
        await mkdir(path.join(sourceDir, 'tiles', '1', '1'), { recursive: true });
        await writeFile(path.join(sourceDir, 'source.json'), JSON.stringify({
            id: 'test-region',
            name: 'آزمون منطقه',
            regionLabel: 'محدودهٔ آزمایشی',
            centerLat: -40,
            centerLng: 90,
            boundsSW: [-90, 0],
            boundsNE: [0, 180],
            zoomMin: 1,
            zoomMax: 1,
            version: 1,
            source: 'Example licensed raster distributor',
            sourceUrl: 'https://maps.example.org/region',
            license: 'ODbL-1.0',
            licenseUrl: 'https://www.openstreetmap.org/copyright',
            attribution: '© OpenStreetMap contributors',
            distributionAuthorized: true,
            evidenceFile: 'LICENSE-EVIDENCE.txt',
            dataUpdatedAt: '2026-01-01T00:00:00.000Z',
            validUntil: '2030-01-01T00:00:00.000Z',
            ...overrides,
        }));
        await writeFile(path.join(sourceDir, 'LICENSE-EVIDENCE.txt'), 'Distribution rights verified for the test bundle.');
        await writeFile(path.join(sourceDir, 'tiles', '1', '1', '1.png'), tileBytes);
        return { sourceDir, outputDir };
    }

    afterEach(async () => {
        if (temporaryRoot) {
            await rm(temporaryRoot, { recursive: true, force: true });
            temporaryRoot = undefined;
        }
        bundleIndex = 0;
    });

    it('publishes versioned tiles with exact size and SHA-256 integrity metadata', async () => {
        const { sourceDir, outputDir } = await createBundle();
        const result = await buildOfflineMapPackage({ sourceDir, outputDir });
        const catalog = JSON.parse(await readFile(path.join(outputDir, 'catalog.json'), 'utf8'));
        const indexPath = path.join(outputDir, 'test-region', 'v1', 'index.json');
        const indexBytes = await readFile(indexPath);
        const index = JSON.parse(indexBytes);
        const tilePath = path.join(outputDir, 'test-region', 'v1', 'tiles', '1', '1', '1.png');

        expect(result).toMatchObject({
            id: 'test-region',
            version: 1,
            tileCount: 1,
            sizeBytes: pngBytes.byteLength,
            indexUrl: '/offline-maps/test-region/v1/index.json',
        });
        expect(catalog.packages).toHaveLength(1);
        expect(index.tiles[0]).toMatchObject({
            z: 1,
            x: 1,
            y: 1,
            sizeBytes: pngBytes.byteLength,
            url: '/offline-maps/test-region/v1/tiles/1/1/1.png',
        });
        expect(await readFile(tilePath)).toEqual(pngBytes);
        expect(result.indexSha256).toMatch(/^[a-f0-9]{64}$/);
        await expect(readFile(path.join(outputDir, 'test-region', 'v1', 'LICENSE-EVIDENCE.txt')))
            .rejects.toMatchObject({ code: 'ENOENT' });

        const sourceManifest = JSON.parse(await readFile(path.join(sourceDir, 'source.json'), 'utf8'));
        sourceManifest.version = 2;
        await writeFile(path.join(sourceDir, 'source.json'), JSON.stringify(sourceManifest));
        await buildOfflineMapPackage({ sourceDir, outputDir });
        const updatedCatalog = JSON.parse(await readFile(path.join(outputDir, 'catalog.json'), 'utf8'));
        expect(updatedCatalog.packages).toHaveLength(1);
        expect(updatedCatalog.packages[0].version).toBe(2);
        expect(await readFile(path.join(outputDir, 'test-region', 'v1', 'index.json'))).toBeTruthy();
    });

    it('rejects public OSM tile harvesting and packages beyond the fixed byte ceiling', async () => {
        const prohibited = await createBundle({
            sourceUrl: 'https://a.tile.openstreetmap.org/{z}/{x}/{y}.png',
        });
        await expect(buildOfflineMapPackage(prohibited)).rejects.toThrow('public OpenStreetMap tile service');

        const tooLarge = await createBundle({}, Buffer.concat([
            Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
            Buffer.alloc(40 * 1024 * 1024),
        ]));
        await expect(buildOfflineMapPackage(tooLarge)).rejects.toThrow('exceeds the 41943040 byte limit');
    });

    it('requires publisher authorization evidence and refuses to overwrite an existing version', async () => {
        const unlicensed = await createBundle({ license: 'Unknown-License' });
        await expect(buildOfflineMapPackage(unlicensed)).rejects.toThrow('Only reviewed ODbL-1.0');

        const unauthorized = await createBundle({ distributionAuthorized: false });
        await expect(buildOfflineMapPackage(unauthorized)).rejects.toThrow('attest that redistribution is authorized');

        const authorized = await createBundle();
        await buildOfflineMapPackage(authorized);
        await expect(buildOfflineMapPackage(authorized)).rejects.toThrow('publish a new version instead');
    });
});
