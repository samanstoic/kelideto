import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const storage = vi.hoisted(() => ({
    getUnsyncedDrafts: vi.fn(),
    markDraftSynced: vi.fn(),
    markDraftSyncConflict: vi.fn(),
    markDraftSyncFailed: vi.fn(),
}));

vi.mock('../../resources/js/services/OfflineStorageService.js', () => storage);

import { syncAllDrafts } from '../../resources/js/services/OfflineSyncService.js';

const draft = {
    idempotencyKey: 'draft-idempotency-uuid',
    serviceId: 'res-open-door',
    serviceName: 'بازگشایی درب',
    lat: 35.7,
    lng: 51.4,
    address: 'تهران',
    notes: '',
    phone: '09123456789',
    createdAt: '2026-01-01T00:00:00.000Z',
};

describe('offline draft sync service', () => {
    beforeEach(() => {
        storage.getUnsyncedDrafts.mockResolvedValue([{ ...draft }]);
        storage.markDraftSynced.mockResolvedValue(undefined);
        storage.markDraftSyncFailed.mockResolvedValue(undefined);
        sessionStorage.setItem('keypro_api_token', 'test-token');
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(null, { status: 204 })));
    });

    afterEach(() => {
        sessionStorage.clear();
        vi.unstubAllGlobals();
        vi.clearAllMocks();
    });

    it('reuses the saved idempotency key and existing bearer token on draft sync', async () => {
        const first = await syncAllDrafts();
        const second = await syncAllDrafts();

        expect(first).toEqual({ total: 1, synced: 1, failed: 0, conflicts: 0, errors: [] });
        expect(second).toEqual({ total: 1, synced: 1, failed: 0, conflicts: 0, errors: [] });
        expect(fetch).toHaveBeenCalledTimes(2);
        const [url, options] = fetch.mock.calls[0];
        expect(url).toBe('/api/bookings/draft-sync');
        expect(options.method).toBe('POST');
        expect(options.headers['X-Idempotency-Key']).toBe(draft.idempotencyKey);
        expect(options.headers.Authorization).toBe('Bearer test-token');
        expect(options.headers).not.toHaveProperty('X-User-Id');
        expect(JSON.parse(options.body)).toMatchObject({
            idempotency_key: draft.idempotencyKey,
            service_id: draft.serviceId,
            lat: draft.lat,
            lng: draft.lng,
        });
        expect(storage.markDraftSynced).toHaveBeenCalledTimes(2);
        expect(storage.markDraftSynced).toHaveBeenCalledWith(draft.idempotencyKey);
    });

    it('does not send drafts until an authenticated session is available', async () => {
        sessionStorage.removeItem('keypro_api_token');

        await expect(syncAllDrafts()).rejects.toThrow('ابتدا وارد حساب کاربری شوید');
        expect(fetch).not.toHaveBeenCalled();
    });

    it('records failed synchronization without marking a draft as synced', async () => {
        fetch.mockResolvedValue(new Response(JSON.stringify({ message: 'درخواست نامعتبر است.' }), {
            status: 422,
            headers: { 'Content-Type': 'application/json' },
        }));

        const result = await syncAllDrafts();

        expect(result).toMatchObject({ total: 1, synced: 0, failed: 1, conflicts: 0 });
        expect(result.errors[0].error).toBe('درخواست نامعتبر است.');
        expect(storage.markDraftSyncFailed).toHaveBeenCalledWith(
            draft.idempotencyKey,
            'درخواست نامعتبر است.',
        );
        expect(storage.markDraftSynced).not.toHaveBeenCalled();
    });

    it('marks a 409 idempotency conflict and does not retry it as a normal failure', async () => {
        fetch.mockResolvedValue(new Response(JSON.stringify({ message: 'کلید قبلاً با محتوای دیگری استفاده شده است.' }), {
            status: 409,
            headers: { 'Content-Type': 'application/json' },
        }));

        const result = await syncAllDrafts();

        expect(result).toMatchObject({ total: 1, synced: 0, failed: 0, conflicts: 1 });
        expect(storage.markDraftSyncConflict).toHaveBeenCalledWith(
            draft.idempotencyKey,
            'کلید قبلاً با محتوای دیگری استفاده شده است.',
        );
        expect(storage.markDraftSyncFailed).not.toHaveBeenCalled();
    });
});
