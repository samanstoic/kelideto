import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { findOfflineTile, getStoredOfflinePackages } from '../../services/OfflineMapService.js';

const DEFAULT_LATITUDE = 35.6892;
const DEFAULT_LONGITUDE = 51.389;
const DEFAULT_ZOOM = 13;
const OSM_TILE_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
const OSM_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer">OpenStreetMap</a> contributors';
let lastGeocodingRequestAt = 0;

const locationIcon = L.icon({
    iconUrl: 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2225%22 height=%2241%22 viewBox=%220 0 25 41%22%3E%3Cpath fill=%22%2306b6d4%22 stroke=%22%230e7490%22 d=%22M12.5 1C6.15 1 1 6.15 1 12.5 1 21 12.5 40 12.5 40S24 21 24 12.5 18.85 1 12.5 1Z%22/%3E%3Ccircle cx=%2212.5%22 cy=%2212.5%22 r=%224%22 fill=%22white%22/%3E%3C/svg%3E',
    iconSize: [25, 41],
    iconAnchor: [12, 41],
});

function makeElement(tagName, { className, text, attributes } = {}) {
    const element = document.createElement(tagName);
    if (className) element.className = className;
    if (text) element.textContent = text;
    if (attributes) {
        Object.entries(attributes).forEach(([name, value]) => {
            if (value !== undefined && value !== null && value !== false) {
                element.setAttribute(name, value === true ? '' : String(value));
            }
        });
    }
    return element;
}

