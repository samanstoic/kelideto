import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createLocationMap, createLocationMapElements } from '../../resources/js/vanilla/location/location-map.js';

const mapMocks = vi.hoisted(() => ({
    map: undefined,
    marker: undefined,
    mapEvents: {},
    markerEvents: {},
    tileLayers: [],
    gridLayers: [],
    getStoredOfflinePackages: vi.fn(),
    findOfflineTile: vi.fn(),
}));

vi.mock('leaflet', () => {
    class GridLayer {
        addTo(map) {
            this.map = map;
            mapMocks.gridLayers.push(this);
            return this;
        }

        remove() {
            this.map?.removeLayer(this);
        }

        getTileSize() {
            return { x: 256, y: 256 };
        }
    }

    return {
        default: {
            GridLayer,
            icon: vi.fn((options) => options),
            map: vi.fn(() => {
                mapMocks.map = {
                    on: vi.fn((event, callback) => {
                        mapMocks.mapEvents[event] = callback;
                    }),
                    panTo: vi.fn(),
                    setView: vi.fn(),
                    getZoom: vi.fn(() => 13),
                    invalidateSize: vi.fn(),
                    remove: vi.fn(),
                    removeLayer: vi.fn(),
                    addLayer: vi.fn(),
                };
                return mapMocks.map;
            }),
            marker: vi.fn(() => {
                mapMocks.marker = {
                    setLatLng: vi.fn(),
                    getLatLng: vi.fn(() => ({ lat: 35.7, lng: 51.4 })),
                    on: vi.fn((event, callback) => {
                        mapMocks.markerEvents[event] = callback;
                    }),
                    addTo: vi.fn(function addTo() {
                        return this;
                    }),
                };
                return mapMocks.marker;
            }),
            tileLayer: vi.fn((url, options) => {
                const layer = {
                    url,
                    options,
                    addTo: vi.fn(function addTo() {
                        return this;
                    }),
                    remove: vi.fn(),
                    on: vi.fn(),
                };
                mapMocks.tileLayers.push(layer);
                return layer;
            }),
        },
    };
});

vi.mock('../../resources/js/services/OfflineMapService.js', () => ({
    findOfflineTile: mapMocks.findOfflineTile,
    getStoredOfflinePackages: mapMocks.getStoredOfflinePackages,
}));

