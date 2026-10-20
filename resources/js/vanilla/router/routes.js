import { renderShell } from '../ui/shell.js';
import { renderLandingPage } from '../landing/landing-page.js';
import { renderAuthPage } from '../auth/auth-page.js';
import { renderInitialSetupPage } from '../setup/initial-setup-page.js';
import { renderPanelsPage } from '../panels/panels-page.js';
import { renderLocationPage } from '../location/location-page.js';
import { apiClient } from '../api/client.js';
import { generateIdempotencyKey, getCurrentSelection } from '../../services/OfflineStorageService.js';
import { renderBookingsPage } from '../booking/booking-page.js';
import { renderNotificationsPage } from '../notifications/notifications-page.js';
import { renderAdminConsole } from '../admin/admin-console.js';
import { renderSupportPage } from '../support/support-page.js';

function createElement(tagName, options = {}) {
    const element = document.createElement(tagName);

    if (options.className) {
        element.className = options.className;
    }

    if (options.text) {
        element.textContent = options.text;
    }

    if (options.attributes) {
        Object.entries(options.attributes).forEach(([name, value]) => {
            if (value !== undefined && value !== null && value !== false) {
                element.setAttribute(name, value === true ? '' : String(value));
            }
        });
    }

    return element;
}

function apiErrorMessage(error, fallback) {
    if (error?.details && typeof error.details === 'object') {
        const validationMessage = Object.values(error.details)
            .flat()
            .find((message) => typeof message === 'string' && message.length > 0);
        if (validationMessage) return validationMessage;
    }

    return error instanceof Error ? error.message : fallback;
}

function createServiceCard(service) {
    const card = createElement('article', { className: 'kp-card kp-vanilla-service-card' });
    const details = createElement('div');
    details.append(
        createElement('h2', { className: 'kp-vanilla-service-name', text: service.title_fa }),
        createElement('p', { className: 'kp-vanilla-service-description', text: service.description || 'جزئیات خدمت هنگام ثبت برآورد نمایش داده می‌شود.' }),
    );

    const pricing = createElement('div', { className: 'kp-vanilla-service-pricing' });
    const estimate = createElement('div', { className: 'kp-vanilla-service-price-row' });
    estimate.append(
        createElement('span', { text: 'مدت تقریبی خدمت:' }),
        createElement('span', { text: `حدود ${Number(service.estimated_duration_minutes)} دقیقه` }),
    );
    const pricingNote = createElement('p', {
        className: 'kp-vanilla-service-description',
        text: 'برآورد تقریبی پس از تعیین محل و زمان، با تعرفهٔ فعال سرور محاسبه می‌شود.',
    });
    if (service.requires_ownership_proof) {
        pricing.append(createElement('p', {
            className: 'kp-vanilla-service-warning',
            text: 'پیش از درخواست این خدمت باید اختیار دسترسی به ملک یا خودرو تأیید شود.',
        }));
    }
    const requestButton = createElement('button', {
        className: 'kp-btn kp-btn-primary kp-vanilla-service-request',
        text: 'انتخاب این خدمت',
        attributes: { type: 'button', 'data-service-id': service.id },
    });
    pricing.append(estimate, pricingNote, requestButton);
    card.append(details, pricing);
    return card;
}

