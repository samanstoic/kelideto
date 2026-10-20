import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderSupportPage } from '../../resources/js/vanilla/support/support-page.js';

const ticketCode = '3a4f51d1-9432-4764-9a0a-6f28c5633f61';

describe('vanilla support page', () => {
    let cleanup;
    let root;

    afterEach(() => {
        cleanup?.();
        cleanup = undefined;
        document.body.replaceChildren();
        sessionStorage.clear();
        vi.unstubAllGlobals();
    });

    it('creates support tickets through the API and renders server text without interpreting HTML', async () => {
        const fetchMock = vi.fn(async (url) => {
            if (url === '/api/support/tickets') {
                return new Response(JSON.stringify({
                    data: {
                        data: [{
                            ticket_code: ticketCode,
                            subject: '<img src=x onerror=alert(1)>',
                            category: 'other',
                            status: 'open',
                        }],
                    },
                }), { status: 200 });
            }
            if (url === '/api/support/bookings') {
                return new Response(JSON.stringify({ data: { data: [] } }), { status: 200 });
            }
            return new Response(JSON.stringify({ data: [] }), { status: 200 });
        });
        vi.stubGlobal('fetch', fetchMock);
        root = document.createElement('div');
        document.body.append(root);
        cleanup = renderSupportPage(root);
        await vi.waitFor(() => expect(root.textContent).toContain('ثبت درخواست پشتیبانی'));
        expect(root.textContent).toContain('<img src=x onerror=alert(1)>');
        expect(root.querySelector('img')).toBeNull();

        const form = root.querySelector('form');
        form.elements.subject.value = 'درخواست بررسی';
        form.elements.category.value = 'other';
        form.elements.message.value = 'شرح درخواست';
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledWith('/api/support/tickets', expect.objectContaining({
            method: 'POST',
        })));
        const request = fetchMock.mock.calls.find(([, options]) => options?.method === 'POST')[1];
        expect(request.body.get('subject')).toBe('درخواست بررسی');
        expect(request.body.get('message')).toBe('شرح درخواست');
        expect(request.body.get('idempotency_key')).toBeTruthy();
    });

    it('shows ticket history and retries replies with the same idempotency key', async () => {
        let failedFirstReply = false;
        const fetchMock = vi.fn(async (url, options = {}) => {
            if (url === '/api/support/tickets') {
                return new Response(JSON.stringify({ data: { data: [{
                    ticket_code: ticketCode,
                    subject: 'درخواست',
                    category: 'other',
                    status: 'open',
                }] } }), { status: 200 });
            }
            if (url === '/api/support/bookings') {
                return new Response(JSON.stringify({ data: { data: [] } }), { status: 200 });
            }
            if (url === `/api/support/tickets/${ticketCode}`) {
                return new Response(JSON.stringify({ data: {
                    ticket: { subject: 'درخواست', status: 'open', priority: 'medium' },
                    messages: { data: [{ message: '<script>متن</script>', sender_user_id: 1, attachments: [] }] },
                    history: [{ event_type: 'ticket.created', actor_name: 'مشتری', created_at: 'اکنون' }],
                } }), { status: 200 });
            }
            if (url.endsWith('/messages') && options.method === 'POST' && !failedFirstReply) {
                failedFirstReply = true;
                return new Response(JSON.stringify({ message: 'خطای موقت' }), { status: 503 });
            }
            if (url.endsWith('/messages') && options.method === 'POST') {
                return new Response(JSON.stringify({ data: { created: true } }), { status: 201 });
            }
            return new Response(JSON.stringify({ data: [] }), { status: 200 });
        });
        vi.stubGlobal('fetch', fetchMock);
        root = document.createElement('div');
        document.body.append(root);
        cleanup = renderSupportPage(root);
        await vi.waitFor(() => expect(root.textContent).toContain('مشاهده گفت‌وگو'));
        [...root.querySelectorAll('button')]
            .find((button) => button.textContent === 'مشاهده گفت‌وگو')
            .click();
        await vi.waitFor(() => expect(root.textContent).toContain('<script>متن</script>'));
        expect(root.querySelector('script')).toBeNull();
        const replyForm = [...root.querySelectorAll('form')]
            .find((form) => form.querySelector('button')?.textContent === 'ارسال پاسخ');
        replyForm.elements.message.value = 'پاسخ';
        replyForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await vi.waitFor(() => expect(root.textContent).toContain('خطای موقت'));
        replyForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await vi.waitFor(() => expect(fetchMock.mock.calls.filter(([, options]) => options?.method === 'POST')).toHaveLength(2));
        const replyRequests = fetchMock.mock.calls
            .filter(([, options]) => options?.method === 'POST')
            .map(([, options]) => options.body.get('idempotency_key'));
        expect(replyRequests[0]).toBe(replyRequests[1]);
    });
});
