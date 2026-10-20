import { apiClient } from '../api/client.js';
import { routerLink, el as createElement, renderShell } from '../ui/shell.js';

const STATUS_LABELS = {
    draft: 'ثبت‌شده؛ در انتظار بررسی',
    broadcasting: 'در حال یافتن متخصص',
    assigned: 'متخصص تعیین شده',
    en_route: 'متخصص در مسیر',
    arrived: 'متخصص در محل',
    in_progress: 'در حال انجام',
    payment_pending: 'در انتظار پرداخت',
    completed: 'تکمیل‌شده',
    cancelled: 'لغوشده',
    disputed: 'در حال رسیدگی',
};

function messageFor(error, fallback) {
    if (error?.details && typeof error.details === 'object') {
        const message = Object.values(error.details)
            .flat()
            .find((value) => typeof value === 'string' && value.length > 0);
        if (message) return message;
    }
    return error instanceof Error ? error.message : fallback;
}

function valueRow(label, value, attributes = {}) {
    const row = createElement('div', { className: 'kp-vanilla-booking-row' });
    row.append(
        createElement('span', { text: label }),
        createElement('strong', { text: value, attributes }),
    );
    return row;
}

function renderStatusHistory(history) {
    const timeline = createElement('ol', {
        className: 'kp-vanilla-booking-timeline',
        attributes: { 'aria-label': 'تاریخچه وضعیت سفارش' },
    });
    history.forEach((entry) => {
        const item = createElement('li');
        const from = entry.from_status ? `${STATUS_LABELS[entry.from_status] ?? entry.from_status} ← ` : '';
        const label = STATUS_LABELS[entry.to_status] ?? entry.to_status;
        item.append(
            createElement('strong', { text: `${from}${label}` }),
            createElement('time', {
                text: entry.created_at ?? '',
                attributes: { datetime: entry.created_at ?? '' },
            }),
        );
        if (entry.comment) item.append(createElement('p', { text: entry.comment }));
        timeline.append(item);
    });
    return timeline;
}