function createConfirmationDialog(service, onClose) {
    const controller = new AbortController();
    const overlay = createElement('div', {
        className: 'kp-modal-overlay kp-vanilla-modal-overlay',
        attributes: {
            role: 'dialog',
            'aria-modal': 'true',
            'aria-labelledby': 'kp-vanilla-modal-title',
            tabindex: '-1',
        },
    });
    const panel = createElement('div', { className: 'kp-modal-content' });
    const header = createElement('div', { className: 'kp-vanilla-modal-header' });
    const title = createElement('h2', {
        text: `انتخاب خدمت: ${service.title_fa}`,
        attributes: { id: 'kp-vanilla-modal-title' },
    });
    const closeButton = createElement('button', {
        className: 'kp-vanilla-modal-close',
        text: '✕',
        attributes: { type: 'button', 'aria-label': 'بستن پنجره' },
    });
    closeButton.addEventListener('click', onClose);
    header.append(title, closeButton);

    const content = createElement('div', { className: 'kp-vanilla-modal-body' });
    content.append(
        createElement('p', {
            text: 'مختصات و زمان را وارد کنید تا برآورد اولیه را سرور و بر اساس تعرفهٔ معتبر محاسبه کند. برآورد تقریبی است؛ انتخاب خدمت به‌تنهایی درخواست یا اعزام ایجاد نمی‌کند.',
        }),
    );
    const summary = createElement('div', { className: 'kp-vanilla-service-summary' });
    const nameRow = createElement('div');
    nameRow.append(
        createElement('span', { text: 'نوع خدمت:' }),
        createElement('strong', { text: service.title_fa }),
    );
    summary.append(nameRow);
    content.append(summary);
    content.append(createElement('a', {
        className: 'kp-btn kp-btn-secondary',
        text: 'انتخاب نقطه یا نشانی روی نقشه',
        attributes: { href: '/location?frontend=vanilla', 'data-router-link': '' },
    }));

    const estimateForm = createElement('form', { className: 'kp-vanilla-estimate-form' });
    const coordinateFields = createElement('div', { className: 'kp-vanilla-estimate-coordinates' });
    const latitudeGroup = createElement('div', { className: 'kp-input-group' });
    const latitudeLabel = createElement('label', {
        className: 'kp-label',
        text: 'عرض جغرافیایی (latitude)',
        attributes: { for: 'service-estimate-latitude' },
    });
    const latitudeInput = createElement('input', {
        className: 'kp-input',
        attributes: {
            id: 'service-estimate-latitude',
            name: 'latitude',
            type: 'number',
            min: '-90',
            max: '90',
            step: '0.0000001',
            required: true,
            inputmode: 'decimal',
        },
    });
    latitudeGroup.append(latitudeLabel, latitudeInput);
    const longitudeGroup = createElement('div', { className: 'kp-input-group' });
    const longitudeLabel = createElement('label', {
        className: 'kp-label',
        text: 'طول جغرافیایی (longitude)',
        attributes: { for: 'service-estimate-longitude' },
    });
    const longitudeInput = createElement('input', {
        className: 'kp-input',
        attributes: {
            id: 'service-estimate-longitude',
            name: 'longitude',
            type: 'number',
            min: '-180',
            max: '180',
            step: '0.0000001',
            required: true,
            inputmode: 'decimal',
        },
    });
    longitudeGroup.append(longitudeLabel, longitudeInput);
    coordinateFields.append(latitudeGroup, longitudeGroup);
    const scheduleGroup = createElement('div', { className: 'kp-input-group' });
    const urgencyLabel = createElement('label', {
        className: 'kp-label',
        text: 'فوریت یا زمان رزرو',
        attributes: { for: 'service-estimate-urgency' },
    });
    const urgencySelect = createElement('select', {
        className: 'kp-input',
        attributes: { id: 'service-estimate-urgency', name: 'urgency' },
    });
    urgencySelect.append(
        createElement('option', { text: 'درخواست فوری', attributes: { value: 'urgent' } }),
        createElement('option', { text: 'رزرو برای زمان مشخص', attributes: { value: 'scheduled' } }),
    );
    const scheduleLabel = createElement('label', {
        className: 'kp-label',
        text: 'زمان مراجعه',
        attributes: { for: 'service-estimate-scheduled-at' },
    });
    const scheduleInput = createElement('input', {
        className: 'kp-input',
        attributes: {
            id: 'service-estimate-scheduled-at',
            name: 'scheduled_at',
            type: 'datetime-local',
            disabled: true,
        },
    });
    scheduleGroup.append(urgencyLabel, urgencySelect, scheduleLabel, scheduleInput);
    const estimateButton = createElement('button', {
        className: 'kp-btn kp-btn-primary',
        text: 'محاسبهٔ برآورد تقریبی',
        attributes: { type: 'submit' },
    });
    const estimateStatus = createElement('p', {
        attributes: { 'aria-live': 'polite', role: 'status' },
    });
    estimateForm.append(coordinateFields, scheduleGroup, estimateButton, estimateStatus);

    const bookingPanel = createElement('section', {
        className: 'kp-vanilla-estimate-booking',
        attributes: { hidden: true, 'aria-label': 'ثبت پیش‌نویس درخواست' },
    });
    const addressGroup = createElement('div', { className: 'kp-input-group' });
    const addressLabel = createElement('label', {
        className: 'kp-label',
        text: 'نشانی دقیق',
        attributes: { for: 'service-estimate-address' },
    });
    const addressInput = createElement('textarea', {
        className: 'kp-input',
        attributes: {
            id: 'service-estimate-address',
            name: 'street_address',
            rows: 2,
            maxLength: 2000,
            required: true,
        },
    });
    addressGroup.append(addressLabel, addressInput);
    const notesGroup = createElement('div', { className: 'kp-input-group' });
    const notesLabel = createElement('label', {
        className: 'kp-label',
        text: 'توضیحات (اختیاری)',
        attributes: { for: 'service-estimate-notes' },
    });
    const notesInput = createElement('textarea', {
        className: 'kp-input',
        attributes: { id: 'service-estimate-notes', name: 'problem_description', rows: 2, maxLength: 5000 },
    });
    notesGroup.append(notesLabel, notesInput);
    bookingPanel.append(addressGroup, notesGroup);

    if (service.requires_ownership_proof) {
        content.append(createElement('p', {
            className: 'kp-vanilla-service-warning',
            text: 'برای خدمات ورود به ملک یا خودرو لازم است مشتری تأیید کند اختیار دسترسی را دارد؛ متخصص نیز باید اختیار را پیش از کار بررسی کند.',
        }));
        const propertyGroup = createElement('div', { className: 'kp-input-group' });
        const propertyLabel = createElement('label', {
            className: 'kp-label',
            text: 'نوع محل',
            attributes: { for: 'service-estimate-property-type' },
        });
        const propertyType = createElement('select', {
            className: 'kp-input',
            attributes: { id: 'service-estimate-property-type', name: 'property_type', required: true },
        });
        [
            ['residential', 'مسکونی'],
            ['commercial', 'تجاری'],
            ['vehicle', 'خودرو'],
            ['safe', 'گاوصندوق'],
        ].forEach(([value, label]) => {
            const option = createElement('option', { text: label, attributes: { value } });
            propertyType.append(option);
        });
        propertyGroup.append(propertyLabel, propertyType);
        const authorityLabel = createElement('label', { className: 'kp-checkbox-label' });
        const authorityInput = createElement('input', {
            attributes: { id: 'service-estimate-authority', name: 'ownership_attested', type: 'checkbox' },
        });
        authorityLabel.append(authorityInput, document.createTextNode(' تأیید می‌کنم اختیار دسترسی به این محل یا خودرو را دارم.'));
        bookingPanel.append(propertyGroup, authorityLabel);
    }

    const bookingButton = createElement('button', {
        className: 'kp-btn kp-btn-gold',
        text: 'تأیید برآورد و ثبت سفارش',
        attributes: { type: 'button', disabled: true },
    });
    const bookingStatus = createElement('p', { attributes: { 'aria-live': 'polite', role: 'status' } });
    bookingPanel.append(bookingButton, bookingStatus);
    const actions = createElement('div', { className: 'kp-vanilla-modal-actions' });
    const cancelButton = createElement('button', {
        className: 'kp-btn kp-btn-secondary',
        text: 'انصراف',
        attributes: { type: 'button' },
    });
    cancelButton.addEventListener('click', onClose);
    actions.append(cancelButton);
    panel.append(header, content, estimateForm, bookingPanel, actions);

    let estimateId = null;
    let idempotencyKey = null;
    let busy = false;
    const clearEstimate = () => {
        estimateId = null;
        idempotencyKey = null;
        bookingPanel.hidden = true;
        bookingButton.disabled = true;
        estimateStatus.textContent = '';
        estimateStatus.removeAttribute('role');
    };
    [latitudeInput, longitudeInput, scheduleInput, urgencySelect].forEach((input) => {
        input.addEventListener('input', clearEstimate);
    });
    urgencySelect.addEventListener('change', () => {
        const scheduled = urgencySelect.value === 'scheduled';
        scheduleInput.disabled = !scheduled;
        scheduleInput.required = scheduled;
        if (!scheduled) scheduleInput.value = '';
        clearEstimate();
    });
    if (globalThis.indexedDB) {
        void getCurrentSelection().then((selection) => {
            if (controller.signal.aborted || !selection) return;
            latitudeInput.value = Number(selection.lat).toFixed(7);
            longitudeInput.value = Number(selection.lng).toFixed(7);
            if (selection.address && !addressInput.value) addressInput.value = selection.address;
            estimateStatus.textContent = 'آخرین نقطهٔ ذخیره‌شده از نقشه در فرم قرار گرفت؛ مختصات را پیش از برآورد بررسی کنید.';
        }).catch((error) => {
            if (!controller.signal.aborted) {
                estimateStatus.setAttribute('role', 'alert');
                estimateStatus.textContent = error instanceof Error
                    ? `بارگذاری نقطهٔ ذخیره‌شده ناموفق بود: ${error.message}`
                    : 'بارگذاری نقطهٔ ذخیره‌شده ناموفق بود؛ مختصات را دستی وارد کنید.';
            }
        });
    }
    estimateForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy) return;
        clearEstimate();
        busy = true;
        estimateButton.disabled = true;
        estimateButton.textContent = 'در حال محاسبه...';
        estimateStatus.setAttribute('role', 'status');
        estimateStatus.textContent = '';
        const body = {
            service_id: Number(service.id),
            latitude: Number(latitudeInput.value),
            longitude: Number(longitudeInput.value),
            urgency: urgencySelect.value,
        };
        if (scheduleInput.value) body.scheduled_at = scheduleInput.value;
        try {
            const response = await apiClient.request('pricing/estimates', {
                method: 'POST',
                body,
                signal: controller.signal,
            });
            estimateId = response.data.estimate_id;
            idempotencyKey = generateIdempotencyKey();
            const formatPrice = (value) => `${Number(value).toLocaleString('fa-IR')} ریال`;
            estimateStatus.textContent = `برآورد تقریبی سرور: از ${formatPrice(response.data.minimum_total)} تا ${formatPrice(response.data.maximum_total)}؛ تعرفهٔ نسخهٔ ${response.data.tariff_version} (${response.data.service_area}).`;
            bookingPanel.hidden = false;
            bookingButton.disabled = !addressInput.value.trim();
            bookingStatus.textContent = '';
        } catch (error) {
            if (!controller.signal.aborted) {
                estimateStatus.setAttribute('role', 'alert');
                estimateStatus.textContent = apiErrorMessage(error, 'محاسبهٔ برآورد ناموفق بود.');
            }
        } finally {
            busy = false;
            estimateButton.disabled = false;
            estimateButton.textContent = 'محاسبهٔ برآورد تقریبی';
        }
    });
    addressInput.addEventListener('input', () => {
        bookingButton.disabled = !estimateId || !addressInput.value.trim() || busy;
    });
    bookingButton.addEventListener('click', async () => {
        if (!estimateId || !addressInput.value.trim() || busy) return;
        const authorityInput = bookingPanel.querySelector('[name="ownership_attested"]');
        const propertyType = bookingPanel.querySelector('[name="property_type"]');
        if (authorityInput && !authorityInput.checked) {
            bookingStatus.setAttribute('role', 'alert');
            bookingStatus.textContent = 'برای ثبت درخواست باید اختیار دسترسی را تأیید کنید.';
            return;
        }
        busy = true;
        bookingButton.disabled = true;
        bookingButton.textContent = 'در حال ثبت سفارش...';
        bookingStatus.setAttribute('role', 'status');
        bookingStatus.textContent = '';
        let bookingCreated = false;
        const body = {
            estimate_id: estimateId,
            idempotency_key: idempotencyKey,
            street_address: addressInput.value.trim(),
            problem_description: notesInput.value.trim() || undefined,
        };
        if (authorityInput) {
            body.ownership_attested = true;
            body.property_type = propertyType.value;
        }
        try {
            const response = await apiClient.request('bookings', {
                method: 'POST',
                body,
                headers: { 'Idempotency-Key': idempotencyKey },
                signal: controller.signal,
            });
            bookingCreated = true;
            bookingStatus.replaceChildren(
                document.createTextNode(`سفارش ${response.data.booking_code} در وضعیت «پیش‌نویس» ثبت شد؛ هنوز متخصصی اعزام نشده است. `),
                createElement('a', {
                    className: 'kp-btn kp-btn-secondary',
                    text: 'مشاهده و پیگیری سفارش',
                    attributes: {
                        href: `/orders?frontend=vanilla&code=${encodeURIComponent(response.data.booking_code)}`,
                        'data-router-link': '',
                    },
                }),
            );
            addressInput.disabled = true;
            notesInput.disabled = true;
            if (authorityInput) authorityInput.disabled = true;
            bookingButton.textContent = 'سفارش ثبت شد';
        } catch (error) {
            if (!controller.signal.aborted) {
                bookingStatus.setAttribute('role', 'alert');
                bookingStatus.textContent = error?.status === 422
                    ? `${apiErrorMessage(error, 'اطلاعات سفارش معتبر نیست.')} اطلاعات را اصلاح کنید یا برآورد تازه بگیرید.`
                    : `${apiErrorMessage(error, 'ثبت درخواست ناموفق بود.')} برای جلوگیری از ثبت تکراری، تلاش دوباره با همین اطلاعات انجام می‌شود.`;
                bookingButton.disabled = false;
                if (error?.status === 422) {
                    const refreshEstimate = createElement('button', {
                        className: 'kp-btn kp-btn-secondary',
                        text: 'محاسبهٔ مجدد برآورد',
                        attributes: { type: 'button' },
                    });
                    refreshEstimate.addEventListener('click', () => {
                        clearEstimate();
                        estimateForm.requestSubmit();
                    });
                    bookingStatus.append(refreshEstimate);
                } else {
                    addressInput.disabled = true;
                    notesInput.disabled = true;
                    if (authorityInput) authorityInput.disabled = true;
                }
                if (error?.status === 409) {
                    bookingStatus.append(createElement('a', {
                        className: 'kp-btn kp-btn-secondary',
                        text: 'مشاهده سفارش‌های ثبت‌شده',
                        attributes: { href: '/orders?frontend=vanilla', 'data-router-link': '' },
                    }));
                }
            }
        } finally {
            busy = false;
            bookingButton.textContent = bookingCreated
                ? 'سفارش ثبت شد'
                : bookingStatus.getAttribute('role') === 'alert'
                    ? 'تلاش دوباره برای ثبت سفارش'
                    : 'تأیید برآورد و ثبت سفارش';
        }
    });

    overlay.append(panel);
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            onClose();
        }
    });
    overlay.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            onClose();
        }
    });

    return { overlay, closeButton, destroy: () => controller.abort() };
}

