import { afterEach, describe, expect, it, vi } from 'vitest';
import { createAppState } from '../../resources/js/vanilla/state/app-state.js';
import { createRouter } from '../../resources/js/vanilla/router/router.js';
import { renderNotFound, routes } from '../../resources/js/vanilla/router/routes.js';

describe('vanilla role panels', () => {
    let router;
    let root;

    function mountApp() {
        history.replaceState({}, '', '/panels?frontend=vanilla');
        root = document.createElement('div');
        root.id = 'vanilla-app';
        document.body.replaceChildren(root);
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

    it('mounts the panels page with an explicit demo-data notice and all four role controls', () => {
        mountApp();

        expect(root.querySelector('main.kp-vanilla-panels-page')).not.toBeNull();
        expect(root.querySelector('[role="note"]').textContent).toContain('نمونهٔ نمایشی');
        expect(root.querySelectorAll('.kp-vanilla-panel-role')).toHaveLength(4);
        expect(root.querySelector('[aria-pressed="true"]').textContent).toContain('پنل مشتری');
        expect(root.textContent).toContain('سفارش فعال: بازگشایی درب ضدسرقت');
        expect(root.querySelector('a[href="/panels?frontend=vanilla"]')).not.toBeNull();
    });

    it('uses live technician data while retaining clearly labeled demo-only other panels', async () => {
        const fetchMock = vi.fn((url) => {
            if (url.endsWith('/technician/dashboard')) {
                return Promise.resolve(new Response(JSON.stringify({
                    data: {
                        profile: {
                            id: 1,
                            name: 'متخصص آزمون',
                            status: 'verified',
                            online: false,
                            available: false,
                            documents: [],
                            service_ids: [],
                            service_area_ids: [],
                            working_hours: [],
                        },
                        services: [{ id: 3, title_fa: 'خدمت واقعی آزمون' }],
                        service_areas: [{ id: 4, city_name: 'تهران', zone_name: 'منطقه واقعی آزمون' }],
                    },
                }), { status: 200 }));
            }
            const data = url.endsWith('/technician/earnings')
                ? { data: { gross: 0, platform_commission: 0, net_earnings: 0, settlements: [] } }
                : { data: [] };
            return Promise.resolve(new Response(JSON.stringify(data), { status: 200 }));
        });
        vi.stubGlobal('fetch', fetchMock);
        mountApp();

        const roleButtons = root.querySelectorAll('.kp-vanilla-panel-role');
        roleButtons[1].click();
        await vi.waitFor(() => expect(root.textContent).toContain('خدمت واقعی آزمون'));
        expect(root.textContent).toContain('منطقه واقعی آزمون');
        expect(root.textContent).not.toContain('علیرضا محمدی');
        expect(fetchMock).toHaveBeenCalledWith('/api/technician/dashboard', expect.anything());

        roleButtons[2].click();
        expect(root.textContent).toContain('درخواست‌های در انتظار دیسپچ');
        expect(root.textContent).toContain('مانیتورینگ زنده صف اعزام و صلاحیت‌ها');
        expect(root.querySelectorAll('.kp-table tbody tr')).toHaveLength(2);

        roleButtons[3].click();
        expect(root.textContent).toContain('موجودی دفتر کل (Ledger Assets)');
        expect(root.querySelectorAll('.kp-table tbody tr')).toHaveLength(3);

        roleButtons[0].click();
        expect(root.textContent).toContain('مشخصات کلیدساز اعزامی');
        expect(root.textContent).toContain('فاکتور و برآورد قیمت');
    });

    it('retains operator pagination controls and does not issue backend requests', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        mountApp();
        root.querySelectorAll('.kp-vanilla-panel-role')[2].click();

        expect(root.querySelector('.kp-pagination span').textContent).toBe('صفحه ۱ از ۵');
        const nextButton = root.querySelector('.kp-pagination button:last-child');
        expect(nextButton.disabled).toBe(false);
        nextButton.click();
        expect(root.querySelector('.kp-pagination span').textContent).toBe('صفحه ۲ از ۵');

        root.querySelector('.kp-pagination button:first-child').click();
        expect(root.querySelector('.kp-pagination span').textContent).toBe('صفحه ۱ از ۵');
        expect(root.querySelector('.kp-pagination button:first-child').disabled).toBe(true);
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
