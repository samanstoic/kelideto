import {
    getOfflineMapCatalog,
    getStoredOfflinePackages,
    installOfflineMap,
    removeOfflineMap,
} from '../../services/OfflineMapService.js';
import { getTotalPackagesSize } from '../../services/OfflineStorageService.js';

function element(tagName, { className, text, attributes } = {}) {
    const node = document.createElement(tagName);
    if (className) node.className = className;
    if (text) node.textContent = text;
    if (attributes) {
        Object.entries(attributes).forEach(([name, value]) => {
            if (value !== undefined && value !== null && value !== false) {
                node.setAttribute(name, value === true ? '' : String(value));
            }
        });
    }
    return node;
}

function formatBytes(bytes) {
    if (bytes < 1024) return `${bytes} بایت`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function createOfflineMapManager() {
    const root = element('section', { className: 'kp-vanilla-offline-manager' });
    const title = element('header');
    title.append(
        element('h2', { text: 'مدیریت بسته‌های نقشه آفلاین' }),
        element('p', {
            text: 'فقط بسته‌های دارای مجوز ODbL و منتشرشده روی توزیع‌کنندهٔ هم‌مبدأ نصب می‌شوند؛ سرور عمومی کاشی OSM منبع دانلود انبوه نیست.',
        }),
    );
    const status = element('div', {
        className: 'kp-vanilla-offline-status',
        attributes: { 'aria-live': 'polite', role: 'status' },
    });
    const installedSummary = element('div', { className: 'kp-card kp-vanilla-installed-summary', attributes: { hidden: true } });
    const availableGrid = element('div', { className: 'kp-vanilla-package-grid' });
    const unavailableInstalled = element('div', { className: 'kp-vanilla-orphaned-packages' });
    const empty = element('div', {
        className: 'kp-card kp-vanilla-offline-empty',
        attributes: { hidden: true },
    });
    root.append(title, status, installedSummary, availableGrid, unavailableInstalled, empty);

    let installed = [];
    let available = [];
    let loading = true;
    let actionLoading = null;
    let progress = null;
    let toastMessage = '';
    let toastType = 'info';
    let disposed = false;

    function showToast(message, type) {
        toastMessage = message;
        toastType = type;
    }

    function renderToast() {
        if (!toastMessage) return null;
        const toast = element('div', {
            className: `kp-toast kp-toast-${toastType}`,
            attributes: { role: 'alert' },
        });
        toast.append(element('span', { text: toastType === 'success' ? '✓' : toastType === 'error' ? '⚠' : 'ℹ' }));
        toast.append(element('span', { text: toastMessage }));
        const dismiss = element('button', {
            className: 'kp-vanilla-toast-close',
            text: '✕',
            attributes: { type: 'button', 'aria-label': 'بستن پیام' },
        });
        dismiss.addEventListener('click', () => {
            toastMessage = '';
            render();
        });
        toast.append(dismiss);
        return toast;
    }

    function renderPackage(pkg) {
        const stored = installed.find((item) => item.id === pkg.id);
        const isInstalled = Boolean(stored);
        const isUpdate = isInstalled && stored.version !== pkg.version;
        const isExpired = Date.parse(pkg.validUntil) <= Date.now();
        const storedExpired = stored?.validUntil && Date.parse(stored.validUntil) <= Date.now();
        const isLoading = actionLoading === pkg.id;
        const card = element('article', { className: 'kp-card kp-vanilla-package-card' });
        const heading = element('header', { className: 'kp-vanilla-package-heading' });
        const name = element('div');
        name.append(
            element('h3', { text: `🗺️ ${pkg.name}` }),
            element('p', { text: pkg.regionLabel }),
        );
        heading.append(name);
        if (isInstalled) {
            heading.append(element('span', {
                className: 'kp-vanilla-package-installed',
                text: storedExpired ? 'منقضی' : 'نصب‌شده',
            }));
        }

        const details = element('dl', { className: 'kp-vanilla-package-details' });
        [
            ['حجم با فهرست:', formatBytes(pkg.sizeBytes + pkg.indexSizeBytes)],
            ['تعداد کاشی‌ها:', pkg.tileCount.toLocaleString('fa-IR')],
            ['سطح زوم:', `${pkg.zoomMin}–${pkg.zoomMax}`],
            ['نسخه:', `v${pkg.version}${isUpdate ? ' (به‌روزرسانی)' : ''}`],
            ['به‌روزرسانی داده:', new Date(pkg.dataUpdatedAt).toLocaleDateString('fa-IR')],
            ['اعتبار داده تا:', new Date(pkg.validUntil).toLocaleDateString('fa-IR')],
        ].forEach(([label, value]) => {
            const row = element('div');
            row.append(element('dt', { text: label }), element('dd', { text: value }));
            details.append(row);
        });
        const sourceRow = element('div');
        const sourceLabel = element('dt', { text: 'منبع داده:' });
        const sourceLink = element('a', {
            text: pkg.source,
            attributes: { href: pkg.sourceUrl, target: '_blank', rel: 'noreferrer' },
        });
        const sourceValue = element('dd');
        sourceValue.append(sourceLink);
        sourceRow.append(sourceLabel, sourceValue);
        details.append(sourceRow);

        const licenseRow = element('div');
        const licenseLabel = element('dt', { text: 'مجوز:' });
        const licenseLink = element('a', {
            text: pkg.license,
            attributes: { href: pkg.licenseUrl, target: '_blank', rel: 'noreferrer' },
        });
        const licenseValue = element('dd');
        licenseValue.append(licenseLink);
        licenseRow.append(licenseLabel, licenseValue);
        details.append(licenseRow);

        const attribution = element('p', {
            className: 'kp-vanilla-package-attribution',
            text: pkg.attribution,
        });
        const button = element('button', {
            className: `kp-btn ${isInstalled && !isUpdate ? 'kp-btn-danger' : 'kp-btn-primary'}`,
            text: isLoading
                ? progress
                    ? `دریافت ${progress.completed.toLocaleString('fa-IR')} از ${progress.total.toLocaleString('fa-IR')} کاشی`
                    : isInstalled && !isUpdate ? 'در حال حذف...' : 'در حال نصب...'
                : isInstalled && !isUpdate
                    ? 'حذف بسته'
                    : isExpired
                        ? 'اعتبار داده پایان یافته'
                    : isUpdate ? 'به‌روزرسانی بسته' : 'دانلود و نصب بسته',
            attributes: {
                type: 'button',
                disabled: Boolean(actionLoading) || (isExpired && (!isInstalled || isUpdate)),
            },
        });

        if (isLoading && progress) {
            const progressBar = element('progress', {
                attributes: {
                    max: progress.total,
                    value: progress.completed,
                    'aria-label': 'پیشرفت نصب بستهٔ نقشه',
                },
            });
            card.append(progressBar);
        }
        button.addEventListener('click', () => {
            if (isInstalled && !isUpdate) {
                void deletePackage(pkg.id, pkg.name);
            } else {
                void installPackage(pkg);
            }
        });
        card.append(heading, details, attribution, button);
        return card;
    }

    function renderOrphanedPackage(pkg) {
        const card = element('article', { className: 'kp-card kp-vanilla-orphaned-package' });
        const details = element('div');
        details.append(
            element('strong', { text: pkg.name }),
            element('p', { text: `نسخهٔ ${pkg.version} · ${formatBytes(pkg.sizeBytes)} · ${pkg.attribution}` }),
        );
        const button = element('button', {
            className: 'kp-btn kp-btn-danger',
            text: actionLoading === pkg.id ? 'در حال حذف...' : 'حذف بسته',
            attributes: { type: 'button', disabled: Boolean(actionLoading) },
        });
        button.addEventListener('click', () => void deletePackage(pkg.id, pkg.name));
        card.append(details, button);
        return card;
    }

    function render() {
        if (disposed) return;
        root.replaceChildren(title);

        const toast = renderToast();
        if (toast) root.append(toast);

        if (loading) {
            root.append(element('div', {
                className: 'kp-vanilla-location-loading',
                text: 'در حال بارگذاری بسته‌های نقشه آفلاین...',
                attributes: { role: 'status', 'aria-live': 'polite' },
            }));
            return;
        }

        if (status.childNodes.length) root.append(status);
        if (installed.length) {
            const summary = element('div', { className: 'kp-card kp-vanilla-installed-summary' });
            summary.append(
                element('span', { text: `${installed.length.toLocaleString('fa-IR')} بسته نصب‌شده` }),
                element('strong', { text: `حجم کل: ${getTotalPackagesSize(installed)}` }),
            );
            root.append(summary);
        }

        const grid = element('div', { className: 'kp-vanilla-package-grid' });
        available.forEach((pkg) => grid.append(renderPackage(pkg)));
        root.append(grid);

        const orphans = element('div', { className: 'kp-vanilla-orphaned-packages' });
        installed
            .filter((pkg) => !available.some((entry) => entry.id === pkg.id))
            .forEach((pkg) => orphans.append(renderOrphanedPackage(pkg)));
        root.append(orphans);

        if (installed.length === 0) {
            root.append(element('div', {
                className: 'kp-card kp-vanilla-offline-empty',
                text: available.length
                    ? 'هیچ بسته آفلاینی نصب نشده است. برای استفاده از نقشه بدون اینترنت، یکی از بسته‌های دارای مجوز را نصب کنید.'
                    : 'هیچ بسته آفلاینی نصب نشده و بسته‌ای در توزیع‌کنندهٔ نقشه پیکربندی‌شده منتشر نشده است.',
                attributes: { role: 'status' },
            }));
        }
    }

    async function loadPackages() {
        loading = true;
        status.replaceChildren();
        render();
        const errors = [];
        try {
            installed = await getStoredOfflinePackages();
        } catch (error) {
            errors.push(error instanceof Error ? error.message : 'خطا در بارگذاری بسته‌های نصب‌شده.');
        }
        try {
            available = await getOfflineMapCatalog();
        } catch (error) {
            errors.push(error instanceof Error ? error.message : 'فهرست بسته‌های نقشه در دسترس نیست.');
        }
        if (errors.length) {
            status.append(element('div', {
                className: 'kp-card kp-vanilla-offline-error',
                text: errors.join(' '),
                attributes: { role: 'alert' },
            }));
        }
        loading = false;
        render();
    }

    async function installPackage(pkg) {
        actionLoading = pkg.id;
        progress = { completed: 0, total: pkg.tileCount, bytesDownloaded: 0, totalBytes: pkg.sizeBytes };
        render();
        try {
            await installOfflineMap(pkg, (nextProgress) => {
                progress = nextProgress;
                render();
            });
            installed = await getStoredOfflinePackages();
            showToast(`بسته نقشه «${pkg.name}» با موفقیت نصب شد.`, 'success');
        } catch (error) {
            showToast(error instanceof Error ? error.message : `خطا در نصب بسته «${pkg.name}».`, 'error');
        } finally {
            actionLoading = null;
            progress = null;
            render();
        }
    }

    async function deletePackage(id, name) {
        actionLoading = id;
        render();
        try {
            await removeOfflineMap(id);
            installed = await getStoredOfflinePackages();
            showToast(`بسته نقشه «${name}» حذف شد.`, 'info');
        } catch (error) {
            showToast(error instanceof Error ? error.message : 'خطا در حذف بسته.', 'error');
        } finally {
            actionLoading = null;
            render();
        }
    }

    void loadPackages();
    return {
        element: root,
        destroy() {
            disposed = true;
        },
    };
}