function renderServiceSelection(root) {
    const page = createElement('main', { className: 'kp-vanilla-service-page' });
    const intro = createElement('header', { className: 'kp-vanilla-service-intro' });
    intro.append(
        createElement('h1', {
            text: 'انتخاب خدمت کلیدسازی و امنیت',
        }),
        createElement('p', {
            text: 'نوع مشکل یا نیاز خود را انتخاب نمایید تا نزدیک‌ترین متخصص مجهز به ابزار مناسب به محل شما اعزام گردد.',
        }),
    );
    const filters = createElement('div', {
        className: 'kp-vanilla-service-filters',
        attributes: { role: 'group', 'aria-label': 'دسته‌بندی خدمات' },
    });
    const grid = createElement('section', {
        className: 'kp-vanilla-service-grid',
        attributes: { 'aria-label': 'خدمات موجود' },
    });
    page.append(intro, filters, grid);
    renderShell(root, 'booking', page);

    const controller = new AbortController();
    let selectedCategory = 'all';
    let categories = [];
    let services = [];
    let activeDialog;

    function renderServices() {
        filters.replaceChildren();
        [{ key: 'all', label: 'همه خدمات' }, ...categories.map((category) => ({
            key: category.slug,
            label: category.name_fa,
        }))].forEach((category) => {
            const button = createElement('button', {
                className: `kp-btn kp-vanilla-category${selectedCategory === category.key ? ' is-active' : ''}`,
                text: category.label,
                attributes: {
                    type: 'button',
                    'aria-pressed': selectedCategory === category.key ? 'true' : 'false',
                },
            });
            button.addEventListener('click', () => {
                selectedCategory = category.key;
                renderServices();
            });
            filters.append(button);
        });

        grid.replaceChildren();
        const visibleServices = services
            .filter((service) => selectedCategory === 'all' || service.category_slug === selectedCategory);
        if (visibleServices.length === 0) {
            grid.append(createElement('p', {
                className: 'kp-card kp-vanilla-status',
                attributes: { role: 'status' },
                text: 'در این دسته خدمت فعالی ثبت نشده است.',
            }));
        }
        visibleServices
            .forEach((service) => {
                const card = createServiceCard(service);
                card.querySelector('[data-service-id]').addEventListener('click', (event) => {
                    const trigger = event.currentTarget;
                    const close = () => {
                        activeDialog.destroy();
                        activeDialog.overlay.remove();
                        trigger.focus();
                    };
                    activeDialog = createConfirmationDialog(service, close);
                    page.append(activeDialog.overlay);
                    activeDialog.closeButton.focus();
                });
                grid.append(card);
            });
    }

    const loading = createElement('p', {
        className: 'kp-card kp-vanilla-status',
        attributes: { role: 'status' },
        text: 'در حال دریافت فهرست خدمات…',
    });
    grid.append(loading);

    async function loadServices() {
        grid.replaceChildren(createElement('p', {
            className: 'kp-card kp-vanilla-status',
            attributes: { role: 'status' },
            text: 'در حال دریافت فهرست خدمات…',
        }));
        try {
            const response = await apiClient.request('services', { signal: controller.signal });
            if (controller.signal.aborted) return;
            categories = Array.isArray(response?.data) ? response.data : [];
            services = categories.flatMap((category) =>
                (Array.isArray(category.services) ? category.services : []).map((service) => ({
                    ...service,
                    category_slug: category.slug,
                }))
            );
            renderServices();
        } catch (error) {
            if (controller.signal.aborted) return;
            filters.replaceChildren();
            grid.replaceChildren();
            const message = createElement('p', {
                className: 'kp-card kp-vanilla-status',
                attributes: { role: 'alert' },
                text: apiErrorMessage(error, 'دریافت فهرست خدمات ناموفق بود.'),
            });
            const retry = createElement('button', {
                className: 'kp-btn kp-btn-secondary',
                text: 'تلاش دوباره',
                attributes: { type: 'button' },
            });
            retry.addEventListener('click', loadServices);
            grid.append(message, retry);
        }
    }

    loadServices();
    return () => {
        controller.abort();
        activeDialog?.destroy();
    };
}

