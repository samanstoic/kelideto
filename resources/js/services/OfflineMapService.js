import { deleteTilePackage, getAllTilePackages, getTilePackage, saveTilePackage, } from './OfflineStorageService.js';
const CATALOG_URL = '/offline-maps/catalog.json';
const CACHE_PREFIX = 'keypro-map-tiles-';
const MAX_PACKAGE_BYTES = 40 * 1024 * 1024;
const MAX_PACKAGE_TILES = 50_000;
const MAX_INDEX_BYTES = 8 * 1024 * 1024;
const MAX_CATALOG_BYTES = 1024 * 1024;
const MAX_CATALOG_PACKAGES = 500;
function fail(message) {
    throw new Error(message);
}
function isCoordinate(value, min, max) {
    return typeof value === 'number' && Number.isFinite(value) && value >= min && value <= max;
}
function validateSameOriginUrl(value, field) {
    if (typeof value !== 'string') {
        return fail(`نشانی ${field} معتبر نیست.`);
    }
    let url;
    try {
        url = new URL(value, window.location.origin);
    }
    catch {
        return fail(`نشانی ${field} معتبر نیست.`);
    }
    if (url.origin !== window.location.origin || !['http:', 'https:'].includes(url.protocol)) {
        return fail(`نشانی ${field} باید از توزیع‌کنندهٔ بسته‌های همین سامانه باشد.`);
    }
    return url.href;
}
function validateCatalogEntry(value) {
    if (!value || typeof value !== 'object') {
        return fail('اطلاعات بستهٔ نقشه ساختار معتبری ندارد.');
    }
    const entry = value;
    const numericFields = [
        ['centerLat', -90, 90],
        ['centerLng', -180, 180],
        ['zoomMin', 0, 19],
        ['zoomMax', 0, 19],
        ['tileCount', 1, MAX_PACKAGE_TILES],
        ['sizeBytes', 1, MAX_PACKAGE_BYTES],
        ['version', 1, Number.MAX_SAFE_INTEGER],
        ['indexSizeBytes', 1, MAX_INDEX_BYTES],
    ];
    for (const [field, min, max] of numericFields) {
        if (!isCoordinate(entry[field], min, max)) {
            return fail(`مقدار ${field} در بستهٔ نقشه معتبر نیست.`);
        }
    }
    if (typeof entry.id !== 'string' || !/^[a-z0-9][a-z0-9-]{0,63}$/.test(entry.id) ||
        typeof entry.name !== 'string' || !entry.name.trim() ||
        typeof entry.regionLabel !== 'string' || !entry.regionLabel.trim() ||
        typeof entry.source !== 'string' || !entry.source.trim() ||
        typeof entry.license !== 'string' || entry.license !== 'ODbL-1.0' ||
        typeof entry.attribution !== 'string' || !entry.attribution.includes('OpenStreetMap contributors') ||
        typeof entry.dataUpdatedAt !== 'string' || !Number.isFinite(Date.parse(entry.dataUpdatedAt)) ||
        typeof entry.validUntil !== 'string' || !Number.isFinite(Date.parse(entry.validUntil)) ||
        Date.parse(entry.validUntil) <= Date.parse(entry.dataUpdatedAt) ||
        Date.parse(entry.dataUpdatedAt) > Date.now() ||
        !Number.isSafeInteger(entry.version) ||
        !Number.isInteger(entry.zoomMin) || !Number.isInteger(entry.zoomMax) ||
        !Number.isInteger(entry.tileCount) ||
        !Number.isInteger(entry.sizeBytes) ||
        !Number.isInteger(entry.indexSizeBytes) ||
        entry.sizeBytes + entry.indexSizeBytes > MAX_PACKAGE_BYTES ||
        !/^[a-f0-9]{64}$/i.test(entry.indexSha256) ||
        !Array.isArray(entry.boundsSW) || !Array.isArray(entry.boundsNE) ||
        entry.boundsSW.length !== 2 || entry.boundsNE.length !== 2 ||
        !isCoordinate(entry.boundsSW[0], -90, 90) || !isCoordinate(entry.boundsSW[1], -180, 180) ||
        !isCoordinate(entry.boundsNE[0], -90, 90) || !isCoordinate(entry.boundsNE[1], -180, 180) ||
        entry.boundsSW[0] >= entry.boundsNE[0] || entry.boundsSW[1] >= entry.boundsNE[1] ||
        Number(entry.zoomMin) > Number(entry.zoomMax)) {
        return fail('مشخصات یا مجوز بستهٔ نقشه معتبر نیست.');
    }
    try {
        const sourceUrl = new URL(String(entry.sourceUrl));
        const licenseUrl = new URL(String(entry.licenseUrl));
        if (sourceUrl.protocol !== 'https:' || licenseUrl.protocol !== 'https:') {
            return fail('منبع و نشانی مجوز بسته باید HTTPS باشند.');
        }
        if (sourceUrl.hostname === 'tile.openstreetmap.org' ||
            sourceUrl.hostname.endsWith('.tile.openstreetmap.org')) {
            return fail('سرور عمومی کاشی OpenStreetMap برای تأمین بستهٔ آفلاین مجاز نیست.');
        }
        if (Date.parse(entry.dataUpdatedAt) > Date.now()) {
            return fail('تاریخ به‌روزرسانی دادهٔ بسته معتبر نیست.');
        }
    }
    catch {
        return fail('نشانی منبع یا مجوز بسته معتبر نیست.');
    }
    return {
        ...entry,
        indexUrl: validateSameOriginUrl(entry.indexUrl, 'فهرست کاشی‌ها'),
    };
}
function tileCacheName(pkg) {
    return pkg.cacheName ?? `${CACHE_PREFIX}${pkg.id}-v${pkg.version}`;
}
export function tileKey(z, x, y) {
    return `${z}/${x}/${y}`;
}
export function offlineTileRequestUrl(pkg, z, x, y) {
    return new URL(`/__keypro_offline_tile__/${pkg.id}/v${pkg.version}/${z}/${x}/${y}`, window.location.origin).href;
}
async function readResponseBytes(response, maxBytes, description) {
    const contentLength = Number(response.headers.get('content-length'));
    if (Number.isFinite(contentLength) && contentLength > maxBytes) {
        throw new Error(`${description} از سقف حجم مجاز بیشتر است.`);
    }

    if (!response.body?.getReader) {
        const bytes = new Uint8Array(await response.arrayBuffer());
        if (bytes.byteLength > maxBytes) {
            throw new Error(`${description} از سقف حجم مجاز بیشتر است.`);
        }
        return bytes;
    }

    const reader = response.body.getReader();
    const chunks = [];
    let totalBytes = 0;
    try {
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            totalBytes += value.byteLength;
            if (totalBytes > maxBytes) {
                await reader.cancel();
                throw new Error(`${description} از سقف حجم مجاز بیشتر است.`);
            }
            chunks.push(value);
        }
    } finally {
        reader.releaseLock();
    }

    const bytes = new Uint8Array(totalBytes);
    let offset = 0;
    for (const chunk of chunks) {
        bytes.set(chunk, offset);
        offset += chunk.byteLength;
    }
    return bytes;
}

