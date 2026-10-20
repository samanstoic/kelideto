import {
    deleteDraftBooking,
    deleteLocation,
    generateIdempotencyKey,
    getAllDraftBookings,
    getCurrentSelection,
    getSavedLocations,
    saveCurrentSelection,
    saveDraftBooking,
    saveLocation,
} from '../../services/OfflineStorageService.js';
import { syncAllDrafts } from '../../services/OfflineSyncService.js';
import { createLocationMap, createLocationMapElements } from './location-map.js';
import { createOfflineMapManager } from './offline-map-manager.js';

const tabs = [
    { id: 'map', label: '📍 انتخاب موقعیت' },
    { id: 'offline', label: '🗺️ بسته‌های آفلاین' },
    { id: 'drafts', label: '📝 پیش‌نویس‌ها' },
];

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

function setButtonLoading(button, loading, loadingText) {
    if (!button.dataset.defaultLabel) {
        button.dataset.defaultLabel = button.textContent;
    }
    button.disabled = loading;
    button.textContent = loading ? loadingText : button.dataset.defaultLabel;
}

function renderLocationCard(location, onShow, onDelete) {
    const card = element('article', { className: 'kp-vanilla-saved-location' });
    const details = element('div');
    details.append(
        element('strong', { text: location.label }),
        element('span', {
            className: 'kp-vanilla-saved-coordinates',
            text: `(${location.lat.toFixed(4)}, ${location.lng.toFixed(4)})`,
            attributes: { dir: 'ltr' },
        }),
    );
    const actions = element('div', { className: 'kp-vanilla-saved-actions' });
    const show = element('button', {
        className: 'kp-vanilla-link-button',
        text: 'نمایش',
        attributes: { type: 'button' },
    });
    show.addEventListener('click', onShow);
    const remove = element('button', {
        className: 'kp-vanilla-delete-button',
        text: 'حذف',
        attributes: { type: 'button', 'aria-label': `حذف موقعیت ${location.label}` },
    });
    remove.addEventListener('click', onDelete);
    actions.append(show, remove);
    card.append(details, actions);
    return card;
}

function renderDraftCard(draft, onDelete, onCreateRevision) {
    const card = element('article', { className: 'kp-card kp-vanilla-draft-card' });
    const heading = element('div', { className: 'kp-vanilla-draft-heading' });
    const nameStatus = element('div');
    nameStatus.append(
        element('strong', { text: draft.address || 'بدون آدرس متنی' }),
        element('span', {
            className: `kp-vanilla-sync-status${draft.synced ? ' is-synced' : ''}${draft.conflict ? ' is-conflict' : ''}`,
            text: draft.synced ? 'همگام‌شده' : draft.conflict ? 'تعارض؛ نیازمند بررسی' : 'در انتظار همگام‌سازی',
        }),
    );
    const remove = element('button', {
        className: 'kp-vanilla-delete-button',
        text: 'حذف',
        attributes: { type: 'button', 'aria-label': 'حذف پیش‌نویس' },
    });
    remove.addEventListener('click', onDelete);
    heading.append(nameStatus, remove);

    const details = element('div', { className: 'kp-vanilla-draft-details' });
    details.append(element('span', {
        text: `📍 ${draft.lat.toFixed(6)}, ${draft.lng.toFixed(6)}`,
        attributes: { dir: 'ltr' },
    }));
    if (draft.notes) details.append(element('span', { text: `📝 ${draft.notes}` }));
    details.append(element('span', {
        text: `🔑 کلید یکتا: ${draft.idempotencyKey}`,
    }));
    if (draft.lastSyncError) {
        details.append(element('span', {
            className: 'kp-vanilla-draft-error',
            text: `⚠ خطای آخر: ${draft.lastSyncError} (تلاش ${(Number(draft.syncAttempts) || 0).toLocaleString('fa-IR')})`,
        }));
    }
    card.append(heading, details);
    if (draft.conflict) {
        const createRevision = element('button', {
            className: 'kp-btn kp-btn-secondary',
            text: 'ساخت پیش‌نویس تازه با همین اطلاعات',
            attributes: { type: 'button' },
        });
        createRevision.addEventListener('click', () => onCreateRevision(draft));
        card.append(createRevision);
    }
    return card;
}