export function getVanillaDestinationForRole(role) {
    if (['super_admin', 'operator', 'dispatcher', 'support', 'accountant', 'content_manager'].includes(role)) {
        return '/admin?frontend=vanilla';
    }
    return role === 'technician' ? '/panels?frontend=vanilla' : '/booking?frontend=vanilla';
}

export const routes = {
    '/': renderLandingPage,
    '/booking': renderServiceSelection,
    '/orders': renderBookingsPage,
    '/support': renderSupportPage,
    '/notifications': (root) => {
        const page = document.createElement('div');
        const cleanup = renderNotificationsPage(page);
        renderShell(root, 'notifications', page.firstElementChild);
        return cleanup;
    },
    '/auth': (root) => {
        const page = document.createElement('div');
        renderAuthPage(page, {
            onLoginSuccess: (role) => {
                window.location.assign(getVanillaDestinationForRole(role));
            },
        });
        renderShell(root, 'auth', page.firstElementChild);
    },
    '/setup': (root) => {
        const page = document.createElement('div');
        const cleanup = renderInitialSetupPage(page);
        renderShell(root, 'setup', page.firstElementChild);
        return cleanup;
    },
    '/panels': (root) => {
        const page = document.createElement('div');
        renderPanelsPage(page);
        renderShell(root, 'panels', page.firstElementChild);
    },
    '/admin': (root) => {
        const page = document.createElement('div');
        const cleanup = renderAdminConsole(page);
        renderShell(root, 'admin', page.firstElementChild);
        return cleanup;
    },
    '/location': (root) => {
        const page = document.createElement('div');
        const cleanup = renderLocationPage(page);
        renderShell(root, 'location', page.firstElementChild);
        return cleanup;
    },
};

export function renderNotFound(root, path) {
    const section = createElement('main', { className: 'kp-card kp-vanilla-status' });
    section.setAttribute('role', 'status');
    section.append(
        createElement('h1', { className: 'kp-vanilla-title', text: 'صفحه پیدا نشد.' }),
        createElement('p', { text: `مسیر «${path}» در entry آزمایشی تعریف نشده است.` }),
    );
    const link = createElement('a', {
        className: 'kp-btn kp-btn-secondary',
        text: 'بازگشت به صفحهٔ پایه',
        attributes: { href: '/?frontend=vanilla', 'data-router-link': '' },
    });
    section.append(link);
    renderShell(root, '', section);
}