function safeAttribution(packages) {
    return [...new Set(packages.map((pkg) => pkg.attribution.replace(/[&<>"']/g, '')))].join(' | ');
}

class OfflineTileLayer extends L.GridLayer {
    constructor(packages, onMissingTile) {
        super({ attribution: safeAttribution(packages), zIndex: 2 });
        this.packages = packages;
        this.onMissingTile = onMissingTile;
    }

    createTile(coords, done) {
        const tile = document.createElement('img');
        const size = this.getTileSize();
        tile.width = size.x;
        tile.height = size.y;
        tile.alt = '';
        tile.setAttribute('role', 'presentation');

        void findOfflineTile(this.packages, coords.z, coords.x, coords.y)
            .then(async (response) => {
                if (!response) {
                    this.onMissingTile();
                    done(new Error('کاشی آفلاین ذخیره نشده است.'), tile);
                    return;
                }
                const objectUrl = URL.createObjectURL(await response.blob());
                tile.onload = () => {
                    URL.revokeObjectURL(objectUrl);
                    done(undefined, tile);
                };
                tile.onerror = () => {
                    URL.revokeObjectURL(objectUrl);
                    this.onMissingTile();
                    done(new Error('کاشی ذخیره‌شده قابل نمایش نیست.'), tile);
                };
                tile.src = objectUrl;
            })
            .catch((error) => {
                this.onMissingTile();
                done(new Error(error instanceof Error ? error.message : 'خواندن کاشی ذخیره‌شده ناموفق بود.'), tile);
            });

        return tile;
    }
}

export function createLocationMap(container, controls, { initialLocation, onLocationChange }) {
    const location = {
        lat: Number.isFinite(initialLocation?.lat) ? initialLocation.lat : null,
        lng: Number.isFinite(initialLocation?.lng) ? initialLocation.lng : null,
        address: initialLocation?.address ?? '',
        source: initialLocation?.source ?? 'unset',
        accuracyMeters: initialLocation?.accuracyMeters ?? null,
    };
    let map;
    let marker;
    let offlinePackages = [];
    let onlineLayer;
    let offlineLayer;
    let destroyed = false;
    let mapError = '';
    let loadingOfflinePackages = false;
    let geocoding = false;

    function setMapError(message) {
        mapError = message;
        controls.mapNotice.hidden = !message;
        controls.mapNotice.textContent = message;
    }

    function updateCoordinates() {
        const hasPoint = Number.isFinite(location.lat) && Number.isFinite(location.lng);
        controls.latitude.textContent = hasPoint ? location.lat.toFixed(7) : '—';
        controls.longitude.textContent = hasPoint ? location.lng.toFixed(7) : '—';
        const methods = {
            browser: 'مکان‌یابی مرورگر (ممکن است از GPS یا شبکه استفاده کند)',
            geocoding: 'نتیجهٔ جست‌وجوی آدرس OpenStreetMap',
            map: 'انتخاب دستی روی نقشه',
            manual: 'ورود دستی مختصات',
            saved: 'موقعیت ذخیره‌شده',
            unset: 'هنوز موقعیتی انتخاب نشده است',
        };
        controls.positionSource.textContent = hasPoint
            ? `${methods[location.source] ?? 'موقعیت انتخاب‌شده'}${Number.isFinite(location.accuracyMeters)
                ? ` · دقت گزارش‌شدهٔ مرورگر: ${Math.round(location.accuracyMeters)} متر`
                : ''}`
            : methods.unset;
    }

    function setLocation(lat, lng, {
        pan = true,
        notify = true,
        source = 'map',
        accuracyMeters = null,
    } = {}) {
        location.lat = Number(Number(lat).toFixed(7));
        location.lng = Number(Number(lng).toFixed(7));
        location.source = source;
        location.accuracyMeters = accuracyMeters;
        if (marker) {
            marker.setLatLng([location.lat, location.lng]);
        } else {
            marker = L.marker([location.lat, location.lng], {
                draggable: true,
                icon: locationIcon,
            }).addTo(map);
            marker.on('dragend', () => {
                const point = marker.getLatLng();
                setLocation(point.lat, point.lng, { pan: false, source: 'map' });
            });
        }
        if (pan) map?.panTo([location.lat, location.lng]);
        updateCoordinates();
        if (notify) onLocationChange({ ...location });
    }

    function reportOnlineTileError() {
        if (!destroyed && navigator.onLine) {
            setMapError('بارگذاری کاشی آنلاین نقشه ناموفق بود. اتصال اینترنت را بررسی کنید.');
        }
    }

    function createOnlineLayer() {
        const layer = L.tileLayer(OSM_TILE_URL, {
            maxZoom: 19,
            attribution: OSM_ATTRIBUTION,
        }).addTo(map);
        layer.on('tileerror', reportOnlineTileError);
        return layer;
    }

    function updateNetworkState() {
        const offline = !navigator.onLine;
        if (offline) {
            if (!loadingOfflinePackages) {
                setMapError(offlinePackages.length
                    ? 'اتصال شبکه قطع است؛ نقشه از بسته‌های آفلاین ذخیره‌شده استفاده می‌کند. برای محدودهٔ خارج از بسته، کاشی نمایش داده نمی‌شود.'
                    : 'نقشهٔ آفلاین برای این دستگاه نصب نشده است؛ مختصات و پیش‌نویس‌های ذخیره‌شده همچنان در دسترس‌اند.');
            }
        } else if (
            mapError.includes('نقشهٔ آفلاین') ||
            mapError.includes('اتصال شبکه قطع') ||
            mapError.includes('کاشی آنلاین')
        ) {
            setMapError('');
        }

        if (map) {
            if (offline && onlineLayer) {
                map.removeLayer(onlineLayer);
                onlineLayer = undefined;
            } else if (!offline && !onlineLayer) {
                onlineLayer = createOnlineLayer();
            }
        }
    }

    async function refreshOfflinePackages() {
        if (loadingOfflinePackages || destroyed) return;
        loadingOfflinePackages = true;
        try {
            const packages = await getStoredOfflinePackages();
            if (destroyed) return;
            offlinePackages = packages;
            offlineLayer?.remove();
            offlineLayer = packages.length
                ? new OfflineTileLayer(packages, () => {
                    if (!navigator.onLine) {
                        setMapError('کاشی این موقعیت در بسته‌های نصب‌شده نیست؛ بستهٔ منطقه و سطح بزرگ‌نمایی را بررسی کنید.');
                    }
                }).addTo(map)
                : undefined;
            updateNetworkState();
        } catch (error) {
            if (!destroyed) {
                setMapError(error instanceof Error
                    ? `خواندن بسته‌های نقشهٔ آفلاین ناموفق بود: ${error.message}`
                    : 'خواندن بسته‌های نقشهٔ آفلاین ناموفق بود؛ نقشهٔ آنلاین همچنان در دسترس است.');
            }
        } finally {
            loadingOfflinePackages = false;
        }
    }

    function validateManualCoordinates(event) {
        event.preventDefault();
        const latitudeValue = controls.manualLatitude.value.trim();
        const longitudeValue = controls.manualLongitude.value.trim();
        const latitude = Number(latitudeValue);
        const longitude = Number(longitudeValue);
        if (!latitudeValue || !longitudeValue ||
            !Number.isFinite(latitude) || !Number.isFinite(longitude)) {
            controls.coordinateError.textContent = 'عرض و طول جغرافیایی را به‌صورت عددی وارد کنید.';
            controls.coordinateError.hidden = false;
            return;
        }
        if (latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
            controls.coordinateError.textContent = 'عرض جغرافیایی باید بین ‎-90 و 90 و طول جغرافیایی بین ‎-180 و 180 باشد.';
            controls.coordinateError.hidden = false;
            return;
        }
        controls.coordinateError.hidden = true;
        setMapError('');
        setLocation(latitude, longitude, { source: 'manual' });
        map.setView([location.lat, location.lng], map.getZoom());
    }

    function useGps() {
        if (!navigator.geolocation || !window.isSecureContext) {
            controls.gpsError.textContent = 'موقعیت‌یابی در این محیط امن و پشتیبانی‌شده در دسترس نیست.';
            controls.gpsError.hidden = false;
            return;
        }

        controls.gpsButton.disabled = true;
        controls.gpsButton.textContent = '📍 در حال دریافت موقعیت دستگاه...';
        controls.gpsError.hidden = true;
        navigator.geolocation.getCurrentPosition(
            (position) => {
                if (destroyed) return;
                const { latitude, longitude } = position.coords;
                setLocation(latitude, longitude, {
                    source: 'browser',
                    accuracyMeters: position.coords.accuracy,
                });
                controls.gpsButton.disabled = false;
                controls.gpsButton.textContent = '📍 مکان‌یابی دستگاه';
            },
            (error) => {
                if (destroyed) return;
                controls.gpsButton.disabled = false;
                controls.gpsButton.textContent = '📍 مکان‌یابی دستگاه';
                if (error.code === error.PERMISSION_DENIED) {
                    controls.gpsError.textContent = 'دسترسی مکان‌یابی توسط شما رد شد. در صورت تمایل، مجوز مرورگر را فعال کنید.';
                } else if (error.code === error.POSITION_UNAVAILABLE) {
                    controls.gpsError.textContent = 'موقعیت دستگاه در دسترس نیست.';
                } else if (error.code === error.TIMEOUT) {
                    controls.gpsError.textContent = 'زمان دریافت موقعیت دستگاه به پایان رسید.';
                } else {
                    controls.gpsError.textContent = 'خطای ناشناخته در مکان‌یابی دستگاه.';
                }
                controls.gpsError.hidden = false;
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 },
        );
    }

    async function searchAddress(event) {
        event.preventDefault();
        const query = controls.searchAddress.value.trim();
        if (!query) {
            controls.searchError.textContent = 'برای جست‌وجو، یک نشانی وارد کنید.';
            controls.searchError.hidden = false;
            return;
        }
        if (!controls.searchConsent.checked) {
            controls.searchError.textContent = 'برای ارسال متن جست‌وجو به Nominatim، رضایت خود را تأیید کنید.';
            controls.searchError.hidden = false;
            return;
        }
        if (geocoding) return;
        const elapsed = Date.now() - lastGeocodingRequestAt;
        if (elapsed < 1000) {
            controls.searchError.textContent = 'برای رعایت محدودیت سرویس جست‌وجو، حداقل یک ثانیه صبر کنید.';
            controls.searchError.hidden = false;
            return;
        }
        geocoding = true;
        lastGeocodingRequestAt = Date.now();
        controls.searchButton.disabled = true;
        controls.searchButton.textContent = 'در حال جست‌وجو...';
        controls.searchError.hidden = true;
        try {
            const headers = {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            };
            const token = sessionStorage.getItem('keypro_api_token');
            if (token) headers.Authorization = `Bearer ${token}`;
            const response = await fetch('/api/location/search', {
                method: 'POST',
                credentials: 'same-origin',
                headers,
                body: JSON.stringify({ query }),
            });
            const payload = await response.json();
            if (!response.ok) {
                throw new Error(typeof payload.message === 'string'
                    ? payload.message
                    : `سرویس جست‌وجوی نشانی پاسخ نداد (HTTP ${response.status}).`);
            }
            const result = payload.data;
            if (!result || typeof result !== 'object') {
                controls.searchError.textContent = 'برای این نشانی نتیجه‌ای پیدا نشد؛ مختصات را دستی وارد کنید.';
                controls.searchError.hidden = false;
                return;
            }
            const latitude = Number(result.lat);
            const longitude = Number(result.lng);
            if (!Number.isFinite(latitude) || !Number.isFinite(longitude) ||
                latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
                throw new Error('مختصات برگشتی سرویس جست‌وجو معتبر نیست.');
            }
            const address = typeof result.address === 'string' ? result.address : query;
            controls.addressInput.value = address;
            location.address = address;
            setLocation(latitude, longitude, { source: 'geocoding' });
            map.setView([location.lat, location.lng], Math.max(map.getZoom(), 15));
            controls.searchError.textContent = 'نتیجهٔ جست‌وجوی Nominatim دریافت شد؛ محل را روی نقشه بررسی کنید.';
            controls.searchError.hidden = false;
        } catch (error) {
            controls.searchError.textContent = error instanceof Error
                ? error.message
                : 'جست‌وجوی نشانی انجام نشد. اتصال را بررسی یا مختصات را دستی وارد کنید.';
            controls.searchError.hidden = false;
        } finally {
            geocoding = false;
            controls.searchButton.disabled = false;
            controls.searchButton.textContent = 'جست‌وجوی نشانی';
        }
    }

    controls.addressInput.value = location.address;
    controls.addressInput.addEventListener('input', () => {
        location.address = controls.addressInput.value;
        onLocationChange({ ...location });
    });
    controls.coordinateForm.addEventListener('submit', validateManualCoordinates);
    controls.gpsButton.addEventListener('click', useGps);
    controls.searchForm.addEventListener('submit', searchAddress);
    window.addEventListener('online', updateNetworkState);
    window.addEventListener('offline', updateNetworkState);

    map = L.map(container, {
        center: Number.isFinite(location.lat) && Number.isFinite(location.lng)
            ? [location.lat, location.lng]
            : [DEFAULT_LATITUDE, DEFAULT_LONGITUDE],
        zoom: DEFAULT_ZOOM,
        zoomControl: true,
        attributionControl: true,
    });
    if (Number.isFinite(location.lat) && Number.isFinite(location.lng)) {
        setLocation(location.lat, location.lng, {
            notify: false,
            source: location.source,
            accuracyMeters: location.accuracyMeters,
        });
    }
    map.on('click', (event) => {
        setLocation(event.latlng.lat, event.latlng.lng, { source: 'map' });
    });

    onlineLayer = navigator.onLine ? createOnlineLayer() : undefined;
    updateCoordinates();
    updateNetworkState();
    void refreshOfflinePackages();

    return {
        getLocation() {
            return Number.isFinite(location.lat) && Number.isFinite(location.lng)
                ? { ...location }
                : undefined;
        },
        setLocation(point) {
            controls.addressInput.value = point.address ?? '';
            location.address = point.address ?? '';
            if (!Number.isFinite(point.lat) || !Number.isFinite(point.lng)) {
                updateCoordinates();
                return;
            }
            setLocation(point.lat, point.lng, {
                source: point.source ?? 'saved',
                accuracyMeters: point.accuracyMeters ?? null,
            });
            map.setView([location.lat, location.lng], map.getZoom());
        },
        invalidateSize() {
            map.invalidateSize();
        },
        refreshOfflinePackages,
        destroy() {
            destroyed = true;
            window.removeEventListener('online', updateNetworkState);
            window.removeEventListener('offline', updateNetworkState);
            controls.searchForm.removeEventListener('submit', searchAddress);
            onlineLayer?.remove();
            offlineLayer?.remove();
            map.remove();
        },
    };
}

export function createLocationMapElements() {
    const root = makeElement('section', { className: 'kp-vanilla-location-map' });
    const mapNotice = makeElement('div', {
        className: 'kp-vanilla-location-map-notice',
        attributes: { role: 'alert', hidden: true },
    });
    const map = makeElement('div', {
        className: 'kp-vanilla-leaflet-map',
        attributes: { 'aria-label': 'نقشهٔ انتخاب موقعیت' },
    });
    const coordinates = makeElement('section', {
        className: 'kp-card kp-vanilla-location-coordinates',
        attributes: { 'aria-label': 'مختصات انتخاب‌شده' },
    });
    const latitudeGroup = makeElement('div');
    latitudeGroup.append(
        makeElement('span', { text: 'عرض جغرافیایی (Latitude):' }),
        makeElement('p', { attributes: { id: 'display-lat', dir: 'ltr' } }),
    );
    const longitudeGroup = makeElement('div');
    longitudeGroup.append(
        makeElement('span', { text: 'طول جغرافیایی (Longitude):' }),
        makeElement('p', { attributes: { id: 'display-lng', dir: 'ltr' } }),
    );
    coordinates.append(latitudeGroup, longitudeGroup);
    const positionSource = makeElement('p', {
        className: 'kp-vanilla-position-source',
        attributes: { role: 'status', 'aria-live': 'polite' },
    });
    coordinates.append(positionSource);

    const addressGroup = makeElement('div', { className: 'kp-input-group' });
    const addressLabel = makeElement('label', {
        className: 'kp-label',
        text: 'آدرس متنی محل (خیابان، کوچه، پلاک)',
        attributes: { for: 'address-input' },
    });
    const addressInput = makeElement('input', {
        className: 'kp-input',
        attributes: {
            id: 'address-input',
            type: 'text',
            placeholder: 'مثال: تهران، سعادت‌آباد، بلوار اصلی، پلاک ۱۲',
            autocomplete: 'street-address',
        },
    });
    addressGroup.append(addressLabel, addressInput);

    const searchForm = makeElement('form', { className: 'kp-vanilla-address-search' });
    const searchLabel = makeElement('label', {
        className: 'kp-label',
        text: 'جست‌وجوی محدوده یا مکان عمومی',
        attributes: { for: 'address-search-input' },
    });
    const searchAddress = makeElement('input', {
        className: 'kp-input',
        attributes: {
            id: 'address-search-input',
            type: 'search',
            autocomplete: 'off',
            maxlength: 250,
            minlength: 3,
            placeholder: 'نام شهر، محله یا مکان عمومی؛ نه نشانی خصوصی',
        },
    });
    const searchConsentLabel = makeElement('label', { className: 'kp-vanilla-search-consent' });
    const searchConsent = makeElement('input', {
        attributes: { id: 'address-search-consent', type: 'checkbox' },
    });
    searchConsentLabel.append(searchConsent, makeElement('span', {
        text: 'می‌پذیرم نام مکان عمومی به Nominatim ارسال و نتیجه تا ۳۰ روز در KeyPro cache شود؛ نشانی منزل یا اطلاعات شخصی وارد نمی‌کنم.',
    }));
    const searchButton = makeElement('button', {
        className: 'kp-btn kp-btn-secondary',
        text: 'جست‌وجوی نشانی',
        attributes: { type: 'submit' },
    });
    const searchError = makeElement('p', {
        className: 'kp-error-text',
        attributes: { role: 'status', 'aria-live': 'polite', hidden: true },
    });
    const geocoderAttribution = makeElement('small', {
        text: 'جست‌وجو: © OpenStreetMap contributors · Nominatim؛ فقط جست‌وجوی دستی، بدون تکمیل خودکار، حداکثر یک درخواست در ثانیه برای کل سامانه.',
    });
    searchForm.append(searchLabel, searchAddress, searchConsentLabel, searchButton, searchError, geocoderAttribution);

    const coordinateDetails = makeElement('details', { className: 'kp-vanilla-manual-coordinates' });
    coordinateDetails.append(makeElement('summary', { text: 'ورود دستی مختصات جغرافیایی' }));
    const coordinateForm = makeElement('form', { className: 'kp-vanilla-coordinate-form' });
    const manualLatitude = makeElement('input', {
        className: 'kp-input',
        attributes: { id: 'manual-lat', type: 'number', step: 'any', placeholder: '35.6892', inputmode: 'decimal' },
    });
    const manualLongitude = makeElement('input', {
        className: 'kp-input',
        attributes: { id: 'manual-lng', type: 'number', step: 'any', placeholder: '51.3890', inputmode: 'decimal' },
    });
    const latitudeLabel = makeElement('label', {
        className: 'kp-label',
        text: 'Latitude',
        attributes: { for: 'manual-lat' },
    });
    const longitudeLabel = makeElement('label', {
        className: 'kp-label',
        text: 'Longitude',
        attributes: { for: 'manual-lng' },
    });
    const latitudeField = makeElement('div', { className: 'kp-input-group' });
    const longitudeField = makeElement('div', { className: 'kp-input-group' });
    latitudeField.append(latitudeLabel, manualLatitude);
    longitudeField.append(longitudeLabel, manualLongitude);
    const coordinateError = makeElement('p', {
        className: 'kp-error-text',
        attributes: { role: 'alert', hidden: true },
    });
    const applyCoordinates = makeElement('button', {
        className: 'kp-btn kp-btn-secondary',
        text: 'اعمال مختصات',
        attributes: { type: 'submit' },
    });
    coordinateForm.append(latitudeField, longitudeField, applyCoordinates, coordinateError);
    coordinateDetails.append(coordinateForm);

    const gpsControls = makeElement('div', { className: 'kp-vanilla-gps-controls' });
    const gpsButton = makeElement('button', {
        className: 'kp-btn kp-btn-primary',
        text: '📍 مکان‌یابی دستگاه',
        attributes: { type: 'button' },
    });
    const gpsExplanation = makeElement('small', {
        text: 'پس از اجازهٔ شما، مرورگر مکان‌یابی را درخواست می‌کند؛ دستگاه ممکن است از GPS ماهواره‌ای یا Wi‑Fi و شبکهٔ همراه استفاده کند. انتخاب روی نقشه و ورود دستی، روش‌های جداگانه‌اند.',
    });
    const gpsError = makeElement('span', {
        className: 'kp-error-text',
        attributes: { role: 'alert', hidden: true },
    });
    gpsControls.append(gpsButton, gpsExplanation, gpsError);
    root.append(mapNotice, map, coordinates, addressGroup, searchForm, coordinateDetails, gpsControls);

    return {
        root,
        map,
        mapNotice,
        latitude: latitudeGroup.querySelector('p'),
        longitude: longitudeGroup.querySelector('p'),
        positionSource,
        addressInput,
        searchForm,
        searchAddress,
        searchConsent,
        searchButton,
        searchError,
        coordinateForm,
        manualLatitude,
        manualLongitude,
        coordinateError,
        gpsButton,
        gpsError,
    };
}
