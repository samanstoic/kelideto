import { apiClient } from '../api/client.js';
import { el, renderShell } from '../ui/shell.js';

function idempotencyKey() {
    return globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export function renderSupportPage(root) {
    const controller = new AbortController();
    const page = el('main', { className: 'kp-vanilla-bookings-page' });
    const content = el('section', {
        className: 'kp-vanilla-bookings-content',
        attributes: { 'aria-live': 'polite' },
    });
    page.append(
        el('header', { className: 'kp-vanilla-service-intro' }, [
            el('h1', { text: 'پشتیبانی و شکایت' }),
            el('p', { text: 'درخواست‌های پشتیبانی، گفت‌وگو و وضعیت رسیدگی را مشاهده کنید.' }),
        ]),
        content,
    );
    renderShell(root, 'support', page);

    function field(label, name, { type = 'text', required = false } = {}) {
        const wrapper = el('label', { className: 'kp-label' });
        wrapper.append(el('span', { text: label }));
        const control = type === 'textarea'
            ? el('textarea', { className: 'kp-input', attributes: { name, required, maxLength: 5000, rows: 4 } })
            : el('input', { className: 'kp-input', attributes: { name, type, required } });
        wrapper.append(control);
        return { wrapper, control };
    }

    async function openTicket(ticket) {
        const result = await apiClient.request(`support/tickets/${encodeURIComponent(ticket.ticket_code)}`, {
            signal: controller.signal,
        });
        const details = result.data;
        const panel = el('article', { className: 'kp-card' });
        panel.append(
            el('h2', { text: details.ticket.subject }),
            el('p', { text: `وضعیت: ${details.ticket.status} • اولویت: ${details.ticket.priority}` }),
        );
        details.messages.data.forEach((message) => {
            const item = el('article', { className: 'kp-card' });
            item.append(el('p', { text: message.message }));
            message.attachments.forEach((attachment) => {
                const download = el('button', {
                    className: 'kp-btn kp-btn-secondary',
                    text: `دریافت پیوست: ${attachment.name}`,
                    attributes: { type: 'button' },
                });
                download.addEventListener('click', async () => {
                    try {
                        const blob = await apiClient.request(attachment.download_url, {
                            responseType: 'blob',
                            signal: controller.signal,
                        });
                        const url = URL.createObjectURL(blob);
                        const link = el('a', {
                            attributes: { href: url, download: attachment.name },
                        });
                        link.click();
                        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
                    } catch (error) {
                        item.append(el('p', { attributes: { role: 'alert' }, text: error.message }));
                    }
                });
                item.append(download);
            });
            panel.append(item);
        });
        details.history.forEach((event) => {
            panel.append(el('p', {
                text: `${event.created_at} • ${event.actor_name || 'سامانه'} • ${event.event_type}${event.reason ? ` • ${event.reason}` : ''}`,
            }));
        });

        if (details.ticket.status !== 'closed') {
            const form = el('form', { className: 'kp-card' });
            const message = field('پاسخ', 'message', { type: 'textarea', required: true });
            const attachments = field('پیوست‌های مجاز (حداکثر ۳ فایل)', 'attachments', { type: 'file' });
            let requestKey = idempotencyKey();
            attachments.control.multiple = true;
            attachments.control.accept = '.jpg,.jpeg,.png,.pdf,.txt';
            const feedback = el('p', { attributes: { 'aria-live': 'polite' } });
            form.append(message.wrapper, attachments.wrapper,
                el('button', { className: 'kp-btn kp-btn-primary', text: 'ارسال پاسخ', attributes: { type: 'submit' } }),
                feedback);
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (attachments.control.files.length > 3) {
                    feedback.textContent = 'حداکثر سه پیوست قابل ارسال است.';
                    return;
                }
                const body = new FormData();
                body.set('message', message.control.value.trim());
                body.set('idempotency_key', requestKey);
                [...attachments.control.files].forEach((file) => body.append('attachments[]', file));
                try {
                    await apiClient.request(`support/tickets/${encodeURIComponent(ticket.ticket_code)}/messages`, {
                        method: 'POST',
                        body,
                        signal: controller.signal,
                    });
                    requestKey = idempotencyKey();
                    await load();
                } catch (error) {
                    feedback.setAttribute('role', 'alert');
                    feedback.textContent = error.message;
                }
            });
            panel.append(form);
        }
        content.append(panel);
    }

    async function load() {
        content.replaceChildren(el('p', { className: 'kp-card', text: 'در حال دریافت درخواست‌ها…' }));
        try {
            const [ticketsResponse, bookingsResponse] = await Promise.all([
                apiClient.request('support/tickets', { signal: controller.signal }),
                apiClient.request('support/bookings', { signal: controller.signal }),
            ]);
            if (controller.signal.aborted) return;
            content.replaceChildren();
            const ticketList = el('section');
            ticketList.append(el('h2', { text: 'درخواست‌های ثبت‌شده' }));
            ticketsResponse.data.data.forEach((ticket) => {
                const card = el('article', { className: 'kp-card' });
                card.append(el('h3', { text: ticket.subject }),
                    el('p', { text: `${ticket.category} • ${ticket.status}` }));
                const button = el('button', {
                    className: 'kp-btn kp-btn-secondary',
                    text: 'مشاهده گفت‌وگو',
                    attributes: { type: 'button' },
                });
                button.addEventListener('click', async () => {
                    try {
                        await openTicket(ticket);
                    } catch (error) {
                        card.append(el('p', { attributes: { role: 'alert' }, text: error.message }));
                    }
                });
                card.append(button);
                ticketList.append(card);
            });
            const form = el('form', { className: 'kp-card' });
            form.append(el('h2', { text: 'ثبت درخواست پشتیبانی' }));
            const subject = field('موضوع', 'subject', { required: true });
            const category = el('label', { className: 'kp-label' });
            category.append(el('span', { text: 'دسته‌بندی' }));
            const categorySelect = el('select', { className: 'kp-input', attributes: { name: 'category', required: true } });
            [
                ['other', 'سایر'],
                ['property_damage', 'آسیب به ملک'],
                ['late_arrival', 'تأخیر'],
                ['misconduct', 'رفتار نامناسب'],
                ['payment_dispute', 'اختلاف پرداخت'],
                ['cost_review', 'درخواست بررسی هزینه'],
            ].forEach(([value, label]) => categorySelect.append(el('option', { text: label, attributes: { value } })));
            category.append(categorySelect);
            const booking = el('label', { className: 'kp-label' });
            booking.append(el('span', { text: 'سفارش مرتبط (برای بررسی هزینه الزامی)' }));
            const bookingSelect = el('select', { className: 'kp-input', attributes: { name: 'booking_id' } });
            bookingSelect.append(el('option', { text: 'بدون سفارش مرتبط', attributes: { value: '' } }));
            bookingsResponse.data.data.forEach((record) => {
                bookingSelect.append(el('option', {
                    text: `${record.booking_code} • ${record.status}`,
                    attributes: { value: record.id },
                }));
            });
            booking.append(bookingSelect);
            const message = field('شرح درخواست', 'message', { type: 'textarea', required: true });
            const attachments = field('پیوست‌ها (حداکثر ۳ فایل)', 'attachments', { type: 'file' });
            attachments.control.multiple = true;
            attachments.control.accept = '.jpg,.jpeg,.png,.pdf,.txt';
            const feedback = el('p', { attributes: { 'aria-live': 'polite' } });
            let requestKey = idempotencyKey();
            form.append(subject.wrapper, category, booking, message.wrapper, attachments.wrapper,
                el('button', { className: 'kp-btn kp-btn-primary', text: 'ثبت تیکت', attributes: { type: 'submit' } }),
                feedback);
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (attachments.control.files.length > 3) {
                    feedback.textContent = 'حداکثر سه پیوست قابل ارسال است.';
                    return;
                }
                const body = new FormData();
                body.set('subject', subject.control.value.trim());
                body.set('category', categorySelect.value);
                body.set('message', message.control.value.trim());
                body.set('idempotency_key', requestKey);
                if (bookingSelect.value) body.set('booking_id', bookingSelect.value);
                [...attachments.control.files].forEach((file) => body.append('attachments[]', file));
                try {
                    await apiClient.request('support/tickets', {
                        method: 'POST',
                        body,
                        signal: controller.signal,
                    });
                    requestKey = idempotencyKey();
                    await load();
                } catch (error) {
                    feedback.setAttribute('role', 'alert');
                    feedback.textContent = error.message;
                }
            });
            content.append(ticketList, form);
        } catch (error) {
            if (!controller.signal.aborted) {
                content.replaceChildren(el('p', { className: 'kp-card', attributes: { role: 'alert' }, text: error.message }));
            }
        }
    }

    load();
    return () => controller.abort();
}
