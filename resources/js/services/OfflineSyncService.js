/**
 * OfflineSyncService — KeyPro Phase 05
 *
 * Manages idempotent synchronisation of draft bookings when the device
 * comes back online. Key guarantees:
 *
 *  1. Each draft carries a unique `idempotencyKey`. The server MUST treat
 *     duplicates as no-ops, so repeated sync attempts never create double bookings.
 *  2. Sync is triggered by the `online` event or explicit user action.
 *  3. Failed syncs increment an attempt counter; the draft stays in IndexedDB
 *     until either successfully synced or manually deleted.
 */
import {
    getUnsyncedDrafts,
    markDraftSynced,
    markDraftSyncConflict,
    markDraftSyncFailed,
} from './OfflineStorageService.js';
let syncInFlight;

/**
 * Attempt to sync all unsynced draft bookings to the server.
 * Returns a summary of results.
 */
export function syncAllDrafts() {
    if (syncInFlight) return syncInFlight;
    syncInFlight = performSync().finally(() => {
        syncInFlight = undefined;
    });
    return syncInFlight;
}

async function performSync() {
    if (!sessionStorage.getItem('keypro_api_token')) {
        throw new Error('برای همگام‌سازی امن پیش‌نویس‌ها ابتدا وارد حساب کاربری شوید.');
    }
    if (!navigator.onLine) {
        throw new Error('برای همگام‌سازی پیش‌نویس‌ها اتصال اینترنت لازم است.');
    }
    const drafts = await getUnsyncedDrafts();
    const result = { total: drafts.length, synced: 0, failed: 0, conflicts: 0, errors: [] };
    for (const draft of drafts) {
        try {
            await syncSingleDraft(draft);
            await markDraftSynced(draft.idempotencyKey);
            result.synced += 1;
        }
        catch (err) {
            const errorMsg = err instanceof Error ? err.message : 'خطای ناشناخته';
            if (err instanceof Error && err.status === 409) {
                await markDraftSyncConflict(draft.idempotencyKey, errorMsg);
                result.conflicts += 1;
            } else {
                await markDraftSyncFailed(draft.idempotencyKey, errorMsg);
                result.failed += 1;
            }
            result.errors.push({ idempotencyKey: draft.idempotencyKey, error: errorMsg });
        }
    }
    return result;
}
/**
 * Send a single draft booking to the server.
 * The server should honour the idempotencyKey header/field.
 */
async function syncSingleDraft(draft) {
    const headers = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Idempotency-Key': draft.idempotencyKey,
    };
    const token = sessionStorage.getItem('keypro_api_token');
    if (token) {
        headers.Authorization = `Bearer ${token}`;
    }
    const response = await fetch('/api/bookings/draft-sync', {
        method: 'POST',
        credentials: 'same-origin',
        headers,
        body: JSON.stringify({
            idempotency_key: draft.idempotencyKey,
            service_id: draft.serviceId,
            service_name: draft.serviceName,
            lat: draft.lat,
            lng: draft.lng,
            address: draft.address,
            notes: draft.notes,
            phone: draft.phone,
            created_at: draft.createdAt,
        }),
    });
    if (!response.ok) {
        const responseText = await response.text();
        let message = `خطای سرور (HTTP ${response.status})`;
        if (responseText) {
            try {
                const data = JSON.parse(responseText);
                if (data && typeof data === 'object' && 'message' in data && typeof data.message === 'string') {
                    message = data.message;
                }
            }
            catch {
                message = `پاسخ نامعتبر سرور (HTTP ${response.status})`;
            }
        }
        const error = new Error(message);
        error.status = response.status;
        throw error;
    }
}
/**
 * Registers automatic sync on the `online` event.
 * Returns a cleanup function to remove the listener.
 */
export function registerAutoSync(onSyncComplete, onSyncError, {
    windowRef = window,
    documentRef = document,
    navigatorRef = navigator,
    tokenStorage = sessionStorage,
} = {}) {
    let running = false;
    let retryTimer;
    const handler = async () => {
        if (running || !navigatorRef.onLine || !tokenStorage.getItem('keypro_api_token')) return;
        running = true;
        try {
            const result = await syncAllDrafts();
            onSyncComplete?.(result);
            if (result.failed > 0) {
                windowRef.clearTimeout(retryTimer);
                retryTimer = windowRef.setTimeout(() => void handler(), 60_000);
            }
        }
        catch (error) {
            onSyncError?.(error instanceof Error ? error : new Error('خطای ناشناخته در همگام‌سازی آفلاین.'));
        } finally {
            running = false;
        }
    };
    const onVisible = () => {
        if (documentRef.visibilityState === 'visible') void handler();
    };
    windowRef.addEventListener('online', handler);
    windowRef.addEventListener('keypro:auth-changed', handler);
    windowRef.addEventListener('pageshow', handler);
    documentRef.addEventListener('visibilitychange', onVisible);
    void handler();
    return () => {
        windowRef.clearTimeout(retryTimer);
        windowRef.removeEventListener('online', handler);
        windowRef.removeEventListener('keypro:auth-changed', handler);
        windowRef.removeEventListener('pageshow', handler);
        documentRef.removeEventListener('visibilitychange', onVisible);
    };
}
