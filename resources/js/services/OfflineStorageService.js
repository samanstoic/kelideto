/**
 * OfflineStorageService — KeyPro Phase 05
 *
 * Provides IndexedDB-based persistent storage for:
 *  1. Offline map package metadata (tile bytes are stored in Cache Storage)
 *  2. Draft booking requests with idempotency keys
 *  3. Saved location pins
 *
 * IndexedDB is chosen over localStorage for structured data with indexes.
 */
const DB_NAME = 'keypro_offline';
const DB_VERSION = 3;
const DRAFT_RETENTION_MS = 7 * 24 * 60 * 60 * 1000;
const CURRENT_SELECTION_RETENTION_MS = 24 * 60 * 60 * 1000;
const SAVED_LOCATION_RETENTION_MS = 30 * 24 * 60 * 60 * 1000;
// ─── Database Initialization ────────────────────────────────────────────
function openDB() {
    return new Promise((resolve, reject) => {
        if (typeof indexedDB === 'undefined') {
            reject(new Error('ذخیره‌سازی آفلاین در این مرورگر پشتیبانی نمی‌شود.'));
            return;
        }
        const request = indexedDB.open(DB_NAME, DB_VERSION);
        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains('tile_packages')) {
                db.createObjectStore('tile_packages', { keyPath: 'id' });
            }
            if (!db.objectStoreNames.contains('draft_bookings')) {
                const store = db.createObjectStore('draft_bookings', { keyPath: 'idempotencyKey' });
                store.createIndex('synced', 'synced', { unique: false });
            } else {
                const store = event.target.transaction.objectStore('draft_bookings');
                if (!store.indexNames.contains('synced')) {
                    store.createIndex('synced', 'synced', { unique: false });
                }
            }
            if (!db.objectStoreNames.contains('saved_locations')) {
                db.createObjectStore('saved_locations', { keyPath: 'id' });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(new Error(`خطا در باز کردن دیتابیس آفلاین: ${request.error?.message}`));
        request.onblocked = () => reject(new Error('ارتقای دیتابیس آفلاین مسدود شده است؛ برگه‌های دیگر برنامه را ببندید.'));
    });
}
// ─── Generic Helpers ────────────────────────────────────────────────────
async function getAll(storeName) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(storeName, 'readonly');
        const store = tx.objectStore(storeName);
        const request = store.getAll();
        let result;
        request.onsuccess = () => {
            result = request.result;
        };
        tx.oncomplete = () => {
            db.close();
            resolve(result);
        };
        tx.onerror = () => {
            db.close();
            reject(new Error(`خطا در خواندن ${storeName}: ${tx.error?.message ?? 'خطای تراکنش'}`));
        };
        tx.onabort = () => {
            db.close();
            reject(new Error(`خواندن ${storeName} لغو شد.`));
        };
    });
}
async function getByKey(storeName, key) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(storeName, 'readonly');
        const store = tx.objectStore(storeName);
        const request = store.get(key);
        let result;
        request.onsuccess = () => {
            result = request.result;
        };
        tx.oncomplete = () => {
            db.close();
            resolve(result);
        };
        tx.onerror = () => {
            db.close();
            reject(new Error(`خطا در خواندن رکورد ${key}: ${tx.error?.message ?? 'خطای تراکنش'}`));
        };
        tx.onabort = () => {
            db.close();
            reject(new Error(`خواندن رکورد ${key} لغو شد.`));
        };
    });
}
async function putItem(storeName, item) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(storeName, 'readwrite');
        const store = tx.objectStore(storeName);
        store.put(item);
        tx.oncomplete = () => {
            db.close();
            resolve();
        };
        tx.onerror = () => {
            db.close();
            reject(new Error(`خطا در ذخیره رکورد در ${storeName}: ${tx.error?.message ?? 'خطای تراکنش'}`));
        };
        tx.onabort = () => {
            db.close();
            reject(new Error(`ذخیره رکورد در ${storeName} لغو شد.`));
        };
    });
}
async function deleteByKey(storeName, key) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(storeName, 'readwrite');
        const store = tx.objectStore(storeName);
        store.delete(key);
        tx.oncomplete = () => {
            db.close();
            resolve();
        };
        tx.onerror = () => {
            db.close();
            reject(new Error(`خطا در حذف رکورد ${key}: ${tx.error?.message ?? 'خطای تراکنش'}`));
        };
        tx.onabort = () => {
            db.close();
            reject(new Error(`حذف رکورد ${key} لغو شد.`));
        };
    });
}
// ─── Tile Package Operations ────────────────────────────────────────────
export async function getAllTilePackages() {
    return getAll('tile_packages');
}
export async function getTilePackage(id) {
    return getByKey('tile_packages', id);
}
export async function saveTilePackage(pkg) {
    return putItem('tile_packages', pkg);
}
export async function deleteTilePackage(id) {
    return deleteByKey('tile_packages', id);
}
export function getTotalPackagesSize(packages) {
    const totalBytes = packages.reduce((sum, p) => sum + p.sizeBytes + (p.indexSizeBytes ?? 0), 0);
    if (totalBytes < 1024)
        return `${totalBytes} بایت`;
    if (totalBytes < 1024 * 1024)
        return `${(totalBytes / 1024).toFixed(1)} کیلوبایت`;
    return `${(totalBytes / (1024 * 1024)).toFixed(1)} مگابایت`;
}
// ─── Draft Booking Operations ───────────────────────────────────────────
export function generateIdempotencyKey() {
    if (typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
export async function saveDraftBooking(draft) {
    return putItem('draft_bookings', {
        ...draft,
        conflict: false,
        syncStatus: draft.synced ? 'synced' : 'queued',
        expiresAt: new Date(Date.now() + DRAFT_RETENTION_MS).toISOString(),
    });
}
export async function getAllDraftBookings() {
    return (await getAll('draft_bookings')).filter((draft) => !isExpired(draft, DRAFT_RETENTION_MS));
}
export async function getUnsyncedDrafts() {
    const all = await getAllDraftBookings();
    const now = Date.now();
    return all.filter((draft) => !draft.synced && !draft.conflict
        && (!Number.isFinite(Date.parse(draft.nextRetryAt)) || Date.parse(draft.nextRetryAt) <= now));
}
export async function markDraftSynced(idempotencyKey) {
    const draft = await getByKey('draft_bookings', idempotencyKey);
    if (draft) {
        draft.synced = true;
        draft.conflict = false;
        draft.syncStatus = 'synced';
        draft.lastSyncError = null;
        draft.nextRetryAt = null;
        await putItem('draft_bookings', draft);
    }
}
export async function markDraftSyncFailed(idempotencyKey, error) {
    const draft = await getByKey('draft_bookings', idempotencyKey);
    if (draft) {
        draft.syncAttempts = (Number(draft.syncAttempts) || 0) + 1;
        draft.syncStatus = 'queued';
        draft.lastSyncError = error;
        const backoffMs = Math.min(60_000 * (2 ** Math.min(draft.syncAttempts - 1, 10)), 24 * 60 * 60 * 1000);
        draft.nextRetryAt = new Date(Date.now() + backoffMs).toISOString();
        await putItem('draft_bookings', draft);
    }
}
export async function markDraftSyncConflict(idempotencyKey, error) {
    const draft = await getByKey('draft_bookings', idempotencyKey);
    if (draft) {
        draft.conflict = true;
        draft.syncStatus = 'conflict';
        draft.lastSyncError = error;
        draft.nextRetryAt = null;
        await putItem('draft_bookings', draft);
    }
}
export async function deleteDraftBooking(idempotencyKey) {
    return deleteByKey('draft_bookings', idempotencyKey);
}
// ─── Saved Location Operations ──────────────────────────────────────────
export async function getSavedLocations() {
    return (await getAll('saved_locations')).filter((location) =>
        location.id !== 'current-selection' && !isExpired(location, SAVED_LOCATION_RETENTION_MS));
}
export async function saveLocation(loc) {
    return putItem('saved_locations', {
        ...loc,
        expiresAt: new Date(Date.now() + SAVED_LOCATION_RETENTION_MS).toISOString(),
    });
}
export async function getCurrentSelection() {
    const current = await getByKey('saved_locations', 'current-selection');
    return current && !isExpired(current, CURRENT_SELECTION_RETENTION_MS) ? current : undefined;
}
export async function saveCurrentSelection(location) {
    return putItem('saved_locations', {
        ...location,
        id: 'current-selection',
        label: 'آخرین نقطهٔ انتخاب‌شده',
        createdAt: new Date().toISOString(),
        expiresAt: new Date(Date.now() + CURRENT_SELECTION_RETENTION_MS).toISOString(),
    });
}
export async function deleteLocation(id) {
    return deleteByKey('saved_locations', id);
}

function isExpired(item, retentionMs) {
    const expiry = Date.parse(item.expiresAt || '');
    if (Number.isFinite(expiry)) return expiry <= Date.now();
    const createdAt = Date.parse(item.createdAt || '');
    return !Number.isFinite(createdAt) || createdAt + retentionMs <= Date.now();
}

export async function purgeExpiredLocalData() {
    const [drafts, locations] = await Promise.all([
        getAll('draft_bookings'),
        getAll('saved_locations'),
    ]);
    const expiredDrafts = drafts.filter((draft) => isExpired(draft, DRAFT_RETENTION_MS));
    const expiredLocations = locations.filter((location) => isExpired(
        location,
        location.id === 'current-selection' ? CURRENT_SELECTION_RETENTION_MS : SAVED_LOCATION_RETENTION_MS,
    ));
    await Promise.all([
        ...expiredDrafts.map((draft) => deleteByKey('draft_bookings', draft.idempotencyKey)),
        ...expiredLocations.map((location) => deleteByKey('saved_locations', location.id)),
    ]);

    return { drafts: expiredDrafts.length, locations: expiredLocations.length };
}
