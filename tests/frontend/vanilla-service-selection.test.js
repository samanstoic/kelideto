import { afterEach, describe, expect, it, vi } from 'vitest';
import { createAppState } from '../../resources/js/vanilla/state/app-state.js';
import { createRouter } from '../../resources/js/vanilla/router/router.js';
import { renderNotFound, routes } from '../../resources/js/vanilla/router/routes.js';

describe('vanilla service selection page', () => {
    let router;
    let root;

    function stubServiceCatalog() {
        const catalog = {
            data: [
                {
                    name_fa: 'درب و منازل',
                    slug: 'residential',
                    services: [
                        { id: 1, title_fa: 'بازگشایی انواع قفل درب ضدسرقت و معمولی', description: 'خدمت ساختمان', estimated_duration_minutes: 20, requires_ownership_proof: true },
                        { id: 2, title_fa: 'تعویض سیلندر و قفل درب حیاط و ورودی', description: 'تعویض قفل', estimated_duration_minutes: 30, requires_ownership_proof: true },
                    ],
                },
                {
                    name_fa: 'خودرو',
                    slug: 'automotive',
                    services: [
                        { id: 3, title_fa: 'باز کردن درب خودرو سواری و وانت', description: 'خدمات خودرو', estimated_duration_minutes: 15, requires_ownership_proof: true },
                        { id: 4, title_fa: 'تراش کلید و تعریف ریموت ایموبلایزر', description: 'خدمات خودرو', estimated_duration_minutes: 45, requires_ownership_proof: true },
                    ],
                },
                {
                    name_fa: 'گاوصندوق و اداری',
                    slug: 'commercial',
                    services: [
                        { id: 5, title_fa: 'بازگشایی و تعمیر تخصصی قفل گاوصندوق', description: 'خدمات اداری', estimated_duration_minutes: 60, requires_ownership_proof: true },
                    ],
                },
                {
                    name_fa: 'اورژانس شبانه',
                    slug: 'emergency',
                    services: [
                        { id: 6, title_fa: 'اعزام کلیدساز شبانه‌روزی اورژانسی', description: 'خدمات فوری', estimated_duration_minutes: 15, requires_ownership_proof: true },
                    ],
                },
            ],
        };
        const responses = {
            '/api/services': catalog,
            '/api/pricing/estimates': {
                data: {
                    estimate_id: 'fcbf46e4-839b-4a6c-9f0a-c79b93c74e0f',
                    minimum_total: 100000,
                    maximum_total: 120000,
                    tariff_version: 4,
                    service_area: 'تهران، منطقه آزمایشی',
                    approximate: true,
                },
            },
            '/api/bookings': {
                data: {
                    booking_code: '5a0ec5b9-3d37-4a1d-b738-2a1a55c60fac',
                },
            },
        };
        const fetchMock = vi.fn(async (url) => new Response(JSON.stringify(responses[url]), {
            status: url === '/api/bookings' ? 201 : 200,
            headers: { 'Content-Type': 'application/json' },
        }));
        vi.stubGlobal('fetch', fetchMock);
        return fetchMock;
    }

    async function waitForCatalog() {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }

    function mountApp() {
        history.replaceState({}, '', '/?frontend=vanilla');
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

    it('loads active services and categories from the backend without presenting fabricated prices', async () => {
        stubServiceCatalog();
        mountApp();

        root.querySelector('[aria-label="درخواست فوری کلیدساز در محل"]').click();
        await waitForCatalog();

        expect(location.pathname).toBe('/booking');
        expect(root.querySelector('h1')?.textContent).toBe('انتخاب خدمت کلیدسازی و امنیت');
        const cards = [...root.querySelectorAll('.kp-vanilla-service-card')];
        expect(cards).toHaveLength(6);
        expect(cards.map((card) => card.querySelector('.kp-vanilla-service-name').textContent)).toEqual([
            'بازگشایی انواع قفل درب ضدسرقت و معمولی',
            'تعویض سیلندر و قفل درب حیاط و ورودی',
            'باز کردن درب خودرو سواری و وانت',
            'تراش کلید و تعریف ریموت ایموبلایزر',
            'بازگشایی و تعمیر تخصصی قفل گاوصندوق',
            'اعزام کلیدساز شبانه‌روزی اورژانسی',
        ]);
        expect(root.textContent).toContain('برآورد تقریبی پس از تعیین محل و زمان');
        expect(root.textContent).not.toContain('450,000');
        expect(root.textContent).toContain('درب و منازل');
        expect(root.textContent).toContain('خودرو');
        expect(root.querySelector('header.kp-navbar')).not.toBeNull();
        expect(root.querySelector('footer.kp-vanilla-mobile-nav')).not.toBeNull();
        expect(root.querySelector('a[href="/location?frontend=vanilla"]')).not.toBeNull();
        expect(root.querySelector('a[href="/auth?frontend=vanilla"]')).not.toBeNull();
    });

    it('filters backend categories and allows canceling the service selection dialog', async () => {
        stubServiceCatalog();
        mountApp();

        root.querySelector('[aria-label="درخواست فوری کلیدساز در محل"]').click();
        await waitForCatalog();

        root.querySelector('.kp-vanilla-category:nth-child(3)').click();
        expect(root.querySelectorAll('.kp-vanilla-service-card')).toHaveLength(2);
        expect(root.textContent).toContain('باز کردن درب خودرو سواری و وانت');
        expect(root.textContent).not.toContain('بازگشایی انواع قفل درب ضدسرقت و معمولی');

        root.querySelector('.kp-vanilla-service-request').click();
        const dialog = root.querySelector('[role="dialog"]');
        expect(dialog).not.toBeNull();
        expect(dialog.textContent).toContain('انتخاب خدمت به‌تنهایی درخواست یا اعزام ایجاد نمی‌کند');
        dialog.querySelector('[aria-label="بستن پنجره"]').click();
        expect(root.querySelector('[role="dialog"]')).toBeNull();
        expect(root.querySelector('[role="alert"]')).toBeNull();
    });

    it('requests a server estimate then creates only an explicitly authorized draft', async () => {
        const fetchMock = stubServiceCatalog();
        mountApp();
        root.querySelector('[aria-label="درخواست فوری کلیدساز در محل"]').click();
        await waitForCatalog();

        root.querySelector('.kp-vanilla-service-request').click();
        const dialog = root.querySelector('[role="dialog"]');
        expect(dialog.textContent).toContain('برآورد اولیه را سرور');
        expect(dialog.textContent).toContain('اختیار دسترسی را دارد');
        dialog.querySelector('[name="latitude"]').value = '35.7';
        dialog.querySelector('[name="longitude"]').value = '51.4';
        dialog.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await waitForCatalog();

        expect(dialog.textContent).toContain('از ۱۰۰٬۰۰۰ ریال تا ۱۲۰٬۰۰۰ ریال');
        expect(dialog.textContent).toContain('تعرفهٔ نسخهٔ 4');
        const estimateRequest = fetchMock.mock.calls[1];
        expect(estimateRequest[0]).toBe('/api/pricing/estimates');
        expect(JSON.parse(estimateRequest[1].body)).toEqual({
            service_id: 1,
            latitude: 35.7,
            longitude: 51.4,
            urgency: 'urgent',
        });
        expect(estimateRequest[1].headers.get('Authorization')).toBeNull();
        expect(estimateRequest[1].headers.get('X-User-Id')).toBeNull();

        dialog.querySelector('[name="street_address"]').value = 'نشانی آزمایشی';
        dialog.querySelector('[name="street_address"]').dispatchEvent(new Event('input', { bubbles: true }));
        dialog.querySelector('[name="ownership_attested"]').checked = true;
        dialog.querySelector('.kp-btn-gold').click();
        await waitForCatalog();
        expect(dialog.textContent).toContain('هنوز متخصصی اعزام نشده است');
        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(fetchMock.mock.calls[2][0]).toBe('/api/bookings');
        expect(JSON.parse(fetchMock.mock.calls[2][1].body)).toEqual({
            estimate_id: 'fcbf46e4-839b-4a6c-9f0a-c79b93c74e0f',
            idempotency_key: expect.stringMatching(/^[0-9a-f-]{36}$/i),
            street_address: 'نشانی آزمایشی',
            ownership_attested: true,
            property_type: 'residential',
        });
        expect(fetchMock.mock.calls[2][1].headers.get('Idempotency-Key')).toBe(
            JSON.parse(fetchMock.mock.calls[2][1].body).idempotency_key,
        );
    });

    it('requires a scheduled time when the customer chooses a reserved appointment', async () => {
        const fetchMock = stubServiceCatalog();
        mountApp();
        root.querySelector('[aria-label="درخواست فوری کلیدساز در محل"]').click();
        await waitForCatalog();
        root.querySelector('.kp-vanilla-service-request').click();

        const dialog = root.querySelector('[role="dialog"]');
        const urgency = dialog.querySelector('[name="urgency"]');
        const scheduledAt = dialog.querySelector('[name="scheduled_at"]');
        urgency.value = 'scheduled';
        urgency.dispatchEvent(new Event('change', { bubbles: true }));
        expect(scheduledAt.disabled).toBe(false);
        expect(scheduledAt.required).toBe(true);
        dialog.querySelector('[name="latitude"]').value = '35.7';
        dialog.querySelector('[name="longitude"]').value = '51.4';
        scheduledAt.value = '2030-10-19T14:30';
        dialog.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await waitForCatalog();

        expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({
            service_id: 1,
            latitude: 35.7,
            longitude: 51.4,
            urgency: 'scheduled',
            scheduled_at: '2030-10-19T14:30',
        });
    });

    it('retries uncertain order creation with the identical payload and idempotency key', async () => {
        const responses = {
            '/api/services': {
                data: [{
                    name_fa: 'ساختمان',
                    slug: 'building',
                    services: [{ id: 1, title_fa: 'تعمیر قفل', estimated_duration_minutes: 30, requires_ownership_proof: true }],
                }],
            },
            '/api/pricing/estimates': {
                data: {
                    estimate_id: 'fcbf46e4-839b-4a6c-9f0a-c79b93c74e0f',
                    minimum_total: 100000,
                    maximum_total: 120000,
                    tariff_version: 1,
                    service_area: 'تهران',
                },
            },
        };
        let bookingAttempts = 0;
        const fetchMock = vi.fn(async (url) => {
            if (url === '/api/bookings') {
                bookingAttempts += 1;
                if (bookingAttempts === 1) throw new TypeError('connection lost after submit');
                return new Response(JSON.stringify({ data: { booking_code: '5a0ec5b9-3d37-4a1d-b738-2a1a55c60fac' } }), {
                    status: 201,
                    headers: { 'Content-Type': 'application/json' },
                });
            }
            return new Response(JSON.stringify(responses[url]), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            });
        });
        vi.stubGlobal('fetch', fetchMock);
        mountApp();
        root.querySelector('[aria-label="درخواست فوری کلیدساز در محل"]').click();
        await waitForCatalog();
        root.querySelector('.kp-vanilla-service-request').click();
        const dialog = root.querySelector('[role="dialog"]');
        dialog.querySelector('[name="latitude"]').value = '35.7';
        dialog.querySelector('[name="longitude"]').value = '51.4';
        dialog.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await waitForCatalog();
        dialog.querySelector('[name="street_address"]').value = 'نشانی ثابت';
        dialog.querySelector('[name="street_address"]').dispatchEvent(new Event('input', { bubbles: true }));
        dialog.querySelector('[name="ownership_attested"]').checked = true;

        dialog.querySelector('.kp-btn-gold').click();
        await waitForCatalog();
        expect(dialog.querySelector('[role="alert"]').textContent).toContain('ارتباط با سرور برقرار نشد');
        dialog.querySelector('.kp-btn-gold').click();
        await waitForCatalog();

        const bookingCalls = fetchMock.mock.calls.filter(([url]) => url === '/api/bookings');
        expect(bookingCalls).toHaveLength(2);
        expect(bookingCalls[1][1].body).toBe(bookingCalls[0][1].body);
        expect(bookingCalls[1][1].headers.get('Idempotency-Key'))
            .toBe(bookingCalls[0][1].headers.get('Idempotency-Key'));
        expect(dialog.textContent).toContain('مشاهده و پیگیری سفارش');
    });

    it('supports Escape to close the dialog and leaves the backend categories intact', async () => {
        stubServiceCatalog();
        mountApp();
        root.querySelector('[aria-label="درخواست فوری کلیدساز در محل"]').click();
        await waitForCatalog();

        root.querySelector('.kp-vanilla-service-request').click();
        const dialog = root.querySelector('[role="dialog"]');
        dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(root.querySelector('[role="dialog"]')).toBeNull();
        root.querySelector('button[aria-pressed="false"]').click();
        expect(root.querySelectorAll('.kp-vanilla-service-card')).toHaveLength(2);
    });

    it('shows a clear error and retry action when the catalog cannot be loaded', async () => {
        const fetchMock = vi.fn()
            .mockRejectedValueOnce(new Error('network down'))
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: [] }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }));
        vi.stubGlobal('fetch', fetchMock);
        mountApp();
        root.querySelector('[aria-label="درخواست فوری کلیدساز در محل"]').click();
        await waitForCatalog();

        expect(root.querySelector('[role="alert"]')?.textContent).toContain('ارتباط با سرور برقرار نشد');
        root.querySelector('.kp-vanilla-service-grid button')?.click();
        await waitForCatalog();
        expect(root.querySelector('[role="alert"]')).toBeNull();
        expect(root.textContent).toContain('در این دسته خدمت فعالی ثبت نشده است');
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
});
