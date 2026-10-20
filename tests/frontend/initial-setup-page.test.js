import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderInitialSetupPage } from '../../resources/js/vanilla/setup/initial-setup-page.js';
import { routes } from '../../resources/js/vanilla/router/routes.js';

function jsonResponse(data, status = 200) {
    return new Response(JSON.stringify(data), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('server-backed initial setup page', () => {
    let root;

    function mount() {
        root = document.createElement('div');
        document.body.replaceChildren(root);
        return renderInitialSetupPage(root);
    }

    afterEach(() => {
        document.body.replaceChildren();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('loads setup readiness and connected integration statuses from the backend', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({
            data: {
                setup_required: true,
                ready: true,
                requirements: [{ key: 'database', label: 'اتصال پایگاه داده', ready: true }],
                services: {
                    sms: { provider: 'mock', status: 'disconnected' },
                    web_push: { provider: 'vapid', status: 'disconnected' },
                    payment_gateway: { provider: 'disconnected', status: 'disconnected' },
                },
            },
        }));
        vi.stubGlobal('fetch', fetchMock);
        mount();

        await vi.waitFor(() => expect(root.querySelector('.kp-setup-form').hidden).toBe(false));
        expect(fetchMock).toHaveBeenCalledWith('/api/setup/status', { headers: { Accept: 'application/json' } });
        expect(root.textContent).toContain('اتصال پایگاه داده');
        expect(root.textContent).toContain('پیامک: قطع/تأییدنشده');
    });

    it('submits administrator credentials and all configured settings to the server', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(jsonResponse({
                data: {
                    setup_required: true,
                    ready: true,
                    requirements: [],
                    services: {},
                },
            }))
            .mockResolvedValueOnce(jsonResponse({
                message: 'راه‌اندازی انجام شد.',
                data: { setup_required: false },
            }, 201));
        vi.stubGlobal('fetch', fetchMock);
        mount();
        await vi.waitFor(() => expect(root.querySelector('.kp-setup-form').hidden).toBe(false));

        root.querySelector('#setup-name').value = 'مدیر سامانه';
        root.querySelector('#setup-username').value = 'root_manager';
        root.querySelector('#setup-email').value = 'root@example.test';
        root.querySelector('#setup-token').value = 's'.repeat(48);
        root.querySelector('#setup-password').value = 'a-very-strong-secret-password';
        root.querySelector('#setup-password-confirmation').value = 'a-very-strong-secret-password';
        root.querySelector('#setup-site-name').value = 'کلیدتو عملیاتی';
        root.querySelector('#setup-contact-email').value = 'support@example.test';
        root.querySelector('#setup-support-phone').value = '09123456789';
        root.querySelector('#setup-response-window').value = '120';
        root.querySelector('#setup-location-retention').value = '3';
        root.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

        await vi.waitFor(() => expect(root.querySelector('.kp-setup-form').hidden).toBe(true));
        expect(fetchMock.mock.calls[1][0]).toBe('/api/setup/complete');
        expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toMatchObject({
            username: 'root_manager',
            setup_token: 's'.repeat(48),
            password: 'a-very-strong-secret-password',
            password_confirmation: 'a-very-strong-secret-password',
            settings: {
                site_name: 'کلیدتو عملیاتی',
                timezone: 'Asia/Tehran',
                dispatch_response_window_seconds: 120,
                location_retention_hours: 3,
            },
        });
        expect(root.querySelector('a[href="/auth?frontend=vanilla"]').hidden).toBe(false);
        expect(root.textContent).toContain('در سرور ثبت شدند');
    });

    it('does not show completion or submit when server prerequisites are missing', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({
            data: {
                setup_required: true,
                ready: false,
                requirements: [{ key: 'application_key', label: 'تنظیم کلید رمزنگاری', ready: false }],
                services: {},
            },
        }));
        vi.stubGlobal('fetch', fetchMock);
        mount();

        await vi.waitFor(() => expect(root.querySelector('.kp-setup-notice').textContent)
            .toContain('پیش‌نیازهای سرور کامل نیست'));
        expect(root.textContent).toContain('پیش‌نیازهای سرور کامل نیست');
        expect(root.textContent).not.toContain('با موفقیت در سرور ثبت شدند');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('registers a dedicated setup route', () => {
        expect(routes['/setup']).toBeTypeOf('function');
    });
});
