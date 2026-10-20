import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createRouter } from '../../resources/js/vanilla/router/router.js';
import { renderNotFound, routes } from '../../resources/js/vanilla/router/routes.js';
import { createAppState } from '../../resources/js/vanilla/state/app-state.js';

const mocks = vi.hoisted(() => ({
    drafts: [],
    locations: [],
    currentSelection: undefined,
    mapController: undefined,
    offlineManager: undefined,
    syncAllDrafts: vi.fn(),
    registerAutoSync: vi.fn(),
    saveDraftBooking: vi.fn(),
    saveLocation: vi.fn(),
    saveCurrentSelection: vi.fn(),
    deleteDraftBooking: vi.fn(),
    deleteLocation: vi.fn(),
    getAllDraftBookings: vi.fn(),
    getSavedLocations: vi.fn(),
    getCurrentSelection: vi.fn(),
    generateIdempotencyKey: vi.fn(),
}));

vi.mock('../../resources/js/services/OfflineStorageService.js', () => ({
    deleteDraftBooking: mocks.deleteDraftBooking,
    deleteLocation: mocks.deleteLocation,
    generateIdempotencyKey: mocks.generateIdempotencyKey,
    getAllDraftBookings: mocks.getAllDraftBookings,
    getCurrentSelection: mocks.getCurrentSelection,
    getSavedLocations: mocks.getSavedLocations,
    saveCurrentSelection: mocks.saveCurrentSelection,
    saveDraftBooking: mocks.saveDraftBooking,
    saveLocation: mocks.saveLocation,
}));

vi.mock('../../resources/js/services/OfflineSyncService.js', () => ({
    registerAutoSync: mocks.registerAutoSync,
    syncAllDrafts: mocks.syncAllDrafts,
}));

vi.mock('../../resources/js/vanilla/location/location-map.js', () => ({
    createLocationMapElements() {
        const root = document.createElement('section');
        root.className = 'kp-vanilla-location-map';
        const coordinates = document.createElement('section');
        const latitudeLabel = document.createElement('span');
        latitudeLabel.textContent = 'عرض جغرافیایی (Latitude)';
        const latitude = document.createElement('p');
        latitude.dir = 'ltr';
        const longitudeLabel = document.createElement('span');
        longitudeLabel.textContent = 'طول جغرافیایی (Longitude)';
        const longitude = document.createElement('p');
        longitude.dir = 'ltr';
        coordinates.append(latitudeLabel, latitude, longitudeLabel, longitude);
        root.append(coordinates);
        return { root, latitude, longitude };
    },
    createLocationMap(_container, controls, { initialLocation, onLocationChange }) {
        let location = initialLocation;
        controls.latitude.textContent = location?.lat?.toFixed(7) ?? '—';
        controls.longitude.textContent = location?.lng?.toFixed(7) ?? '—';
        const controller = {
            getLocation: () => location ? { ...location } : undefined,
            setLocation: (point) => {
                location = { ...point };
                onLocationChange({ ...location });
            },
            invalidateSize: vi.fn(),
            refreshOfflinePackages: vi.fn(),
            destroy: vi.fn(),
        };
        mocks.mapController = controller;
        mocks.onMapLocationChange = (point) => {
            location = { ...point };
            onLocationChange({ ...location });
        };
        return controller;
    },
}));

vi.mock('../../resources/js/vanilla/location/offline-map-manager.js', () => ({
    createOfflineMapManager() {
        const element = document.createElement('section');
        element.textContent = 'مدیریت بسته‌های نقشه آفلاین';
        const manager = { element, destroy: vi.fn() };
        mocks.offlineManager = manager;
        return manager;
    },
}));

