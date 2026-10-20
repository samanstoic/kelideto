import { createHash, randomUUID } from 'node:crypto';
import { Buffer } from 'node:buffer';
import { error as logError, log } from 'node:console';
import { access, lstat, mkdir, readFile, readdir, rename, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { pathToFileURL, URL } from 'node:url';

const MAX_PACKAGE_BYTES = 40 * 1024 * 1024;
const MAX_PACKAGE_TILES = 50_000;
const MAX_EVIDENCE_BYTES = 1024 * 1024;
const OSM_TILE_HOSTS = new Set(['tile.openstreetmap.org']);

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

function isNumber(value, min, max) {
    return typeof value === 'number' && Number.isFinite(value) && value >= min && value <= max;
}

function tileBounds(z, x, y) {
    const side = 2 ** z;
    const longitude = (tileX) => tileX / side * 360 - 180;
    const latitude = (tileY) => Math.atan(Math.sinh(Math.PI * (1 - 2 * tileY / side))) * 180 / Math.PI;
    return [latitude(y + 1), longitude(x), latitude(y), longitude(x + 1)];
}

function hasImageSignature(bytes, extension) {
    if (extension.toLowerCase() === 'png') {
        return bytes.length >= 8 && bytes.subarray(0, 8).equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]));
    }
    if (extension.toLowerCase() === 'webp') {
        return bytes.length >= 12 && bytes.toString('ascii', 0, 4) === 'RIFF' &&
            bytes.toString('ascii', 8, 12) === 'WEBP';
    }
    return bytes.length >= 4 && bytes[0] === 0xff && bytes[1] === 0xd8 &&
        bytes[bytes.length - 2] === 0xff && bytes[bytes.length - 1] === 0xd9;
}

function validateMetadata(metadata) {
    assert(metadata && typeof metadata === 'object', 'source.json must contain a JSON object.');
    assert(typeof metadata.id === 'string' && /^[a-z0-9][a-z0-9-]{0,63}$/.test(metadata.id), 'Invalid package id.');
    assert(typeof metadata.name === 'string' && metadata.name.trim(), 'Package name is required.');
    assert(typeof metadata.regionLabel === 'string' && metadata.regionLabel.trim(), 'Region label is required.');
    assert(typeof metadata.source === 'string' && metadata.source.trim(), 'Data source name is required.');
    assert(isNumber(metadata.centerLat, -90, 90) && isNumber(metadata.centerLng, -180, 180), 'Invalid package center.');
    assert(Array.isArray(metadata.boundsSW) && Array.isArray(metadata.boundsNE) &&
        metadata.boundsSW.length === 2 && metadata.boundsNE.length === 2 &&
        isNumber(metadata.boundsSW[0], -90, 90) && isNumber(metadata.boundsSW[1], -180, 180) &&
        isNumber(metadata.boundsNE[0], -90, 90) && isNumber(metadata.boundsNE[1], -180, 180) &&
        metadata.boundsSW[0] < metadata.boundsNE[0] && metadata.boundsSW[1] < metadata.boundsNE[1],
    'Invalid package bounds.');
    assert(Number.isInteger(metadata.zoomMin) && Number.isInteger(metadata.zoomMax) &&
        metadata.zoomMin >= 0 && metadata.zoomMax <= 19 && metadata.zoomMin <= metadata.zoomMax,
    'Invalid zoom range.');
    assert(Number.isSafeInteger(metadata.version) && metadata.version > 0, 'Package version must be a positive integer.');
    assert(metadata.license === 'ODbL-1.0', 'Only reviewed ODbL-1.0 source bundles are currently supported.');
    assert(typeof metadata.attribution === 'string' &&
        metadata.attribution.includes('OpenStreetMap contributors'), 'OpenStreetMap attribution is required.');
    assert(metadata.distributionAuthorized === true, 'The publisher must attest that redistribution is authorized.');
    assert(typeof metadata.evidenceFile === 'string' &&
        !path.isAbsolute(metadata.evidenceFile) &&
        !metadata.evidenceFile.split(/[\\/]/).includes('..'), 'A relative license evidence file is required.');
    const sourceUrl = new URL(metadata.sourceUrl);
    const licenseUrl = new URL(metadata.licenseUrl);
    assert(sourceUrl.protocol === 'https:' && licenseUrl.protocol === 'https:', 'Source and license URLs must use HTTPS.');
    assert(!OSM_TILE_HOSTS.has(sourceUrl.hostname) &&
        !sourceUrl.hostname.endsWith('.tile.openstreetmap.org'),
    'Bulk acquisition from the public OpenStreetMap tile service is prohibited.');
    assert(typeof metadata.dataUpdatedAt === 'string' &&
        Number.isFinite(Date.parse(metadata.dataUpdatedAt)), 'A valid dataUpdatedAt timestamp is required.');
    assert(typeof metadata.validUntil === 'string' &&
        Number.isFinite(Date.parse(metadata.validUntil)) &&
        Date.parse(metadata.validUntil) > Date.parse(metadata.dataUpdatedAt) &&
        Date.parse(metadata.validUntil) > Date.now(), 'Package data must have a future validUntil timestamp.');
    return metadata;
}

