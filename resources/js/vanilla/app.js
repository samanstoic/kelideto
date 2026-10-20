import { createAppState } from './state/app-state.js';
import { createRouter } from './router/router.js';
import { renderNotFound, routes } from './router/routes.js';
import { purgeExpiredLocalData } from '../services/OfflineStorageService.js';
import { registerAutoSync } from '../services/OfflineSyncService.js';

function registerNetworkStatus(documentRef, windowRef) {
    const status = documentRef.createElement('div');
    status.className = 'kp-pwa-network-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.setAttribute('aria-atomic', 'true');
    documentRef.getElementById('vanilla-app').before(status);

    const updateConnection = () => {
        const online = windowRef.navigator.onLine;
        const localStorageAvailable = typeof windowRef.indexedDB !== 'undefined';
        status.textContent = !localStorageAvailable
            ? 'ذخیره‌سازی آفلاین در این مرورگر پشتیبانی نمی‌شود؛ برای ثبت و پیگیری سفارش به اتصال نیاز دارید.'
            : online
                ? 'متصل به اینترنت — پیش‌نویس‌ها پس از ورود همگام می‌شوند؛ این کار سفارش یا اعزام ایجاد نمی‌کند.'
                : 'آفلاین — پیش‌نویس و موقعیت روی همین دستگاه ذخیره می‌شود؛ هنوز سفارش واقعی یا اعزامی ثبت نشده است.';
        status.classList.toggle('is-offline', !online);
    };
    windowRef.addEventListener('online', updateConnection);
    windowRef.addEventListener('offline', updateConnection);
    updateConnection();

    return {
        status,
        cleanup: () => {
            windowRef.removeEventListener('online', updateConnection);
            windowRef.removeEventListener('offline', updateConnection);
            status.remove();
        },
    };
}

function registerAppWideOfflineSupport(documentRef = document, windowRef = window) {
    const networkStatus = registerNetworkStatus(documentRef, windowRef);
    const { status } = networkStatus;
    const stopAutoSync = registerAutoSync((result) => {
        if (result.synced > 0) {
            status.textContent = `${result.synced.toLocaleString('fa-IR')} پیش‌نویس به سرور منتقل شد؛ این همگام‌سازی سفارش واقعی یا اعزام ایجاد نمی‌کند.`;
        } else if (result.conflicts > 0) {
            status.textContent = `${result.conflicts.toLocaleString('fa-IR')} پیش‌نویس به‌دلیل تعارض متوقف شد. آن را بررسی کنید و برای نسخهٔ جدید، پیش‌نویس تازه بسازید.`;
        }
        windowRef.dispatchEvent(new windowRef.CustomEvent('keypro:drafts-synced', { detail: result }));
    }, (error) => {
        status.textContent = error.message;
    });

    const purge = () => {
        if (typeof windowRef.indexedDB === 'undefined') return;
        void purgeExpiredLocalData().catch((error) => {
            console.error('پاک‌سازی داده‌های آفلاین منقضی‌شده ناموفق بود.', error);
        });
    };
    const purgeOnVisible = () => {
        if (documentRef.visibilityState === 'visible') purge();
    };
    windowRef.addEventListener('pageshow', purge);
    documentRef.addEventListener('visibilitychange', purgeOnVisible);
    if (typeof windowRef.indexedDB !== 'undefined') purge();

    return () => {
        stopAutoSync();
        networkStatus.cleanup();
        windowRef.removeEventListener('pageshow', purge);
        documentRef.removeEventListener('visibilitychange', purgeOnVisible);
    };
}

export function registerServiceWorker(navigatorRef = navigator, windowRef = window) {
    if (!('serviceWorker' in navigatorRef) || !windowRef.isSecureContext) return;
    void navigatorRef.serviceWorker.register('/service-worker.js')
        .catch((error) => console.error('ثبت Service Worker آفلاین ناموفق بود.', error));
}

export function startVanillaApp(documentRef = document) {
    const vanillaRoot = documentRef.getElementById('vanilla-app');

    if (!vanillaRoot) {
        throw new Error('The vanilla entry requires the #vanilla-app root.');
    }

    const state = createAppState({ path: window.location.pathname });
    const router = createRouter({
        root: vanillaRoot,
        routes,
        renderNotFound,
        state,
    });

    registerServiceWorker();
    const cleanupOfflineSupport = registerAppWideOfflineSupport(documentRef, window);
    return { state, router, cleanupOfflineSupport };
}

if (typeof document !== 'undefined' && document.getElementById('vanilla-app')) {
    startVanillaApp(document);
}