describe('vanilla location page', () => {
    let root;
    let router;

    function mount() {
        history.replaceState({}, '', '/location?frontend=vanilla');
        root = document.createElement('div');
        root.id = 'vanilla-app';
        document.body.replaceChildren(root);
        router = createRouter({
            root,
            routes,
            renderNotFound,
            state: createAppState(),
        });
    }

    beforeEach(() => {
        mocks.drafts = [];
        mocks.locations = [];
        mocks.currentSelection = undefined;
        mocks.mapController = undefined;
        mocks.offlineManager = undefined;
        mocks.getAllDraftBookings.mockImplementation(async () => [...mocks.drafts]);
        mocks.getSavedLocations.mockImplementation(async () => [...mocks.locations]);
        mocks.getCurrentSelection.mockImplementation(async () => mocks.currentSelection);
        mocks.generateIdempotencyKey.mockReturnValue('draft-idempotency-key');
        mocks.saveDraftBooking.mockImplementation(async (draft) => {
            mocks.drafts = [...mocks.drafts, draft];
        });
        mocks.saveLocation.mockImplementation(async (location) => {
            mocks.locations = [...mocks.locations, location];
        });
        mocks.saveCurrentSelection.mockResolvedValue(undefined);
        mocks.deleteDraftBooking.mockImplementation(async (key) => {
            mocks.drafts = mocks.drafts.filter((draft) => draft.idempotencyKey !== key);
        });
        mocks.deleteLocation.mockImplementation(async (id) => {
            mocks.locations = mocks.locations.filter((location) => location.id !== id);
        });
        mocks.registerAutoSync.mockReturnValue(vi.fn());
        mocks.syncAllDrafts.mockResolvedValue({ total: 0, synced: 0, failed: 0, errors: [] });
        vi.clearAllMocks();
        mount();
    });

    afterEach(() => {
        router?.destroy();
        router = undefined;
        document.body.replaceChildren();
        history.replaceState({}, '', '/');
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('mounts semantic RTL map controls and displays explicit coordinates', () => {
        expect(root.querySelector('main.kp-vanilla-location-page')).not.toBeNull();
        expect(root.querySelector('nav[aria-label="بخش‌های موقعیت و نقشه"]')).not.toBeNull();
        expect(root.querySelector('#kp-location-map')).not.toBeNull();
        expect(root.textContent).toContain('عرض جغرافیایی (Latitude)');
        expect(root.textContent).toContain('طول جغرافیایی (Longitude)');
        expect(root.querySelector('[dir="ltr"]')).not.toBeNull();
    });

    it('invalidates the map size after the page has been mounted', async () => {
        await vi.waitFor(() => expect(mocks.mapController.invalidateSize).toHaveBeenCalledOnce());
    });

    it('stores a selected point as a local draft with the existing idempotency key field', async () => {
        mocks.onMapLocationChange({
            lat: 35.7,
            lng: 51.4,
            address: 'تهران، خیابان نمونه',
        });
        root.querySelector('#draft-notes').value = 'قفل درب ورودی';
        root.querySelector('.kp-vanilla-draft-form').dispatchEvent(new Event('submit', {
            bubbles: true,
            cancelable: true,
        }));

        await vi.waitFor(() => expect(mocks.saveDraftBooking).toHaveBeenCalledOnce());
        expect(mocks.saveDraftBooking).toHaveBeenCalledWith(expect.objectContaining({
            idempotencyKey: 'draft-idempotency-key',
            lat: 35.7,
            lng: 51.4,
            address: 'تهران، خیابان نمونه',
            notes: 'قفل درب ورودی',
            synced: false,
        }));
        expect(mocks.syncAllDrafts).not.toHaveBeenCalled();
    });

    it('keeps conflicting drafts reviewable and creates a new revision with a fresh key', async () => {
        router.destroy();
        router = undefined;
        mocks.drafts = [{
            idempotencyKey: 'conflicted-key',
            serviceId: 'pending-selection',
            serviceName: 'درخواست اولیه',
            lat: 35.7,
            lng: 51.4,
            address: 'تهران',
            notes: 'متن قابل بررسی',
            createdAt: '2026-01-01T00:00:00.000Z',
            synced: false,
            conflict: true,
            syncAttempts: 1,
            lastSyncError: 'کلید با محتوای دیگری استفاده شده است.',
        }];
        mocks.generateIdempotencyKey.mockReturnValue('fresh-revision-key');
        mount();
        root.querySelector('.kp-vanilla-location-tab:nth-child(3)').click();

        await vi.waitFor(() => expect(root.textContent).toContain('تعارض؛ نیازمند بررسی'));
        expect(root.textContent).toContain('تعارض؛ نیازمند بررسی');
        root.querySelector('.kp-vanilla-draft-card .kp-btn-secondary').click();

        await vi.waitFor(() => expect(mocks.saveDraftBooking).toHaveBeenCalledOnce());
        expect(mocks.saveDraftBooking).toHaveBeenCalledWith(expect.objectContaining({
            idempotencyKey: 'fresh-revision-key',
            notes: 'متن قابل بررسی',
            conflict: false,
        }));
        expect(mocks.drafts[0].idempotencyKey).toBe('conflicted-key');
    });

    it('does not overwrite an early user selection while persisted location data is loading', async () => {
        router.destroy();
        let resolveCurrentSelection;
        mocks.getCurrentSelection.mockImplementation(() => new Promise((resolve) => {
            resolveCurrentSelection = resolve;
        }));
        vi.useFakeTimers();
        mount();

        mocks.onMapLocationChange({ lat: 12.3, lng: 45.6, address: 'انتخاب جدید' });
        expect(mocks.saveCurrentSelection).not.toHaveBeenCalled();
        resolveCurrentSelection({ lat: 1, lng: 2, address: 'موقعیت قدیمی' });
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();
        vi.advanceTimersByTime(250);
        await Promise.resolve();

        expect(mocks.saveCurrentSelection).toHaveBeenCalledWith({
            lat: 12.3,
            lng: 45.6,
            address: 'انتخاب جدید',
        });
    });

    it('saves and removes a named local location without a backend request', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        mocks.onMapLocationChange({ lat: 34.6, lng: 50.9, address: 'قم' });
        root.querySelector('.kp-vanilla-location-actions button').click();

        await vi.waitFor(() => expect(mocks.saveLocation).toHaveBeenCalledOnce());
        const removeButton = root.querySelector('.kp-vanilla-saved-location .kp-vanilla-delete-button');
        expect(removeButton).not.toBeNull();
        removeButton.click();
        await vi.waitFor(() => expect(mocks.deleteLocation).toHaveBeenCalledOnce());
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('loads the offline package manager, refreshes map tiles on return, and cleans up on route navigation', () => {
        root.querySelector('.kp-vanilla-location-tab:nth-child(2)').click();
        expect(root.textContent).toContain('مدیریت بسته‌های نقشه آفلاین');
        expect(mocks.offlineManager).toBeDefined();
        expect(mocks.mapController.destroy).not.toHaveBeenCalled();
        root.querySelector('.kp-vanilla-location-tab:first-child').click();
        expect(mocks.mapController.refreshOfflinePackages).toHaveBeenCalledOnce();

        router.navigate('/unknown');
        expect(mocks.mapController.destroy).toHaveBeenCalledOnce();
        expect(mocks.offlineManager.destroy).toHaveBeenCalledOnce();
    });
});