async function listTiles(tileRoot) {
    const tiles = [];
    async function visit(folder, relative = '') {
        const entries = await readdir(folder, { withFileTypes: true });
        for (const entry of entries) {
            const relativePath = path.join(relative, entry.name);
            const fullPath = path.join(folder, entry.name);
            assert(!entry.isSymbolicLink(), `Symbolic links are not allowed in tile bundles: ${relativePath}`);
            if (entry.isDirectory()) {
                await visit(fullPath, relativePath);
            } else if (entry.isFile()) {
                tiles.push({ fullPath, relativePath });
            } else {
                throw new Error(`Unsupported tile bundle entry: ${relativePath}`);
            }
        }
    }
    await visit(tileRoot);
    assert(tiles.length > 0 && tiles.length <= MAX_PACKAGE_TILES,
        `Tile count must be between 1 and ${MAX_PACKAGE_TILES}.`);
    return tiles;
}

export async function buildOfflineMapPackage({ sourceDir, outputDir }) {
    const sourceRoot = path.resolve(sourceDir);
    const outputRoot = path.resolve(outputDir);
    const metadataPath = path.join(sourceRoot, 'source.json');
    const metadata = validateMetadata(JSON.parse(await readFile(metadataPath, 'utf8')));
    const evidencePath = path.resolve(sourceRoot, metadata.evidenceFile);
    assert(evidencePath.startsWith(`${sourceRoot}${path.sep}`), 'License evidence must remain inside the source bundle.');
    const evidenceInfo = await lstat(evidencePath);
    assert(!evidenceInfo.isSymbolicLink() && evidenceInfo.isFile() &&
        evidenceInfo.size > 0 && evidenceInfo.size <= MAX_EVIDENCE_BYTES,
        'License evidence must be a non-empty file no larger than 1 MiB.');

    const tileRoot = path.join(sourceRoot, 'tiles');
    const tileRootInfo = await lstat(tileRoot);
    assert(!tileRootInfo.isSymbolicLink() && tileRootInfo.isDirectory(),
        'Tile root must be a real directory inside the source bundle.');
    const sourceTiles = await listTiles(tileRoot);
    const id = metadata.id;
    const version = metadata.version;
    const indexPath = `/offline-maps/${id}/v${version}/index.json`;
    const finalPackageDir = path.join(outputRoot, id, `v${version}`);
    await mkdir(outputRoot, { recursive: true });
    try {
        await access(finalPackageDir);
        throw new Error(`Package ${id} version ${version} already exists; publish a new version instead.`);
    } catch (error) {
        if (error.code !== 'ENOENT') throw error;
    }

    const stagingDir = path.join(outputRoot, `.staging-${randomUUID()}`);
    await mkdir(stagingDir, { recursive: true });
    let totalBytes = 0;
    let publishedDirectory = false;
    let temporaryCatalog;
    try {
        const seen = new Set();
        const tiles = [];
        for (const sourceTile of sourceTiles) {
            const normalizedPath = sourceTile.relativePath.split(path.sep).join('/');
            const match = normalizedPath.match(/^(\d+)\/(\d+)\/(\d+)\.(png|webp|jpe?g)$/i);
            assert(match, `Tile must use z/x/y.png, webp, or jpeg layout: ${normalizedPath}`);
            const [, zText, xText, yText, extension] = match;
            const z = Number(zText);
            const x = Number(xText);
            const y = Number(yText);
            assert(Number.isInteger(z) && z >= metadata.zoomMin && z <= metadata.zoomMax &&
                Number.isInteger(x) && x >= 0 && x < 2 ** z &&
                Number.isInteger(y) && y >= 0 && y < 2 ** z,
            `Invalid tile coordinate: ${sourceTile.relativePath}`);
            const [south, west, north, east] = tileBounds(z, x, y);
            assert(!(north < metadata.boundsSW[0] || south > metadata.boundsNE[0] ||
                east < metadata.boundsSW[1] || west > metadata.boundsNE[1]),
            `Tile is outside the declared region: ${sourceTile.relativePath}`);
            const key = `${z}/${x}/${y}`;
            assert(!seen.has(key), `Duplicate tile coordinate: ${key}`);
            seen.add(key);

            const bytes = await readFile(sourceTile.fullPath);
            assert(bytes.length > 0, `Empty tile file: ${sourceTile.relativePath}`);
            assert(hasImageSignature(bytes, extension), `Tile is not a valid ${extension.toUpperCase()} image: ${normalizedPath}`);
            totalBytes += bytes.length;
            assert(totalBytes <= MAX_PACKAGE_BYTES, `Package exceeds the ${MAX_PACKAGE_BYTES} byte limit.`);
            const sha256 = createHash('sha256').update(bytes).digest('hex');
            const targetRelative = path.join('tiles', normalizedPath);
            const targetPath = path.join(stagingDir, targetRelative);
            await mkdir(path.dirname(targetPath), { recursive: true });
            await writeFile(targetPath, bytes, { flag: 'wx' });
            tiles.push({
                z,
                x,
                y,
                sizeBytes: bytes.length,
                sha256,
                url: `${indexPath.replace(/index\.json$/, '')}tiles/${z}/${x}/${y}.${extension.toLowerCase()}`,
            });
        }

        const index = {
            packageId: id,
            version,
            tiles,
        };
        const indexBytes = Buffer.from(`${JSON.stringify(index)}\n`);
        assert(indexBytes.length <= 8 * 1024 * 1024, 'Tile index exceeds the 8 MiB limit.');
        assert(totalBytes + indexBytes.length <= MAX_PACKAGE_BYTES,
            `Package including its index exceeds the ${MAX_PACKAGE_BYTES} byte limit.`);
        await writeFile(path.join(stagingDir, 'index.json'), indexBytes, { flag: 'wx' });
        await mkdir(path.dirname(finalPackageDir), { recursive: true });
        await rename(stagingDir, finalPackageDir);
        publishedDirectory = true;

        let packages = [];
        const catalogPath = path.join(outputRoot, 'catalog.json');
        try {
            const current = JSON.parse(await readFile(catalogPath, 'utf8'));
            assert(Array.isArray(current.packages), 'Existing offline map catalog has an invalid format.');
            packages = current.packages.filter((item) => item.id !== id);
        } catch (error) {
            if (error.code !== 'ENOENT') throw error;
        }

        const catalogEntry = {
            id,
            name: metadata.name,
            regionLabel: metadata.regionLabel,
            centerLat: metadata.centerLat,
            centerLng: metadata.centerLng,
            boundsSW: metadata.boundsSW,
            boundsNE: metadata.boundsNE,
            zoomMin: metadata.zoomMin,
            zoomMax: metadata.zoomMax,
            tileCount: tiles.length,
            sizeBytes: totalBytes,
            version,
            source: metadata.source,
            sourceUrl: metadata.sourceUrl,
            license: metadata.license,
            licenseUrl: metadata.licenseUrl,
            attribution: metadata.attribution,
            dataUpdatedAt: metadata.dataUpdatedAt,
            validUntil: metadata.validUntil,
            indexUrl: indexPath,
            indexSizeBytes: indexBytes.length,
            indexSha256: createHash('sha256').update(indexBytes).digest('hex'),
        };
        const nextCatalog = Buffer.from(`${JSON.stringify({ packages: [...packages, catalogEntry] }, null, 2)}\n`);
        temporaryCatalog = `${catalogPath}.${randomUUID()}.tmp`;
        await writeFile(temporaryCatalog, nextCatalog, { flag: 'wx' });
        await rename(temporaryCatalog, catalogPath);
        temporaryCatalog = undefined;
        publishedDirectory = false;
        return catalogEntry;
    } catch (error) {
        await rm(stagingDir, { recursive: true, force: true });
        if (temporaryCatalog) await rm(temporaryCatalog, { force: true });
        if (publishedDirectory) await rm(finalPackageDir, { recursive: true, force: true });
        throw error;
    }
}

function parseArguments(args) {
    const values = {};
    for (let index = 0; index < args.length; index += 2) {
        const key = args[index];
        const value = args[index + 1];
        assert(['--source', '--output'].includes(key) && value, 'Usage: node scripts/build-offline-map-package.js --source <licensed-bundle> --output <public/offline-maps>');
        values[key] = value;
    }
    assert(values['--source'] && values['--output'],
        'Usage: node scripts/build-offline-map-package.js --source <licensed-bundle> --output <public/offline-maps>');
    return { sourceDir: values['--source'], outputDir: values['--output'] };
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
    try {
        const result = await buildOfflineMapPackage(parseArguments(process.argv.slice(2)));
        log(`Published ${result.id} v${result.version}: ${result.tileCount} tiles, ${result.sizeBytes} bytes.`);
    } catch (caughtError) {
        logError(caughtError instanceof Error ? caughtError.message : 'Offline map package build failed.');
        process.exitCode = 1;
    }
}
