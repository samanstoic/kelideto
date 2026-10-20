import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderAdminConsole } from '../../resources/js/vanilla/admin/admin-console.js';

describe('operational admin console', () => {
    let cleanup;
    let root;

    afterEach(() => {
        cleanup?.();
        cleanup = undefined;
        document.body.replaceChildren();
        history.replaceState({}, '', '/');
        vi.unstubAllGlobals();
    });

    it('renders its administrative sections from live API responses', async () => {
        const fetchMock = vi.fn(() => Promise.resolve(new Response(JSON.stringify({
            success: true,
            data: {
                users: 123,
                active_users: 120,
                technicians_pending: 4,
                technicians_verified: 30,
                open_bookings: 6,
                open_tickets: 2,
                payments_pending_settlement: 1,
                recent_bookings: [{ id: 7, booking_code: 'order-live', status: 'assigned' }],
                as_of: '2026-10-19T12:00:00+00:00',
            },
        }), { status: 200 })));
        vi.stubGlobal('fetch', fetchMock);
        history.replaceState({}, '', '/admin?frontend=vanilla');
        root = document.createElement('div');
        document.body.append(root);
        cleanup = renderAdminConsole(root);

        await vi.waitFor(() => expect(root.textContent).toContain('123'));
        expect(root.querySelectorAll('.kp-admin-nav-item')).toHaveLength(15);
        expect(root.textContent).toContain('order-live');
        expect(root.textContent).not.toContain('آمار نمونه');
        expect(fetchMock).toHaveBeenCalledWith('/api/admin/console/dashboard', expect.anything());
    });

    it('provides a permission-backed CMS editor, safe preview, media library, and redirect manager', async () => {
        const fetchMock = vi.fn(async (url) => {
            if (url.startsWith('/api/admin/console/content/media?')) {
                return new Response(JSON.stringify({ success: true, data: {
                    data: [{ id: 3, public_id: 'media-id', original_name: 'cover.webp', mime_type: 'image/webp', width: 640, height: 360 }],
                    current_page: 1,
                    last_page: 1,
                    total: 1,
                } }), { status: 200 });
            }
            if (url.startsWith('/api/admin/console/resources/content?')) {
                return new Response(JSON.stringify({ success: true, data: {
                    data: [{
                        id: 9,
                        title: 'مقاله واقعی',
                        slug: 'real-article',
                        content_type: 'article',
                        status: 'draft',
                        body: 'محتوای واقعی',
                        published_at: null,
                        scheduled_at: null,
                    }],
                    current_page: 1,
                    last_page: 1,
                    total: 1,
                } }), { status: 200 });
            }
            if (url.startsWith('/api/admin/console/content/redirects?')) {
                return new Response(JSON.stringify({ success: true, data: {
                    data: [{ id: 4, source_path: '/old', target_path: '/pages/new', status_code: 301, is_active: true }],
                    current_page: 1,
                    last_page: 1,
                    total: 1,
                } }), { status: 200 });
            }
            if (url === '/api/admin/console/content/preview') {
                return new Response(JSON.stringify({ success: true, data: { html: '<main>پیش‌نمایش امن</main>' } }), { status: 200 });
            }
            return new Response(JSON.stringify({ success: true, data: {} }), { status: 200 });
        });
        vi.stubGlobal('fetch', fetchMock);
        history.replaceState({}, '', '/admin?frontend=vanilla&section=content');
        root = document.createElement('div');
        document.body.append(root);
        cleanup = renderAdminConsole(root);

        await vi.waitFor(() => expect(root.textContent).toContain('مقاله واقعی'));
        expect(root.textContent).toContain('Meta description');
        expect(root.textContent).toContain('بازنویسی و تغییر مسیر URL');
        expect(root.textContent).toContain('cover.webp');

        const editor = [...root.querySelectorAll('.kp-admin-editor')]
            .find((form) => form.elements.body);
        editor.elements.slug.value = 'preview-article';
        editor.elements.title.value = 'مقاله پیش‌نمایش';
        editor.elements.body.value = 'متن پیش‌نمایش';
        editor.querySelector('button[type="button"]').click();
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledWith(
            '/api/admin/console/content/preview',
            expect.objectContaining({ method: 'POST' }),
        ));
        await vi.waitFor(() => expect(root.querySelector('iframe[title="پیش‌نمایش محتوا"]').srcdoc)
            .toContain('پیش‌نمایش امن'));
    });

    it('uses the audited support API for ticket replies and renders conversations as text', async () => {
        const ticketCode = '3a4f51d1-9432-4764-9a0a-6f28c5633f61';
        const fetchMock = vi.fn(async (url) => {
            if (url.startsWith('/api/admin/support/tickets?')) {
                return new Response(JSON.stringify({ data: {
                    data: [{
                        id: 1,
                        ticket_code: ticketCode,
                        subject: 'درخواست پشتیبانی',
                        category: 'other',
                        priority: 'medium',
                        status: 'open',
                        assigned_agent_id: null,
                    }],
                    current_page: 1,
                    last_page: 1,
                    total: 1,
                } }), { status: 200 });
            }
            if (url === `/api/support/tickets/${ticketCode}`) {
                return new Response(JSON.stringify({ data: {
                    ticket: { subject: 'درخواست', status: 'open', priority: 'medium' },
                    messages: { data: [{ message: '<script>محتوای پیام</script>' }] },
                    history: [],
                } }), { status: 200 });
            }
            return new Response(JSON.stringify({ data: { created: true } }), { status: 201 });
        });
        vi.stubGlobal('fetch', fetchMock);
        history.replaceState({}, '', '/admin?frontend=vanilla&section=tickets');
        root = document.createElement('div');
        document.body.append(root);
        cleanup = renderAdminConsole(root);

        await vi.waitFor(() => expect(root.textContent).toContain('درخواست پشتیبانی'));
        const conversationButton = [...root.querySelectorAll('button')]
            .find((button) => button.textContent === 'مشاهدهٔ پیام‌ها و تاریخچه');
        conversationButton.click();
        await vi.waitFor(() => expect(root.textContent).toContain('<script>محتوای پیام</script>'));
        expect(root.querySelector('script')).toBeNull();

        const reply = [...root.querySelectorAll('form')].find((form) => form.elements.message);
        reply.elements.message.value = 'پاسخ کارشناسی';
        reply.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledWith(
            `/api/support/tickets/${ticketCode}/messages`,
            expect.objectContaining({ method: 'POST' }),
        ));
        await vi.waitFor(() => expect(root.textContent).toContain('پاسخ برای مشتری ثبت شد.'));
    });
});