describe('vanilla Leaflet location map', () => {
    let controls;
    let container;
    let onLocationChange;
    let controller;
    let originalOnLine;
    let originalSecureContext;

    function mount(initialLocation) {
        controls = createLocationMapElements();
        container = document.createElement('div');
        document.body.append(container);
        onLocationChange = vi.fn();
        controller = createLocationMap(container, controls, {
            initialLocation,
            onLocationChange,
        });
    }

    beforeEach(() => {
        mapMocks.map = undefined;
        mapMocks.marker = undefined;
        mapMocks.mapEvents = {};
        mapMocks.markerEvents = {};
        mapMocks.tileLayers = [];
        mapMocks.gridLayers = [];
        mapMocks.getStoredOfflinePackages.mockResolvedValue([]);
        mapMocks.findOfflineTile.mockResolvedValue(undefined);
        originalOnLine = Object.getOwnPropertyDescriptor(navigator, 'onLine');
        originalSecureContext = Object.getOwnPropertyDescriptor(window, 'isSecureContext');
        Object.defineProperty(navigator, 'onLine', { configurable: true, value: true });
        Object.defineProperty(window, 'isSecureContext', { configurable: true, value: true });
        document.body.replaceChildren();
        vi.clearAllMocks();
    });

    afterEach(() => {
        controller?.destroy();
        controller = undefined;
        document.body.replaceChildren();
        if (originalOnLine) Object.defineProperty(navigator, 'onLine', originalOnLine);
        else delete navigator.onLine;
        if (originalSecureContext) Object.defineProperty(window, 'isSecureContext', originalSecureContext);
        else delete window.isSecureContext;
        vi.unstubAllGlobals();
    });

    it('updates the movable map point and keeps the online tile attribution', () => {
        mount();
        expect(controls.latitude.textContent).toBe('—');
        expect(mapMocks.marker).toBeUndefined();
        mapMocks.mapEvents.click({ latlng: { lat: 40.1, lng: -73.8 } });

        expect(controls.latitude.textContent).toBe('40.1000000');
        expect(controls.longitude.textContent).toBe('-73.8000000');
        expect(controls.positionSource.textContent).toContain('انتخاب دستی روی نقشه');
        expect(onLocationChange).toHaveBeenCalledWith({
            lat: 40.1,
            lng: -73.8,
            address: '',
            source: 'map',
            accuracyMeters: null,
        });
        expect(mapMocks.tileLayers[0].url).toBe('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');
        expect(mapMocks.tileLayers[0].options.attribution).toContain('OpenStreetMap');
        expect(mapMocks.tileLayers[0].options.attribution).toContain('copyright');
    });

    it('validates manual coordinates before updating the selected point', () => {
        mount();
        controls.manualLatitude.value = '';
        controls.manualLongitude.value = '';
        controls.coordinateForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        expect(controls.coordinateError.hidden).toBe(false);
        expect(onLocationChange).not.toHaveBeenCalled();

        controls.manualLatitude.value = '91';
        controls.manualLongitude.value = '50';
        controls.coordinateForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

        expect(controls.coordinateError.hidden).toBe(false);
        expect(onLocationChange).not.toHaveBeenCalled();

        controls.manualLatitude.value = '-89';
        controls.manualLongitude.value = '179';
        controls.coordinateForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        expect(controls.coordinateError.hidden).toBe(true);
        expect(onLocationChange).toHaveBeenLastCalledWith({
            lat: -89,
            lng: 179,
            address: '',
            source: 'manual',
            accuracyMeters: null,
        });
    });

    it('supports dragging the selected marker to update exact local coordinates', () => {
        mount();
        mapMocks.mapEvents.click({ latlng: { lat: 34.123456789, lng: 50.987654321 } });
        mapMocks.markerEvents.dragend();

        expect(controls.latitude.textContent).toBe('35.7000000');
        expect(controls.longitude.textContent).toBe('51.4000000');
        expect(onLocationChange).toHaveBeenLastCalledWith({
            lat: 35.7,
            lng: 51.4,
            address: '',
            source: 'map',
            accuracyMeters: null,
        });
    });

    it('requests GPS only after explicit user action and accepts the returned location', () => {
        const getCurrentPosition = vi.fn();
        vi.stubGlobal('navigator', { onLine: true, geolocation: { getCurrentPosition } });
        mount();
        expect(getCurrentPosition).not.toHaveBeenCalled();

        controls.gpsButton.click();
        expect(getCurrentPosition).toHaveBeenCalledOnce();
        getCurrentPosition.mock.calls[0][0]({
            coords: { latitude: 10.2, longitude: 20.4, accuracy: 12.4 },
        });
        expect(onLocationChange).toHaveBeenCalledWith({
            lat: 10.2,
            lng: 20.4,
            address: '',
            source: 'browser',
            accuracyMeters: 12.4,
        });
        expect(controls.positionSource.textContent).toContain('GPS یا شبکه');
        expect(controls.positionSource.textContent).toContain('12 متر');
    });

    it('only searches an address after explicit consent and moves the selected marker to valid results', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify([{
            lat: '35.70000004',
            lng: '51.40000006',
            address: 'تهران، ایران',
        }]), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        }));
        vi.stubGlobal('fetch', fetchMock);
        mount();
        controls.searchAddress.value = 'تهران';
        controls.searchForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        expect(fetchMock).not.toHaveBeenCalled();
        expect(controls.searchError.textContent).toContain('رضایت');

        controls.searchConsent.checked = true;
        fetchMock.mockResolvedValueOnce(new Response(JSON.stringify({
            success: true,
            data: {
                lat: '35.70000004',
                lng: '51.40000006',
                address: 'تهران، ایران',
            },
        }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        }));
        controls.searchForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());
        expect(fetchMock.mock.calls[0][0]).toBe('/api/location/search');
        expect(fetchMock.mock.calls[0][1].method).toBe('POST');
        expect(fetchMock.mock.calls[0][1].credentials).toBe('same-origin');
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ query: 'تهران' });
        await vi.waitFor(() => expect(onLocationChange).toHaveBeenLastCalledWith({
            lat: 35.7000000,
            lng: 51.4000001,
            address: 'تهران، ایران',
            source: 'geocoding',
            accuracyMeters: null,
        }));
    });

    it('loads installed package tiles, reports uncovered offline areas, and drops online tiles', async () => {
        Object.defineProperty(navigator, 'onLine', { configurable: true, value: false });
        mapMocks.getStoredOfflinePackages.mockResolvedValue([{
            id: 'tehran',
            version: 1,
            attribution: '© OpenStreetMap contributors',
        }]);
        mount();
        await vi.waitFor(() => expect(mapMocks.gridLayers).toHaveLength(1));
        const tileDone = vi.fn();
        const tile = mapMocks.gridLayers[0].createTile({ z: 12, x: 1, y: 1 }, tileDone);
        await vi.waitFor(() => expect(mapMocks.findOfflineTile).toHaveBeenCalledWith(
            expect.any(Array),
            12,
            1,
            1,
        ));
        expect(tile.getAttribute('role')).toBe('presentation');
        expect(tileDone).toHaveBeenCalledWith(expect.any(Error), tile);
        expect(controls.mapNotice.hidden).toBe(false);
        expect(controls.mapNotice.textContent).toContain('کاشی این موقعیت');
        expect(mapMocks.tileLayers).toHaveLength(0);
    });

    it('shows a clear offline state when no map package is installed', async () => {
        Object.defineProperty(navigator, 'onLine', { configurable: true, value: false });
        mount();
        await vi.waitFor(() => expect(mapMocks.getStoredOfflinePackages).toHaveBeenCalledOnce());
        await vi.waitFor(() => expect(controls.mapNotice.textContent).toContain('نصب نشده است'));
        expect(mapMocks.tileLayers).toHaveLength(0);
        expect(mapMocks.gridLayers).toHaveLength(0);
    });

    it('restores online tiles after reconnecting and reports tile-load errors', () => {
        mount();
        Object.defineProperty(navigator, 'onLine', { configurable: true, value: false });
        window.dispatchEvent(new Event('offline'));
        Object.defineProperty(navigator, 'onLine', { configurable: true, value: true });
        window.dispatchEvent(new Event('online'));
        expect(controls.mapNotice.hidden).toBe(true);
        expect(mapMocks.tileLayers).toHaveLength(2);
        expect(mapMocks.tileLayers[1].url).toContain('tile.openstreetmap.org');

        mapMocks.tileLayers[1].on.mock.calls[0][1]();
        expect(controls.mapNotice.hidden).toBe(false);
        expect(controls.mapNotice.textContent).toContain('بارگذاری کاشی آنلاین');
    });
});