export async function getOfflineMapCatalog() {
    const response = await fetch(CATALOG_URL, {
        cache: 'no-store',
        headers: { Accept: 'application/json' },
    });
    if (!response.ok) {
        throw new Error('فهرست بسته‌های نقشه در دسترس نیست. توزیع‌کنندهٔ مجاز نقشه پیکربندی نشده است.');
    }
    const contentType = response.headers.get('content-type') ?? '';
    if (!contentType.toLowerCase().includes('application/json')) {
        throw new Error('فهرست بسته‌های نقشهٔ آفلاین روی این میزبان پیکربندی نشده است.');
    }
    const bytes = await readResponseBytes(response, MAX_CATALOG_BYTES, 'فهرست بسته‌های نقشه');
    let data;
    try {
        data = JSON.parse(new TextDecoder().decode(bytes));
    } catch {
        throw new Error('پاسخ فهرست بسته‌های نقشه JSON معتبر نیست.');
    }
    if (!data || typeof data !== 'object' || !Array.isArray(data.packages) ||
        data.packages.length > MAX_CATALOG_PACKAGES) {
        throw new Error('فهرست بسته‌های نقشه ساختار معتبری ندارد.');
    }
    const entries = data.packages.map(validateCatalogEntry);
    if (new Set(entries.map((entry) => entry.id)).size !== entries.length) {
        throw new Error('شناسهٔ تکراری در فهرست بسته‌های نقشه وجود دارد.');
    }
    return entries;
}
function validateTileIndex(value, pkg) {
    if (!value || typeof value !== 'object' || !Array.isArray(value.tiles)) {
        return fail('فهرست کاشی‌های بسته معتبر نیست.');
    }
    const tiles = value.tiles;
    if (tiles.length !== pkg.tileCount || tiles.length > MAX_PACKAGE_TILES) {
        return fail('تعداد کاشی‌های بسته با مشخصات آن مطابقت ندارد.');
    }
    const seen = new Set();
    let totalBytes = 0;
    const validated = tiles.map((value) => {
        if (!value || typeof value !== 'object') {
            return fail('رکورد کاشی معتبر نیست.');
        }
        const tile = value;
        const tileBounds = (zoom, x, y) => {
            const side = 2 ** zoom;
            const longitude = (tileX) => tileX / side * 360 - 180;
            const latitude = (tileY) => Math.atan(Math.sinh(Math.PI * (1 - 2 * tileY / side))) * 180 / Math.PI;
            return [latitude(y + 1), longitude(x), latitude(y), longitude(x + 1)];
        };
        if (!Number.isInteger(tile.z) || tile.z < pkg.zoomMin || tile.z > pkg.zoomMax ||
            !Number.isInteger(tile.x) || tile.x < 0 || tile.x >= 2 ** tile.z ||
            !Number.isInteger(tile.y) || tile.y < 0 || tile.y >= 2 ** tile.z ||
            !Number.isInteger(tile.sizeBytes) || tile.sizeBytes < 1 ||
            !/^[a-f0-9]{64}$/i.test(tile.sha256)) {
            return fail('مختصات یا چکیدهٔ امنیتی یک کاشی معتبر نیست.');
        }
        const [south, west, north, east] = tileBounds(tile.z, tile.x, tile.y);
        if (north < pkg.boundsSW[0] || south > pkg.boundsNE[0] ||
            east < pkg.boundsSW[1] || west > pkg.boundsNE[1]) {
            return fail('یک کاشی خارج از محدودهٔ جغرافیایی اعلام‌شده قرار دارد.');
        }
        const key = tileKey(tile.z, tile.x, tile.y);
        if (seen.has(key)) {
            return fail('فهرست بسته شامل کاشی تکراری است.');
        }
        seen.add(key);
        totalBytes += tile.sizeBytes;
        return {
            ...tile,
            url: validateSameOriginUrl(tile.url, 'کاشی'),
        };
    });
    if (totalBytes !== pkg.sizeBytes || totalBytes > MAX_PACKAGE_BYTES) {
        return fail('حجم اعلام‌شدهٔ بسته با مجموع اندازهٔ کاشی‌ها مطابقت ندارد.');
    }
    return validated;
}
async function sha256(bytes) {
    if (!crypto.subtle) {
        throw new Error('مرورگر امن برای اعتبارسنجی بستهٔ نقشه پشتیبانی نمی‌شود.');
    }
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
}
export async function installOfflineMap(entry, onProgress) {
    const pkg = validateCatalogEntry(entry);
    if (Date.parse(pkg.validUntil) <= Date.now()) {
        throw new Error('اعتبار دادهٔ این بستهٔ نقشه پایان یافته است؛ بستهٔ به‌روز را انتخاب کنید.');
    }
    if (!('caches' in window)) {
        throw new Error('ذخیره‌سازی کاشی آفلاین در این مرورگر پشتیبانی نمی‌شود.');
    }
    if (navigator.storage?.estimate) {
        const estimate = await navigator.storage.estimate();
        const requiredBytes = pkg.sizeBytes + pkg.indexSizeBytes;
        if (estimate.quota && estimate.usage !== undefined &&
            estimate.quota - estimate.usage < requiredBytes) {
            throw new Error('فضای ذخیره‌سازی دستگاه برای نصب این بسته کافی نیست.');
        }
    }
    const indexResponse = await fetch(pkg.indexUrl, {
        cache: 'no-store',
        headers: { Accept: 'application/json' },
    });
    if (!indexResponse.ok) {
        throw new Error(`فهرست بستهٔ «${pkg.name}» دریافت نشد (HTTP ${indexResponse.status}).`);
    }
    const indexBytes = await readResponseBytes(indexResponse, MAX_INDEX_BYTES, 'فهرست کاشی‌های بسته');
    if (indexBytes.byteLength !== pkg.indexSizeBytes ||
        (await sha256(indexBytes)).toLowerCase() !== pkg.indexSha256.toLowerCase()) {
        throw new Error('یکپارچگی فهرست کاشی‌های بستهٔ نقشه تأیید نشد.');
    }
    let indexData;
    try {
        indexData = JSON.parse(new TextDecoder().decode(indexBytes));
    } catch {
        throw new Error('فهرست کاشی‌های بسته JSON معتبر نیست.');
    }
    const tiles = validateTileIndex(indexData, pkg);
    const oldPackage = await getTilePackage(pkg.id);
    const cacheName = `${CACHE_PREFIX}staging-${pkg.id}-${crypto.randomUUID()}`;
    const cache = await caches.open(cacheName);
    let bytesDownloaded = 0;
    try {
        for (let index = 0; index < tiles.length; index += 1) {
            const tile = tiles[index];
            const response = await fetch(tile.url, { cache: 'no-store' });
            if (!response.ok) {
                throw new Error(`کاشی ${tileKey(tile.z, tile.x, tile.y)} دریافت نشد (HTTP ${response.status}).`);
            }
            const bytes = await readResponseBytes(response, tile.sizeBytes, `کاشی ${tileKey(tile.z, tile.x, tile.y)}`);
            const contentType = (response.headers.get('content-type') ?? '').split(';')[0].trim().toLowerCase();
            if (!/^image\/(png|webp|jpeg)$/.test(contentType) || bytes.byteLength !== tile.sizeBytes) {
                throw new Error(`اندازه یا قالب کاشی ${tileKey(tile.z, tile.x, tile.y)} معتبر نیست.`);
            }
            if ((await sha256(bytes)).toLowerCase() !== tile.sha256.toLowerCase()) {
                throw new Error(`اعتبارسنجی کاشی ${tileKey(tile.z, tile.x, tile.y)} ناموفق بود.`);
            }
            const tileResponse = new Response(new Blob([bytes], { type: contentType }), {
                headers: { 'Content-Type': contentType },
            });
            await cache.put(offlineTileRequestUrl(pkg, tile.z, tile.x, tile.y), tileResponse);
            bytesDownloaded += bytes.byteLength;
            onProgress?.({
                completed: index + 1,
                total: tiles.length,
                bytesDownloaded,
                totalBytes: pkg.sizeBytes,
            });
        }
        const metadata = {
            ...pkg,
            cacheName,
            tileKeys: tiles.map((tile) => tileKey(tile.z, tile.x, tile.y)),
            createdAt: oldPackage?.createdAt ?? new Date().toISOString(),
            updatedAt: new Date().toISOString(),
        };
        await saveTilePackage(metadata);
        if (oldPackage && tileCacheName(oldPackage) !== cacheName) {
            await caches.delete(tileCacheName(oldPackage));
        }
    }
    catch (error) {
        await caches.delete(cacheName);
        throw error;
    }
}
export async function removeOfflineMap(id) {
    const pkg = await getTilePackage(id);
    if (!pkg) {
        throw new Error('بستهٔ نقشهٔ موردنظر پیدا نشد.');
    }
    await caches.delete(tileCacheName(pkg));
    await deleteTilePackage(id);
}
export async function findOfflineTile(packages, z, x, y) {
    const key = tileKey(z, x, y);
    for (const pkg of packages) {
        if (pkg.validUntil && Date.parse(pkg.validUntil) <= Date.now()) {
            continue;
        }
        if (pkg.tileKeys?.includes(key)) {
            const cache = await caches.open(tileCacheName(pkg));
            const response = await cache.match(offlineTileRequestUrl(pkg, z, x, y));
            if (response) {
                return response;
            }
        }
    }
    return undefined;
}
export async function getStoredOfflinePackages() {
    return getAllTilePackages();
}
export function validateOfflineCatalogEntry(value) {
    return validateCatalogEntry(value);
}
