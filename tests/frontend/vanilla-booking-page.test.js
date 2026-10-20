import { afterEach, describe, expect, it, vi } from 'vitest';
import { createAppState } from '../../resources/js/vanilla/state/app-state.js';
import { createRouter } from '../../resources/js/vanilla/router/router.js';
import { renderNotFound, routes } from '../../resources/js/vanilla/router/routes.js';

const bookingCode = '5a0ec5b9-3d37-4a1d-b738-2a1a55c60fac';

describe('vanilla booking tracking page', () => {
    let router;
    let root;

    function mount(path) {
        history.replaceState({}, '', path);
        root = document.createElement('div');
        root.id = 'vanilla-app';
        document.body.append(root);
        router = createRouter({
            root,
            routes,
            renderNotFound,
            state: createAppState(),
        });
    }

    afterEach(() => {
        router?.destroy();
        router = undefined;
        document.body.replaceChildren();
        history.replaceState({}, '', '/');
        vi.unstubAllGlobals();
    });

    it('renders only the customer booking records returned by the protected API', async () => {
        const fetchMock = vi.fn(async () => new Response(JSON.stringify({
            data: [{
                booking_code: bookingCode,
                service_name: 'تعمیر قفل',
                status: 'draft',
                created_at: '2026-10-19T12:00:00Z',
            }],
            meta: { page: 1, per_page: 20 },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        mount('/orders?frontend=vanilla');
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(fetchMock.mock.calls[0][0]).toBe('/api/bookings');
        expect(root.textContent).toContain(bookingCode);
        expect(root.textContent).toContain('ثبت‌شده؛ در انتظار بررسی');
        expect(root.querySelector(`a[href="/orders?frontend=vanilla&code=${bookingCode}"]`)).not.toBeNull();
        expect(root.querySelector('a[href="/orders?frontend=vanilla"]')).not.toBeNull();
    });

    it('shows booking snapshot and history, then safely retries customer cancellation', async () => {
        let cancelled = false;
        const booking = () => ({
            booking_code: bookingCode,
            service_name: 'تعمیر قفل',
            status: cancelled ? 'cancelled' : 'draft',
            urgency: 'urgent',
            street_address: 'خیابان آزمون',
            latitude: 35.7,
            longitude: 51.4,
            problem_description: 'قفل خراب است',
            estimated_price: 120000,
            tariff_version: 3,
            pricing_snapshot: { tariff_version: 3 },
            created_at: '2026-10-19T12:00:00Z',
            can_cancel: !cancelled,
            status_history: cancelled
                ? [
                    { from_status: null, to_status: 'draft', comment: 'ثبت سفارش', created_at: '2026-10-19T12:00:00Z' },
                    { from_status: 'draft', to_status: 'cancelled', comment: 'تغییر برنامه', created_at: '2026-10-19T12:05:00Z' },
                ]
                : [{ from_status: null, to_status: 'draft', comment: 'ثبت سفارش', created_at: '2026-10-19T12:00:00Z' }],
        });
        const fetchMock = vi.fn(async (url) => {
            if (url.endsWith('/cancel')) {
                cancelled = true;
                return new Response(JSON.stringify({
                    data: { booking_code: bookingCode, status: 'cancelled' },
                    already_cancelled: false,
                }), { status: 200, headers: { 'Content-Type': 'application/json' } });
            }
            return new Response(JSON.stringify({ data: booking() }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            });
        });
        vi.stubGlobal('fetch', fetchMock);
        mount(`/orders?frontend=vanilla&code=${bookingCode}`);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(root.textContent).toContain('خیابان آزمون');
        expect(root.textContent).toContain('35.7000000, 51.4000000');
        expect(root.textContent).toContain('نسخه تعرفه');
        root.querySelector('[name="reason"]').value = 'تغییر برنامه';
        root.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        const cancelCall = fetchMock.mock.calls.find(([url]) => url.endsWith('/cancel'));
        expect(cancelCall[0]).toBe(`/api/bookings/${bookingCode}/cancel`);
        expect(JSON.parse(cancelCall[1].body)).toEqual({ reason: 'تغییر برنامه' });
        expect(root.textContent).toContain('لغوشده');
        expect(root.querySelector('[name="reason"]')).toBeNull();
    });

    it('provides an explicit retry action when loading order details fails', async () => {
        const fetchMock = vi.fn()
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: { booking_code: bookingCode, service_name: 'تعمیر قفل', status: 'draft', urgency: 'urgent', street_address: 'آدرس', latitude: 1, longitude: 2, estimated_price: 10, status_history: [], can_cancel: false },
            }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        mount(`/orders?frontend=vanilla&code=${bookingCode}`);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(root.querySelector('[role="alert"]')?.textContent).toContain('ارتباط با سرور برقرار نشد');
        root.querySelector('.kp-vanilla-bookings-content > button')?.click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(root.textContent).toContain('تعمیر قفل');
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('initiates payment with a persistent idempotency key and does not trust a browser success return', async () => {
        const fetchMock = vi.fn(async (url) => {
            if (url.endsWith('/payments')) {
                return new Response(JSON.stringify({
                    error: 'GATEWAY_NOT_CONNECTED',
                    message: 'درگاه پرداخت تا زمان تأیید اتصال فعال نمی‌شود.',
                }), { status: 503, headers: { 'Content-Type': 'application/json' } });
            }
            return new Response(JSON.stringify({
                data: {
                    id: 42,
                    booking_code: bookingCode,
                    service_name: 'تعمیر قفل',
                    status: 'payment_pending',
                    urgency: 'urgent',
                    street_address: 'آدرس',
                    latitude: 1,
                    longitude: 2,
                    estimated_price: 500000,
                    can_cancel: false,
                    status_history: [],
                },
            }), { status: 200, headers: { 'Content-Type': 'application/json' } });
        });
        vi.stubGlobal('fetch', fetchMock);
        mount(`/orders?frontend=vanilla&code=${bookingCode}&payment_result=returned`);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(root.textContent).toContain('پرداخت امن سفارش');
        const paymentButton = root.querySelector('.kp-vanilla-booking-details button.kp-btn-primary');
        expect(paymentButton?.textContent).toContain('پرداخت امن سفارش');
        paymentButton.click();
        await new Promise((resolve) => setTimeout(resolve, 0));

        const paymentCall = fetchMock.mock.calls.find(([url]) => url.endsWith('/payments'));
        expect(paymentCall[0]).toBe('/api/bookings/42/payments');
        expect(paymentCall[1].method).toBe('POST');
        const payload = JSON.parse(paymentCall[1].body);
        expect(payload.idempotency_key).toMatch(/^payment:/);
        expect(root.textContent).toContain('درگاه پرداخت تا زمان تأیید اتصال فعال نمی‌شود.');
        expect(root.textContent).not.toContain('پرداخت با تأیید سرور ثبت شد');
    });
});
