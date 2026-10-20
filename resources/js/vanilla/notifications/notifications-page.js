import { apiClient } from '../api/client.js';

const EVENT_LABELS = {
    'booking.status_changed': 'تغییر وضعیت سفارش',
    'booking.cancelled': 'لغو سفارش',
    'booking.delayed': 'تأخیر سفارش',
    'booking.rescheduled': 'تغییر زمان سفارش',
    'dispatch.offer.issued': 'پیشنهاد مأموریت',
};
const CHANNEL_LABELS = {
    in_app: 'درون‌برنامه‌ای',
    sms: 'پیامک',
    web_push: 'اعلان وب',
};
const EVENTS = Object.keys(EVENT_LABELS);
const CHANNELS = Object.keys(CHANNEL_LABELS);

function element(tagName, { className, text, attributes } = {}) {
    const node = document.createElement(tagName);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    if (attributes) {
        Object.entries(attributes).forEach(([name, value]) => {
            if (value !== undefined && value !== null && value !== false) {
                node.setAttribute(name, value === true ? '' : String(value));
            }
        });
    }
    return node;
}

function decodeVapidKey(value) {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
    const raw = globalThis.atob(base64);
    return Uint8Array.from(raw, (character) => character.charCodeAt(0));
}

export function renderNotificationsPage(root, api = apiClient) {
    const page = element('main', { className: 'kp-vanilla-service-page kp-notifications-page' });
    const heading = element('header', { className: 'kp-vanilla-service-intro' });
    heading.append(
        element('h1', { text: 'اعلان‌ها و ترجیحات' }),
        element('p', { text: 'پیام‌ها فقط وضعیت و کد پیگیری سفارش را نشان می‌دهند؛ نشانی و مختصات در اعلان‌ها ارسال نمی‌شود.' }),
    );
    const feedback = element('p', { attributes: { role: 'status', 'aria-live': 'polite' } });
    const inbox = element('section', { attributes: { 'aria-label': 'اعلان‌های اخیر' } });
    const preferences = element('section', { className: 'kp-card', attributes: { 'aria-label': 'ترجیحات اعلان' } });
    const pushCard = element('section', { className: 'kp-card' });
    pushCard.append(
        element('h2', { text: 'اعلان وب با رضایت شما' }),
        element('p', { text: 'فعال‌سازی اعلان مرورگر اختیاری است و فقط پس از تأیید صریح مجوز انجام می‌شود.' }),
    );
    const pushButton = element('button', {
        className: 'kp-btn kp-btn-secondary',
        text: 'فعال‌سازی اعلان وب',
        attributes: { type: 'button' },
    });
    pushCard.append(pushButton);
    const pushStatus = element('p', { attributes: { role: 'status' } });
    pushCard.append(pushStatus);
    page.append(heading, feedback, inbox, preferences, pushCard);
    root.append(page);

    let disposed = false;
    let vapidPublicKey = null;
    let pushSetupError = null;
    const pushKeyReady = api.request('/notifications/web-push/public-key')
        .then((result) => {
            vapidPublicKey = result.data.public_key;
        })
        .catch((error) => {
            pushSetupError = error;
            if (!disposed) pushStatus.textContent = error.message;
        });
    const setFeedback = (message, isError = false) => {
        feedback.textContent = message;
        feedback.setAttribute('role', isError ? 'alert' : 'status');
    };

    async function loadInbox() {
        inbox.replaceChildren(element('p', { text: 'در حال دریافت اعلان‌ها…', attributes: { role: 'status' } }));
        const result = await api.request('/notifications');
        if (disposed) return;
        inbox.replaceChildren(element('h2', { text: 'آخرین اعلان‌ها' }));
        if (!result.data.length) {
            inbox.append(element('p', { className: 'kp-card', text: 'اعلانی برای نمایش وجود ندارد.' }));
            return;
        }
        result.data.forEach((notification) => {
            const card = element('article', { className: 'kp-card kp-notification-card' });
            card.append(
                element('h3', { text: notification.title || EVENT_LABELS[notification.event_key] || 'اعلان' }),
                element('p', { text: notification.body }),
                element('time', {
                    text: notification.created_at,
                    attributes: { datetime: notification.created_at },
                }),
            );
            if (!notification.read_at) {
                const readButton = element('button', {
                    className: 'kp-btn kp-btn-secondary',
                    text: 'خوانده شد',
                    attributes: { type: 'button' },
                });
                readButton.addEventListener('click', async () => {
                    readButton.disabled = true;
                    try {
                        await api.request(`/notifications/${notification.id}/read`, { method: 'PATCH' });
                        await loadInbox();
                    } catch (error) {
                        readButton.disabled = false;
                        setFeedback(error.message, true);
                    }
                });
                card.append(readButton);
            }
            inbox.append(card);
        });
    }

    async function loadPreferences() {
        preferences.replaceChildren(element('h2', { text: 'ترجیحات کانال‌ها' }));
        const result = await api.request('/notifications/preferences');
        const saved = new Map(result.data.map((item) => [`${item.event_key}:${item.channel}`, item]));
        EVENTS.forEach((eventKey) => {
            const group = element('fieldset', { className: 'kp-notification-preference' });
            group.append(element('legend', { text: EVENT_LABELS[eventKey] }));
            CHANNELS.forEach((channel) => {
                const savedValue = saved.get(`${eventKey}:${channel}`);
                const enabled = savedValue ? Boolean(savedValue.enabled) : result.defaults[channel];
                const id = `preference-${eventKey.replaceAll('.', '-')}-${channel}`;
                const checkbox = element('input', {
                    attributes: { type: 'checkbox', id, checked: enabled },
                });
                const label = element('label', {
                    text: CHANNEL_LABELS[channel],
                    attributes: { for: id },
                });
                const row = element('div', { className: 'kp-notification-preference-row' });
                row.append(checkbox, label);
                checkbox.addEventListener('change', async () => {
                    checkbox.disabled = true;
                    try {
                        await api.request('/notifications/preferences', {
                            method: 'PUT',
                            body: {
                                event_key: eventKey,
                                channel,
                                enabled: checkbox.checked,
                                hourly_limit: savedValue?.hourly_limit ?? result.defaults.hourly_limit,
                            },
                        });
                        setFeedback('ترجیحات اعلان ذخیره شد.');
                    } catch (error) {
                        checkbox.checked = !checkbox.checked;
                        setFeedback(error.message, true);
                    } finally {
                        checkbox.disabled = false;
                    }
                });
                group.append(row);
            });
            preferences.append(group);
        });
    }

    pushButton.addEventListener('click', async () => {
        pushButton.disabled = true;
        try {
            if (!('serviceWorker' in navigator) || !('PushManager' in globalThis) || !('Notification' in globalThis)) {
                throw new Error('مرورگر فعلی از اعلان وب پشتیبانی نمی‌کند.');
            }
            if (pushSetupError && !vapidPublicKey) throw pushSetupError;
            const permissionRequest = Notification.permission === 'granted'
                ? Promise.resolve('granted')
                : Notification.requestPermission();
            const registration = await navigator.serviceWorker.ready;
            const currentSubscription = await registration.pushManager.getSubscription();
            if (currentSubscription) {
                await api.request('/notifications/web-push-subscriptions', {
                    method: 'DELETE',
                    body: { endpoint: currentSubscription.endpoint },
                });
                await currentSubscription.unsubscribe();
                pushButton.textContent = 'فعال‌سازی اعلان وب';
                setFeedback('اعلان وب غیرفعال شد.');
                return;
            }
            await pushKeyReady;
            if (pushSetupError || !vapidPublicKey) {
                throw pushSetupError || new Error('کلید عمومی اعلان وب در دسترس نیست.');
            }
            const permission = await permissionRequest;
            if (permission !== 'granted') {
                throw new Error('مجوز اعلان وب داده نشد.');
            }
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: decodeVapidKey(vapidPublicKey),
            });
            await api.request('/notifications/web-push-subscriptions', {
                method: 'POST',
                body: { consent: true, subscription: subscription.toJSON() },
            });
            pushButton.textContent = 'غیرفعال‌سازی اعلان وب';
            setFeedback('اعلان وب با رضایت شما فعال شد.');
        } catch (error) {
            setFeedback(error.message, true);
        } finally {
            pushButton.disabled = false;
        }
    });
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.ready
            .then((registration) => registration.pushManager.getSubscription())
            .then((subscription) => {
                if (!disposed && subscription) pushButton.textContent = 'غیرفعال‌سازی اعلان وب';
            })
            .catch((error) => {
                pushStatus.textContent = error.message;
            });
    }

    Promise.all([loadInbox(), loadPreferences()])
        .catch((error) => setFeedback(error.message, true));
    return () => {
        disposed = true;
    };
}