export function renderBookingsPage(root) {
    const controller = new AbortController();
    const page = createElement('main', { className: 'kp-vanilla-bookings-page' });
    const header = createElement('header', { className: 'kp-vanilla-service-intro' });
    header.append(
        createElement('h1', { text: 'سفارش‌ها و پیگیری' }),
        createElement('p', { text: 'جزئیات، وضعیت و تاریخچهٔ سفارش‌های ثبت‌شده را مشاهده کنید.' }),
    );
    const content = createElement('section', {
        className: 'kp-vanilla-bookings-content',
        attributes: { 'aria-live': 'polite' },
    });
    page.append(header, content);
    renderShell(root, 'orders', page);

    const code = new URLSearchParams(window.location.search).get('code');
    const paymentUuid = new URLSearchParams(window.location.search).get('payment_uuid');

    function paymentIdempotencyKey(bookingCode) {
        const storageKey = `kp-payment-idempotency:${bookingCode}`;
        let key = window.sessionStorage.getItem(storageKey);
        if (!key) {
            const nonce = globalThis.crypto?.randomUUID?.()
                ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`;
            key = `payment:${nonce}`;
            window.sessionStorage.setItem(storageKey, key);
        }
        return key;
    }

    async function loadList() {
        content.replaceChildren(createElement('p', { className: 'kp-card', text: 'در حال دریافت سفارش‌ها…' }));
        try {
            const response = await apiClient.request('bookings', { signal: controller.signal });
            if (controller.signal.aborted) return;
            const bookings = Array.isArray(response?.data) ? response.data : [];
            if (!bookings.length) {
                content.replaceChildren(
                    createElement('p', { className: 'kp-card', text: 'هنوز سفارشی برای این حساب ثبت نشده است.' }),
                    routerLink('ثبت درخواست خدمت', '/booking?frontend=vanilla', 'kp-btn kp-btn-primary'),
                );
                return;
            }

            const list = createElement('div', { className: 'kp-vanilla-booking-list' });
            bookings.forEach((booking) => {
                const card = createElement('article', { className: 'kp-card kp-vanilla-booking-card' });
                card.append(
                    createElement('h2', { text: booking.service_name }),
                    valueRow('شناسه سفارش', booking.booking_code, { dir: 'ltr' }),
                    valueRow('وضعیت', STATUS_LABELS[booking.status] ?? booking.status),
                    valueRow('ثبت‌شده در', booking.created_at ?? ''),
                    routerLink('مشاهده جزئیات', `/orders?frontend=vanilla&code=${encodeURIComponent(booking.booking_code)}`, 'kp-btn kp-btn-secondary'),
                );
                list.append(card);
            });
            content.replaceChildren(list);
        } catch (error) {
            if (!controller.signal.aborted) renderLoadError(content, error, loadList);
        }
    }

    async function loadDetails(bookingCode) {
        content.replaceChildren(createElement('p', { className: 'kp-card', text: 'در حال دریافت جزئیات سفارش…' }));
        try {
            const response = await apiClient.request(`bookings/${encodeURIComponent(bookingCode)}`, {
                signal: controller.signal,
            });
            if (controller.signal.aborted) return;
            const booking = response?.data;
            const card = createElement('article', { className: 'kp-card kp-vanilla-booking-details' });
            card.append(
                createElement('h2', { text: booking.service_name }),
                valueRow('شناسه سفارش', booking.booking_code, { dir: 'ltr' }),
                valueRow('وضعیت فعلی', STATUS_LABELS[booking.status] ?? booking.status),
                valueRow('فوریت', booking.urgency === 'scheduled' ? 'رزرو زمان‌دار' : 'فوری'),
                valueRow('نشانی', booking.street_address),
                valueRow('مختصات', `${Number(booking.latitude).toFixed(7)}, ${Number(booking.longitude).toFixed(7)}`, { dir: 'ltr' }),
                valueRow('شرح مشکل', booking.problem_description || 'ثبت نشده'),
                valueRow('برآورد ثبت‌شده', `${Number(booking.estimated_price).toLocaleString('fa-IR')} ریال`),
                valueRow('نسخه تعرفه', String(booking.tariff_version ?? '—')),
            );
            if (booking.scheduled_at) card.append(valueRow('زمان رزرو', booking.scheduled_at));

            const historyTitle = createElement('h3', { text: 'تاریخچهٔ وضعیت' });
            card.append(historyTitle, renderStatusHistory(booking.status_history ?? []));

            if (booking.status === 'completed') {
                const reviewForm = createElement('form', { className: 'kp-vanilla-booking-cancel-form' });
                const ratingLabel = createElement('label', {
                    className: 'kp-label',
                    text: 'امتیاز خدمت',
                });
                const rating = createElement('select', {
                    className: 'kp-input',
                    attributes: { name: 'rating', required: true },
                });
                [5, 4, 3, 2, 1].forEach((value) => rating.append(
                    createElement('option', { text: `${value} از ۵`, attributes: { value } }),
                ));
                ratingLabel.append(rating);
                const commentLabel = createElement('label', {
                    className: 'kp-label',
                    text: 'نظر شما (اختیاری)',
                });
                const comment = createElement('textarea', {
                    className: 'kp-input',
                    attributes: { name: 'comment', maxLength: 2000, rows: 4 },
                });
                commentLabel.append(comment);
                const reviewStatus = createElement('p', { attributes: { 'aria-live': 'polite' } });
                const submitReview = createElement('button', {
                    className: 'kp-btn kp-btn-primary',
                    text: 'ارسال امتیاز برای بررسی',
                    attributes: { type: 'submit' },
                });
                reviewForm.append(
                    createElement('h3', { text: 'امتیازدهی به خدمت' }),
                    ratingLabel,
                    commentLabel,
                    submitReview,
                    reviewStatus,
                );
                reviewForm.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    submitReview.disabled = true;
                    try {
                        const result = await apiClient.request(`bookings/${booking.id}/reviews`, {
                            method: 'POST',
                            body: { rating: Number(rating.value), comment: comment.value.trim() },
                            signal: controller.signal,
                        });
                        if (controller.signal.aborted) return;
                        reviewStatus.setAttribute('role', 'status');
                        reviewStatus.textContent = result?.data?.moderation_status === 'pending'
                            ? 'نظر شما ثبت شد و پس از بررسی منتشر خواهد شد.'
                            : 'نظر ثبت شد.';
                        submitReview.remove();
                    } catch (error) {
                        if (controller.signal.aborted) return;
                        reviewStatus.setAttribute('role', 'alert');
                        reviewStatus.textContent = messageFor(error, 'ثبت امتیاز ناموفق بود.');
                        submitReview.disabled = false;
                    }
                });
                card.append(reviewForm);
            }

            if (paymentUuid) {
                const paymentStatus = createElement('p', {
                    attributes: { 'aria-live': 'polite', role: 'status' },
                    text: 'در حال استعلام وضعیت پرداخت از سرور…',
                });
                card.append(paymentStatus);
                try {
                    const paymentResponse = await apiClient.request(`payments/${encodeURIComponent(paymentUuid)}`, {
                        signal: controller.signal,
                    });
                    if (controller.signal.aborted) return;
                    const payment = paymentResponse?.data;
                    if (Number(payment?.booking_id) !== Number(booking.id)) {
                        throw new Error('تراکنش پرداخت به این سفارش تعلق ندارد.');
                    }
                    paymentStatus.textContent = payment.status === 'paid'
                        ? `پرداخت با تأیید سرور ثبت شد: ${Number(payment.amount_minor).toLocaleString('fa-IR')} ریال`
                        : payment.status === 'failed'
                            ? 'پرداخت توسط درگاه تأیید نشد؛ در صورت نیاز درخواست تازه‌ای ثبت کنید.'
                            : `وضعیت پرداخت: ${payment.status}؛ هنوز پرداخت تأییدشده نیست.`;
                    if (payment.status === 'failed') {
                        window.sessionStorage.removeItem(`kp-payment-idempotency:${booking.booking_code}`);
                    }
                } catch (error) {
                    if (controller.signal.aborted) return;
                    paymentStatus.setAttribute('role', 'alert');
                    paymentStatus.textContent = messageFor(error, 'استعلام وضعیت پرداخت ناموفق بود.');
                }
            }

            if (booking.status === 'payment_pending') {
                const paymentButton = createElement('button', {
                    className: 'kp-btn kp-btn-primary',
                    text: 'پرداخت امن سفارش',
                    attributes: { type: 'button' },
                });
                const paymentFeedback = createElement('p', {
                    attributes: { 'aria-live': 'polite' },
                });
                paymentButton.addEventListener('click', async () => {
                    paymentButton.disabled = true;
                    paymentButton.textContent = 'در حال اتصال به درگاه…';
                    paymentFeedback.removeAttribute('role');
                    paymentFeedback.textContent = '';
                    try {
                        const response = await apiClient.request(`bookings/${booking.id}/payments`, {
                            method: 'POST',
                            body: { idempotency_key: paymentIdempotencyKey(booking.booking_code) },
                            signal: controller.signal,
                        });
                        if (controller.signal.aborted) return;
                        if (response?.data?.status === 'paid') {
                            await loadDetails(bookingCode);
                            return;
                        }
                        if (! response?.data?.redirect_url) {
                            throw new Error('نشانی امن درگاه در پاسخ سرور موجود نیست.');
                        }
                        window.location.assign(response.data.redirect_url);
                    } catch (error) {
                        if (controller.signal.aborted) return;
                        paymentFeedback.setAttribute('role', 'alert');
                        paymentFeedback.textContent = messageFor(error, 'شروع پرداخت ناموفق بود.');
                        paymentButton.disabled = false;
                        paymentButton.textContent = 'تلاش دوباره برای پرداخت';
                    }
                });
                card.append(paymentButton, paymentFeedback);
            }

            if (booking.can_cancel) {
                const cancelForm = createElement('form', { className: 'kp-vanilla-booking-cancel-form' });
                const label = createElement('label', {
                    className: 'kp-label',
                    text: 'دلیل لغو',
                    attributes: { for: 'booking-cancel-reason' },
                });
                const reason = createElement('textarea', {
                    className: 'kp-input',
                    attributes: {
                        id: 'booking-cancel-reason',
                        name: 'reason',
                        minLength: 3,
                        maxLength: 500,
                        required: true,
                    },
                });
                const cancelButton = createElement('button', {
                    className: 'kp-btn kp-btn-secondary',
                    text: 'لغو سفارش',
                    attributes: { type: 'submit' },
                });
                const cancelStatus = createElement('p', { attributes: { 'aria-live': 'polite' } });
                cancelForm.append(label, reason, cancelButton, cancelStatus);
                cancelForm.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    if (!reason.value.trim() || cancelButton.disabled) return;
                    cancelButton.disabled = true;
                    cancelButton.textContent = 'در حال لغو…';
                    cancelStatus.removeAttribute('role');
                    cancelStatus.textContent = '';
                    try {
                        await apiClient.request(`bookings/${encodeURIComponent(bookingCode)}/cancel`, {
                            method: 'POST',
                            body: { reason: reason.value.trim() },
                            signal: controller.signal,
                        });
                        await loadDetails(bookingCode);
                    } catch (error) {
                        if (controller.signal.aborted) return;
                        cancelStatus.setAttribute('role', 'alert');
                        cancelStatus.textContent = `${messageFor(error, 'لغو سفارش ناموفق بود.')} می‌توانید همین درخواست را دوباره ارسال کنید.`;
                        cancelButton.disabled = false;
                        cancelButton.textContent = 'تلاش دوباره برای لغو';
                    }
                });
                card.append(cancelForm);
            }

            content.replaceChildren(
                routerLink('بازگشت به سفارش‌ها', '/orders?frontend=vanilla', 'kp-btn kp-btn-secondary'),
                card,
            );
        } catch (error) {
            if (!controller.signal.aborted) renderLoadError(content, error, () => loadDetails(bookingCode));
        }
    }

    if (code) void loadDetails(code);
    else void loadList();
    return () => controller.abort();
}

function renderLoadError(container, error, retry) {
    const message = createElement('p', {
        className: 'kp-card kp-vanilla-status',
        text: messageFor(error, 'بارگذاری سفارش ناموفق بود.'),
        attributes: { role: 'alert' },
    });
    const button = createElement('button', {
        className: 'kp-btn kp-btn-secondary',
        text: 'تلاش دوباره',
        attributes: { type: 'button' },
    });
    button.addEventListener('click', retry);
    container.replaceChildren(message, button);
}
