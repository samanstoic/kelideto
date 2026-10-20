import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, createApiClient } from '../../resources/js/vanilla/api/client.js';
import { createRouter } from '../../resources/js/vanilla/router/router.js';
import { routes } from '../../resources/js/vanilla/router/routes.js';
import { registerServiceWorker, startVanillaApp } from '../../resources/js/vanilla/app.js';

describe('vanilla API client', () => {
    afterEach(() => {
        sessionStorage.clear();
        vi.unstubAllGlobals();
    });

    it('builds the request URL and method and parses JSON responses', async () => {
        const fetchImpl = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ success: true }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }),
        );
        const client = createApiClient({ fetchImpl });

        const data = await client.request('/auth/otp/request', {
            method: 'post',
            body: { phone_number: '09123456789' },
        });

        expect(fetchImpl).toHaveBeenCalledWith('/api/auth/otp/request', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({ phone_number: '09123456789' }),
        }));
        const headers = fetchImpl.mock.calls[0][1].headers;
        expect(headers.get('Accept')).toBe('application/json');
        expect(headers.get('Content-Type')).toBe('application/json');
        expect(data).toEqual({ success: true });
    });

    it('uploads FormData without forcing a JSON content type', async () => {
        const fetchImpl = vi.fn().mockResolvedValue(new Response('{}', { status: 201 }));
        const client = createApiClient({ fetchImpl });
        const body = new FormData();
        body.append('document_type', 'national_id');
        body.append('document', new File(['id'], 'id.pdf', { type: 'application/pdf' }));

        await client.request('/technician/documents', { method: 'POST', body });

        expect(fetchImpl.mock.calls[0][1].body).toBe(body);
        expect(fetchImpl.mock.calls[0][1].headers.has('Content-Type')).toBe(false);
    });

    it('attaches the existing bearer token only when it exists', async () => {
        const fetchImpl = vi.fn().mockImplementation(
            () => Promise.resolve(new Response('{}', { status: 200 })),
        );
        const client = createApiClient({ fetchImpl });

        sessionStorage.setItem('keypro_api_token', 'test-token');
        await client.request('auth/me');
        expect(fetchImpl.mock.calls[0][1].headers.get('Authorization')).toBe('Bearer test-token');

        sessionStorage.clear();
        await client.request('auth/me', {
            headers: {
                Authorization: 'Bearer caller-supplied-token',
                'X-User-Id': '123',
            },
        });
        expect(fetchImpl.mock.calls[1][1].headers.has('Authorization')).toBe(false);
        expect(fetchImpl.mock.calls[1][1].headers.has('X-User-Id')).toBe(false);
    });

    it('forwards the caller-supplied idempotency header', async () => {
        const fetchImpl = vi.fn().mockResolvedValue(new Response('{}', { status: 201 }));
        const client = createApiClient({ fetchImpl });

        await client.request('/bookings/draft-sync', {
            method: 'POST',
            headers: { 'X-Idempotency-Key': 'draft-uuid' },
            body: { idempotency_key: 'draft-uuid' },
        });

        expect(fetchImpl.mock.calls[0][1].headers.get('X-Idempotency-Key')).toBe('draft-uuid');
    });

    it.each([
        [401, 'UNAUTHENTICATED'],
        [403, 'FORBIDDEN'],
        [409, 'CONFLICT'],
        [422, 'VALIDATION_ERROR'],
        [503, 'SERVER_ERROR'],
    ])('reports HTTP %s as %s', async (status, code) => {
        const fetchImpl = vi.fn().mockResolvedValue(new Response(
            JSON.stringify({ message: 'request failed' }),
            { status, headers: { 'Content-Type': 'application/json' } },
        ));
        const client = createApiClient({ fetchImpl });

        await expect(client.request('health')).rejects.toMatchObject({
            name: 'ApiError',
            status,
            code,
            message: 'request failed',
        });
    });

    it('reports network errors without leaking request data', async () => {
        const fetchImpl = vi.fn().mockRejectedValue(new TypeError('network down'));
        const client = createApiClient({ fetchImpl });

        await expect(client.request('auth/otp/verify', {
            method: 'POST',
            body: { code: '123456' },
        })).rejects.toMatchObject({
            name: 'ApiError',
            code: 'NETWORK_ERROR',
        });
        await expect(client.request('health')).rejects.toBeInstanceOf(ApiError);
    });
});

describe('vanilla landing page', () => {
    afterEach(() => {
        document.body.replaceChildren();
    });

    it('renders the landing page structure with one native frontend root', () => {
        const root = document.createElement('div');
        root.id = 'vanilla-app';
        document.body.append(root);

        routes['/'](root);

        expect(root.querySelector('.kp-vanilla-landing')).not.toBeNull();
        expect(root.textContent).toContain('قفل درب باز نمی‌شود؟');
        expect(root.querySelectorAll('.kp-vanilla-feature-card')).toHaveLength(4);
        expect(root.querySelector('a[aria-label="درخواست فوری کلیدساز در محل"]')?.getAttribute('href'))
            .toBe('/booking?frontend=vanilla');
        expect(root.querySelector('header.kp-navbar')).not.toBeNull();
        expect(root.querySelector('footer.kp-vanilla-mobile-nav')).not.toBeNull();
        expect(root.querySelector('a[aria-label="تماس صوتی اضطراری"]')?.getAttribute('href'))
            .toBe('tel:02199998888');
    });
});

describe('vanilla router', () => {
    afterEach(() => {
        history.replaceState({}, '', '/');
        document.body.replaceChildren();
    });

    it('navigates, handles back/forward, and renders an explicit not-found state', () => {
        const root = document.createElement('div');
        document.body.append(root);
        const rendered = [];
        const router = createRouter({
            root,
            routes: {
                '/': (element) => {
                    rendered.push('/');
                    element.innerHTML = '<a href="/known" data-router-link>Known</a>';
                },
                '/known': (element) => {
                    rendered.push('/known');
                    element.textContent = 'known page';
                },
            },
            renderNotFound: (element, path) => {
                rendered.push(`not-found:${path}`);
                element.textContent = 'not found';
            },
        });

        root.querySelector('a').click();
        expect(location.pathname).toBe('/known');
        expect(root.textContent).toBe('known page');

        history.back();
        return new Promise((resolve) => {
            window.addEventListener('popstate', () => {
                expect(location.pathname).toBe('/');
                router.navigate('/missing');
                expect(root.textContent).toBe('not found');
                expect(rendered).toContain('not-found:/missing');
                router.destroy();
                resolve();
            }, { once: true });
        });
    });

    it('starts the native frontend in its single root and registers the service worker safely', () => {
        document.body.innerHTML = '<div id="vanilla-app"></div>';
        history.replaceState({}, '', '/?frontend=vanilla');

        const app = startVanillaApp(document);
        expect(document.querySelector('#vanilla-app .kp-vanilla-landing')).not.toBeNull();
        expect(document.querySelector('#app')).toBeNull();
        app.router.destroy();

        const register = vi.fn().mockResolvedValue(undefined);
        registerServiceWorker({ serviceWorker: { register } }, { isSecureContext: true });
        expect(register).toHaveBeenCalledWith('/service-worker.js');
    });

    it('does not register a service worker outside a secure context or without support', () => {
        const register = vi.fn();
        registerServiceWorker({ serviceWorker: { register } }, { isSecureContext: false });
        registerServiceWorker({}, { isSecureContext: true });
        expect(register).not.toHaveBeenCalled();
    });
});