export function renderLocationPage(root) {
    let activeTab = 'map';
    let selectedLocation = null;
    let savedLocations = [];
    let drafts = [];
    let syncing = false;
    let disposed = false;
    let selectionLoaded = false;
    let selectionChangedBeforeLoad = false;
    let pendingSelection;
    let saveSelectionTimer;
    let mapMountTimer;
    let mapController;
    let offlineManager;
    const handleSyncedDrafts = (event) => {
        if (event.detail?.synced > 0 || event.detail?.conflicts > 0) {
            void refreshDrafts().catch((error) => {
                notify(error instanceof Error ? error.message : 'خواندن پیش‌نویس‌ها ناموفق بود.', 'error');
            });
        }
    };
    window.addEventListener('keypro:drafts-synced', handleSyncedDrafts);
    const savedActions = new Set();
    const draftActions = new Set();

    const page = element('main', { className: 'kp-vanilla-location-page' });
    const toastRegion = element('div', {
        className: 'kp-vanilla-location-toast-region',
        attributes: { 'aria-live': 'polite' },
    });
    const pageHeader = element('header', { className: 'kp-vanilla-location-header' });
    pageHeader.append(
        element('h1', { text: 'انتخاب موقعیت و نقشه آفلاین' }),
        element('p', {
            text: 'محل خود را روی نقشه انتخاب کنید یا از GPS استفاده نمایید. پیش‌نویس و مختصات تا مدت محدود روی همین دستگاه نگهداری می‌شوند. همگام‌سازی به‌تنهایی سفارش واقعی یا اعزام متخصص ایجاد نمی‌کند.',
        }),
    );

    const tabNavigation = element('nav', {
        className: 'kp-vanilla-location-tabs',
        attributes: { 'aria-label': 'بخش‌های موقعیت و نقشه' },
    });
    const tabButtons = new Map();
    tabs.forEach((tab) => {
        const button = element('button', {
            className: 'kp-btn kp-vanilla-location-tab',
            text: tab.label,
            attributes: { type: 'button', 'aria-controls': `kp-location-${tab.id}` },
        });
        button.addEventListener('click', () => void selectTab(tab.id));
        tabButtons.set(tab.id, button);
        tabNavigation.append(button);
    });

    const mapPanel = element('section', {
        className: 'kp-vanilla-location-panel',
        attributes: { id: 'kp-location-map', 'aria-label': 'انتخاب موقعیت' },
    });
    const mapElements = createLocationMapElements();
    const locationActions = element('div', { className: 'kp-vanilla-location-actions' });
    const saveLocationButton = element('button', {
        className: 'kp-btn kp-btn-gold',
        text: '💾 ذخیره موقعیت فعلی',
        attributes: { type: 'button' },
    });
    saveLocationButton.addEventListener('click', () => void saveSelectedLocation());
    locationActions.append(saveLocationButton);

    const draftForm = element('form', { className: 'kp-card kp-vanilla-draft-form' });
    const draftTitle = element('h2', { text: 'ذخیرهٔ پیش‌نویس درخواست (حتی در حالت آفلاین)' });
    const draftNotice = element('p', {
        className: 'kp-vanilla-draft-notice',
        text: 'ذخیره یا همگام‌سازی پیش‌نویس به معنی ثبت سفارش عملیاتی یا اعزام فوری متخصص نیست. برای ثبت سفارش واقعی باید پس از اتصال، روند سفارش را آنلاین تکمیل کنید.',
    });
    const notesGroup = element('div', { className: 'kp-input-group' });
    const notesLabel = element('label', {
        className: 'kp-label',
        text: 'توضیحات مشکل یا درخواست',
        attributes: { for: 'draft-notes' },
    });
    const notesInput = element('textarea', {
        className: 'kp-input',
        attributes: {
            id: 'draft-notes',
            placeholder: 'مثال: درب ضدسرقت منزل قفل شده، کلید ندارم',
            rows: 3,
        },
    });
    notesGroup.append(notesLabel, notesInput);
    const saveDraftButton = element('button', {
        className: 'kp-btn kp-btn-primary',
        text: 'ذخیره پیش‌نویس درخواست',
        attributes: { type: 'submit' },
    });
    draftForm.append(draftTitle, draftNotice, notesGroup, saveDraftButton);
    draftForm.addEventListener('submit', (event) => {
        event.preventDefault();
        void saveDraft();
    });

    const savedSection = element('section', {
        className: 'kp-card kp-vanilla-saved-section',
        attributes: { hidden: true, 'aria-labelledby': 'kp-saved-locations-title' },
    });
    savedSection.append(element('h2', {
        text: '📌 موقعیت‌های ذخیره‌شده',
        attributes: { id: 'kp-saved-locations-title' },
    }));
    const savedList = element('div', { className: 'kp-vanilla-saved-list' });
    savedSection.append(savedList);
    mapPanel.append(mapElements.root, locationActions, draftForm, savedSection);

    const offlinePanel = element('section', {
        className: 'kp-vanilla-location-panel',
        attributes: { id: 'kp-location-offline', hidden: true, 'aria-label': 'بسته‌های نقشه آفلاین' },
    });
    const draftsPanel = element('section', {
        className: 'kp-vanilla-location-panel kp-vanilla-drafts-panel',
        attributes: { id: 'kp-location-drafts', hidden: true, 'aria-label': 'پیش‌نویس‌های درخواست' },
    });
    const draftsHeader = element('header', { className: 'kp-vanilla-drafts-header' });
    draftsHeader.append(element('h2', { text: 'پیش‌نویس‌های درخواست' }));
    const syncButton = element('button', {
        className: 'kp-btn kp-btn-primary',
        text: '🔄 همگام‌سازی دستی',
        attributes: { type: 'button' },
    });
    syncButton.addEventListener('click', () => void manualSync());
    draftsHeader.append(syncButton);
    const draftsList = element('div', { className: 'kp-vanilla-drafts-list' });
    draftsPanel.append(draftsHeader, draftsList);

    page.append(toastRegion, pageHeader, tabNavigation, mapPanel, offlinePanel, draftsPanel);
    root.append(page);

    function notify(message, type = 'info') {
        if (disposed) return;
        const toast = element('div', {
            className: `kp-toast kp-toast-${type}`,
            attributes: { role: type === 'error' ? 'alert' : 'status' },
        });
        toast.append(element('span', {
            text: type === 'success' ? '✓' : type === 'error' ? '⚠' : 'ℹ',
            attributes: { 'aria-hidden': 'true' },
        }));
        toast.append(element('span', { text: message }));
        const close = element('button', {
            className: 'kp-vanilla-toast-close',
            text: '✕',
            attributes: { type: 'button', 'aria-label': 'بستن پیام' },
        });
        close.addEventListener('click', () => toast.remove());
        toast.append(close);
        toastRegion.replaceChildren(toast);
    }

    function renderTabs() {
        tabButtons.forEach((button, tabId) => {
            const selected = activeTab === tabId;
            button.classList.toggle('is-active', selected);
            button.setAttribute('aria-current', selected ? 'page' : 'false');
        });
        mapPanel.hidden = activeTab !== 'map';
        offlinePanel.hidden = activeTab !== 'offline';
        draftsPanel.hidden = activeTab !== 'drafts';
    }

    async function refreshDrafts() {
        drafts = await getAllDraftBookings();
        renderDrafts();
        renderTabs();
    }

    function renderSavedLocations() {
        savedList.replaceChildren();
        savedSection.hidden = savedLocations.length === 0;
        savedLocations.forEach((location) => {
            const row = renderLocationCard(
                location,
                () => {
                    selectedLocation = {
                        lat: location.lat,
                        lng: location.lng,
                        address: location.address,
                        source: location.source,
                        accuracyMeters: location.accuracyMeters,
                    };
                    mapController?.setLocation(selectedLocation);
                    void selectTab('map');
                },
                () => void deleteSavedLocation(location.id),
            );
            const remove = row.querySelector('.kp-vanilla-delete-button');
            remove.disabled = savedActions.has(location.id);
            savedList.append(row);
        });
    }

    function renderDrafts() {
        draftsList.replaceChildren();
        const unsyncedCount = drafts.filter((draft) => !draft.synced).length;
        const draftsTab = tabButtons.get('drafts');
        draftsTab.textContent = `📝 پیش‌نویس‌ها (${unsyncedCount.toLocaleString('fa-IR')})`;
        syncButton.disabled = syncing;
        syncButton.textContent = syncing ? '🔄 در حال همگام‌سازی...' : '🔄 همگام‌سازی دستی';
        if (!drafts.length) {
            draftsList.append(element('div', {
                className: 'kp-card kp-vanilla-empty-state',
                text: 'هیچ پیش‌نویسی ثبت نشده است.',
                attributes: { role: 'status' },
            }));
            return;
        }
        drafts.forEach((draft) => {
            const card = renderDraftCard(
                draft,
                () => void deleteDraft(draft.idempotencyKey),
                (conflictedDraft) => void createDraftRevision(conflictedDraft),
            );
            const remove = card.querySelector('.kp-vanilla-delete-button');
            remove.disabled = draftActions.has(draft.idempotencyKey);
            draftsList.append(card);
        });
    }

    function scheduleCurrentSelectionSave(location) {
        selectedLocation = { ...location };
        pendingSelection = { ...location };
        if (!selectionLoaded) {
            selectionChangedBeforeLoad = true;
            return;
        }
        window.clearTimeout(saveSelectionTimer);
        saveSelectionTimer = window.setTimeout(() => {
            void saveCurrentSelection(pendingSelection).catch((error) => {
                notify(error instanceof Error ? error.message : 'ذخیرهٔ نقطهٔ انتخاب‌شده ناموفق بود.', 'error');
            });
        }, 250);
    }

    async function saveSelectedLocation() {
        if (!selectedLocation) {
            notify('لطفاً ابتدا یک نقطه روی نقشه انتخاب کنید.', 'error');
            return;
        }
        if (!Number.isFinite(selectedLocation.lat) || !Number.isFinite(selectedLocation.lng)) {
            notify('برای ذخیرهٔ موقعیت، مختصات معتبر انتخاب کنید.', 'error');
            return;
        }
        setButtonLoading(saveLocationButton, true, 'در حال ذخیره...');
        const location = {
            id: `loc-${Date.now()}`,
            label: selectedLocation.address || `نقطه ${(savedLocations.length + 1).toLocaleString('fa-IR')}`,
            lat: selectedLocation.lat,
            lng: selectedLocation.lng,
            address: selectedLocation.address,
            source: selectedLocation.source,
            accuracyMeters: selectedLocation.accuracyMeters,
            createdAt: new Date().toISOString(),
        };
        try {
            await saveLocation(location);
            savedLocations = [...savedLocations, location];
            renderSavedLocations();
            notify('موقعیت با موفقیت ذخیره شد.', 'success');
        } catch {
            notify('خطا در ذخیره موقعیت.', 'error');
        } finally {
            setButtonLoading(saveLocationButton, false, '');
        }
    }

    async function saveDraft() {
        if (!selectedLocation) {
            notify('لطفاً ابتدا موقعیت خود را روی نقشه انتخاب کنید.', 'error');
            return;
        }
        if (!Number.isFinite(selectedLocation.lat) || !Number.isFinite(selectedLocation.lng)) {
            notify('برای ذخیرهٔ پیش‌نویس، ابتدا نقطه‌ای روی نقشه انتخاب یا مختصات را وارد کنید.', 'error');
            return;
        }
        setButtonLoading(saveDraftButton, true, 'در حال ذخیره...');
        const draft = {
            idempotencyKey: generateIdempotencyKey(),
            serviceId: 'pending-selection',
            serviceName: 'درخواست اولیه',
            lat: selectedLocation.lat,
            lng: selectedLocation.lng,
            address: selectedLocation.address,
            locationSource: selectedLocation.source,
            accuracyMeters: selectedLocation.accuracyMeters,
            notes: notesInput.value,
            phone: '',
            createdAt: new Date().toISOString(),
            synced: false,
            syncAttempts: 0,
            lastSyncError: null,
        };
        try {
            await saveDraftBooking(draft);
            drafts = [...drafts, draft];
            notesInput.value = '';
            renderDrafts();
            notify('پیش‌نویس درخواست ذخیره شد. پس از اتصال به اینترنت همگام‌سازی خواهد شد.', 'success');
        } catch {
            notify('خطا در ذخیره پیش‌نویس.', 'error');
        } finally {
            setButtonLoading(saveDraftButton, false, '');
        }
    }

    async function deleteDraft(key) {
        if (draftActions.has(key)) return;
        draftActions.add(key);
        renderDrafts();
        try {
            await deleteDraftBooking(key);
            drafts = drafts.filter((draft) => draft.idempotencyKey !== key);
            notify('پیش‌نویس حذف شد.', 'info');
        } catch {
            notify('خطا در حذف پیش‌نویس.', 'error');
        } finally {
            draftActions.delete(key);
            renderDrafts();
        }
    }

    async function createDraftRevision(conflictedDraft) {
        const revision = {
            ...conflictedDraft,
            idempotencyKey: generateIdempotencyKey(),
            createdAt: new Date().toISOString(),
            synced: false,
            conflict: false,
            syncStatus: 'queued',
            syncAttempts: 0,
            lastSyncError: null,
            nextRetryAt: null,
        };
        try {
            await saveDraftBooking(revision);
            drafts = [...drafts, revision];
            renderDrafts();
            notify('پیش‌نویس تازه با شناسهٔ یکتا ساخته شد. پیش‌نویس متعارض قبلی برای بررسی حفظ شده است.', 'success');
        } catch (error) {
            notify(error instanceof Error ? error.message : 'ساخت پیش‌نویس تازه ناموفق بود.', 'error');
        }
    }

    async function deleteSavedLocation(id) {
        if (savedActions.has(id)) return;
        savedActions.add(id);
        renderSavedLocations();
        try {
            await deleteLocation(id);
            savedLocations = savedLocations.filter((location) => location.id !== id);
        } catch {
            notify('خطا در حذف موقعیت.', 'error');
        } finally {
            savedActions.delete(id);
            renderSavedLocations();
        }
    }

    async function manualSync() {
        if (syncing) return;
        if (!navigator.onLine) {
            notify('اتصال اینترنت برقرار نیست. همگام‌سازی پس از اتصال مجدد انجام خواهد شد.', 'error');
            return;
        }
        syncing = true;
        renderDrafts();
        try {
            const result = await syncAllDrafts();
            await refreshDrafts();
            if (result.synced > 0) {
                notify(`${result.synced.toLocaleString('fa-IR')} پیش‌نویس با موفقیت همگام‌سازی شد.`, 'success');
            } else if (result.total === 0) {
                notify('هیچ پیش‌نویس همگام‌نشده‌ای یافت نشد.', 'info');
            } else if (result.conflicts > 0) {
                notify(`${result.conflicts.toLocaleString('fa-IR')} پیش‌نویس به‌دلیل تعارض متوقف شد؛ آن را بررسی و در صورت نیاز با شناسهٔ تازه بازسازی کنید.`, 'error');
            } else {
                notify(`${result.failed.toLocaleString('fa-IR')} پیش‌نویس در همگام‌سازی دچار خطا شد.`, 'error');
            }
        } catch {
            notify('خطا در همگام‌سازی.', 'error');
        } finally {
            syncing = false;
            renderDrafts();
        }
    }

    async function selectTab(tabId) {
        activeTab = tabId;
        renderTabs();
        if (tabId === 'map') {
            if (!mapController) {
                mapController = createLocationMap(mapElements.map, mapElements, {
                    initialLocation: selectedLocation,
                    onLocationChange: scheduleCurrentSelectionSave,
                });
                if (!selectedLocation) {
                    selectedLocation = mapController.getLocation();
                }
            } else {
                mapController.invalidateSize();
                void mapController.refreshOfflinePackages();
            }
        } else if (tabId === 'drafts') {
            try {
                drafts = await getAllDraftBookings();
                renderDrafts();
            } catch (error) {
                notify(error instanceof Error ? error.message : 'خواندن پیش‌نویس‌ها ناموفق بود.', 'error');
            }
        } else if (tabId === 'offline' && !offlineManager) {
            offlineManager = createOfflineMapManager();
            offlinePanel.append(offlineManager.element);
        }
    }

    renderTabs();
    renderDrafts();
    void selectTab('map');
    mapMountTimer = window.setTimeout(() => {
        if (!disposed) mapController?.invalidateSize();
    }, 0);
    void Promise.all([getSavedLocations(), getCurrentSelection()])
        .then(([locations, current]) => {
            if (disposed) return;
            savedLocations = locations;
            renderSavedLocations();
            if (current && !selectionChangedBeforeLoad) {
                selectedLocation = {
                    lat: current.lat,
                    lng: current.lng,
                    address: current.address,
                    source: current.source,
                    accuracyMeters: current.accuracyMeters,
                };
            }
            selectionLoaded = true;
            if (current && !selectionChangedBeforeLoad) {
                mapController?.setLocation(selectedLocation);
            } else if (selectedLocation) {
                scheduleCurrentSelectionSave(selectedLocation);
            }
        })
        .catch((error) => {
            notify(error instanceof Error ? error.message : 'خواندن اطلاعات آفلاین ناموفق بود.', 'error');
        });

    return () => {
        disposed = true;
        window.clearTimeout(saveSelectionTimer);
        window.clearTimeout(mapMountTimer);
        window.removeEventListener('keypro:drafts-synced', handleSyncedDrafts);
        mapController?.destroy();
        offlineManager?.destroy();
    };
}
