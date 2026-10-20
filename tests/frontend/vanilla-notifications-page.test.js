import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderNotificationsPage } from '../../resources/js/vanilla/notifications/notifications-page.js';

describe('vanilla notifications page', () => {
    let cleanup;
    let root;

    afterEach(() => {
        cleanup?.();
        cleanup = undefined;
        document.body.replaceChildren();
    });

    it('renders the authenticated inbox as text and persists channel preferences', async () => {
        const requests = [];
        const api = {
            request: vi.fn(async (path, options = {}) => {
                requests.push({ path, options });
                if (path === '/notifications') {
                    return {
                        data: [{
                            id: 12,
                            event_key: 'booking.status_changed',
                            title: 'وضعیت سفارش',
                            body: '<script>نشانی محرمانه</script>',
                            read_at: null,
                            created_at: '2026-10-19T12:00:00Z',
                        }],
                    };
                }
                if (path === '/notifications/preferences') {
                    return { data: [], defaults: { in_app: true, sms: true, web_push: false, hourly_limit: 10 } };
                }
                return { data: { updated: true } };
            }),
        };
        root = document.createElement('div');
        document.body.append(root);
        cleanup = renderNotificationsPage(root, api);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(root.textContent).toContain('<script>نشانی محرمانه</script>');
        expect(root.querySelector('script')).toBeNull();
        expect(root.querySelector('input[type="checkbox"]')?.checked).toBe(true);
        const smsPreference = root.querySelector('#preference-booking-status_changed-sms');
        smsPreference.checked = false;
        smsPreference.dispatchEvent(new Event('change', { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        const update = requests.find(({ path, options }) => path === '/notifications/preferences' && options.method === 'PUT');
        expect(update.options.body).toMatchObject({
            event_key: 'booking.status_changed',
            channel: 'sms',
            enabled: false,
        });
    });
});
