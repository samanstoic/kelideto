import { afterEach, describe, expect, it, vi } from 'vitest';
import { Passkeys } from '@laravel/passkeys';
import { renderAuthPage } from '../../resources/js/vanilla/auth/auth-page.js';
import { getVanillaDestinationForRole, routes } from '../../resources/js/vanilla/router/routes.js';

vi.mock('@laravel/passkeys', () => ({
    Passkeys: {
        register: vi.fn(),
        verify: vi.fn(),
    },
}));

describe('vanilla authentication page', () => {
    let root;
    let onLoginSuccess;

    function mount() {
        root = document.createElement('div');
        root.id = 'vanilla-app';
        document.body.replaceChildren(root);
        onLoginSuccess = vi.fn();
        renderAuthPage(root, { onLoginSuccess });
    }

    function submitForm() {
        root.querySelector('form').dispatchEvent(new Event('submit', {
            bubbles: true,
            cancelable: true,
        }));
    }

    function submitAdminForm() {
        root.querySelector('.kp-vanilla-admin-login form').dispatchEvent(new Event('submit', {
            bubbles: true,
            cancelable: true,
        }));
    }

    function successfulResponse(data = {}) {
        return new Response(JSON.stringify({
            success: true,
            message: 'درخواست با موفقیت انجام شد.',
            ...data,
        }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    afterEach(() => {
        sessionStorage.clear();
        document.body.replaceChildren();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        vi.clearAllMocks();
    });

    it('mounts semantic Persian RTL login and registration UI with both supported roles', () => {
        mount();

        expect(root.querySelector('main.kp-vanilla-auth-page')).not.toBeNull();
        expect(root.querySelector('form')).not.toBeNull();
        expect(root.querySelector('h1').textContent).toBe('ورود / ثبت‌نام در کلیدتو');
        expect(root.querySelector('[role="group"][aria-label="نوع حساب کاربری"]')).not.toBeNull();
        expect(root.querySelectorAll('.kp-vanilla-auth-role')).toHaveLength(2);
        expect(root.querySelector('#phone-input').getAttribute('autocomplete')).toBe('tel');
    });

    it('is registered at the Vanilla auth path and routes roles to the corresponding migrated pages', () => {
        root = document.createElement('div');
        document.body.replaceChildren(root);
        routes['/auth'](root);

        expect(root.querySelector('.kp-vanilla-auth-page')).not.toBeNull();
        expect(root.querySelector('a[href="/auth?frontend=vanilla"]')).not.toBeNull();
        expect(getVanillaDestinationForRole('technician')).toBe('/panels?frontend=vanilla');
        expect(getVanillaDestinationForRole('customer')).toBe('/booking?frontend=vanilla');
        expect(getVanillaDestinationForRole('super_admin')).toBe('/admin?frontend=vanilla');
    });

    it('rejects invalid Iranian phone numbers without calling the OTP endpoint', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        mount();

        root.querySelector('#phone-input').value = '12345';
        submitForm();

        expect(root.querySelector('#phone-input-error').textContent).toContain('شماره موبایل باید');
        expect(root.querySelector('#phone-input').getAttribute('aria-invalid')).toBe('true');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('sends OTP request using the existing endpoint and payload without auth identity headers', async () => {
        const fetchMock = vi.fn().mockResolvedValue(successfulResponse({
            message: 'کد تایید پیامکی ارسال شد.',
            data: { expires_in_seconds: 180 },
        }));
        vi.stubGlobal('fetch', fetchMock);
        sessionStorage.setItem('keypro_api_token', 'existing-token');
        mount();

        root.querySelector('#phone-input').value = '09123456789';
        submitForm();
        await vi.waitFor(() => expect(root.querySelector('#otp-input').closest('.kp-input-group').hidden).toBe(false));

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledWith('/api/auth/otp/request', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ phone_number: '09123456789' }),
        });
        expect(fetchMock.mock.calls[0][1].headers.Authorization).toBeUndefined();
        expect(fetchMock.mock.calls[0][1].headers['X-User-Id']).toBeUndefined();
        expect(root.textContent).toContain('کد تایید پیامکی ارسال شد.');
        expect(root.querySelector('#otp-input').getAttribute('autocomplete')).toBe('one-time-code');
    });

    it('shows the server error message and returns to an enabled form after request failure', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            success: false,
            message: 'کد تایید اخیراً ارسال شده است.',
        }), {
            status: 429,
            headers: { 'Content-Type': 'application/json' },
        }));
        vi.stubGlobal('fetch', fetchMock);
        mount();

        root.querySelector('#phone-input').value = '09123456789';
        submitForm();
        await vi.waitFor(() => expect(root.querySelector('[role="alert"]')).not.toBeNull());

        expect(root.querySelector('[role="alert"]').textContent).toBe('کد تایید اخیراً ارسال شده است.');
        expect(root.querySelector('button[type="submit"]').disabled).toBe(false);
    });

    it('validates OTP length before verification', async () => {
        const fetchMock = vi.fn().mockResolvedValue(successfulResponse());
        vi.stubGlobal('fetch', fetchMock);
        mount();
        root.querySelector('#phone-input').value = '09123456789';
        submitForm();
        await vi.waitFor(() => expect(root.querySelector('#otp-input').closest('.kp-input-group').hidden).toBe(false));

        root.querySelector('#otp-input').value = '123';
        submitForm();

        expect(root.querySelector('#otp-input-error').textContent).toBe('کد تایید باید ۶ رقمی باشد');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('verifies OTP using the existing contract, stores the existing token key, and redirects by returned role', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(successfulResponse())
            .mockResolvedValueOnce(successfulResponse({
                data: {
                    user: { id: 25, roles: ['technician'] },
                    token: 'issued-token',
                },
            }));
        vi.stubGlobal('fetch', fetchMock);
        mount();
        root.querySelector('.kp-vanilla-auth-role:last-child').click();
        root.querySelector('#phone-input').value = '09123456789';
        submitForm();
        await vi.waitFor(() => expect(root.querySelector('#otp-input').closest('.kp-input-group').hidden).toBe(false));

        root.querySelector('#otp-input').value = '123456';
        submitForm();
        await vi.waitFor(() => expect(onLoginSuccess).toHaveBeenCalledWith('technician', '09123456789'));

        expect(fetchMock.mock.calls[1][0]).toBe('/api/auth/otp/verify');
        expect(fetchMock.mock.calls[1][1].method).toBe('POST');
        expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({
            phone_number: '09123456789',
            code: '123456',
            role: 'technician',
        });
        expect(sessionStorage.getItem('keypro_api_token')).toBe('issued-token');
        expect(onLoginSuccess).toHaveBeenCalledTimes(1);
    });

    it('routes authenticated administrators to the operational console', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(successfulResponse({
            data: { mode: 'verify' },
        }));
        Passkeys.verify.mockResolvedValueOnce({
            data: {
                user: { id: 7, roles: ['super_admin'] },
                token: 'admin-token',
            },
        });
        vi.stubGlobal('fetch', fetchMock);
        mount();
        root.querySelector('#admin-username').value = 'root_manager';
        root.querySelector('#admin-password').value = 'a-very-strong-secret-password';
        submitAdminForm();

        await vi.waitFor(() => expect(onLoginSuccess).toHaveBeenCalledWith('super_admin'));
        expect(fetchMock.mock.calls[0][0]).toBe('/api/auth/admin/login');
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({
            username: 'root_manager',
            password: 'a-very-strong-secret-password',
        });
        expect(Passkeys.verify).toHaveBeenCalledWith({
            routes: {
                options: '/api/auth/admin/passkeys/options',
                submit: '/api/auth/admin/passkeys/verify',
            },
        });
        expect(getVanillaDestinationForRole(onLoginSuccess.mock.calls[0][0])).toBe('/admin?frontend=vanilla');
    });

    it('enrolls the first admin passkey before storing the returned token', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(successfulResponse({
            data: { mode: 'enroll' },
        }));
        Passkeys.register.mockResolvedValueOnce({
            data: {
                user: { id: 7, roles: ['super_admin'] },
                token: 'enrolled-admin-token',
            },
        });
        vi.stubGlobal('fetch', fetchMock);
        mount();
        root.querySelector('#admin-username').value = 'root_manager';
        root.querySelector('#admin-password').value = 'a-very-strong-secret-password';
        submitAdminForm();

        await vi.waitFor(() => expect(sessionStorage.getItem('keypro_api_token')).toBe('enrolled-admin-token'));
        expect(Passkeys.register).toHaveBeenCalledWith({
            name: 'کلید مدیریت',
            routes: {
                options: '/api/auth/admin/passkeys/options',
                submit: '/api/auth/admin/passkeys/register',
            },
        });
        expect(Passkeys.verify).not.toHaveBeenCalled();
    });

    it('never stores an administrator token when the passkey ceremony fails', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(successfulResponse({
            data: { mode: 'verify' },
        }));
        Passkeys.verify.mockRejectedValueOnce(new Error('Passkey verification failed'));
        vi.stubGlobal('fetch', fetchMock);
        mount();
        root.querySelector('#admin-username').value = 'root_manager';
        root.querySelector('#admin-password').value = 'a-very-strong-secret-password';
        submitAdminForm();

        await vi.waitFor(() => expect(root.querySelector('.kp-vanilla-admin-login-error').hidden).toBe(false));
        expect(sessionStorage.getItem('keypro_api_token')).toBeNull();
        expect(onLoginSuccess).not.toHaveBeenCalled();
    });

    it('does not store a token or redirect when the successful response is malformed', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(successfulResponse())
            .mockResolvedValueOnce(successfulResponse({
                data: { token: 'incomplete-token', user: { roles: 'customer' } },
            }));
        vi.stubGlobal('fetch', fetchMock);
        mount();
        root.querySelector('#phone-input').value = '09123456789';
        submitForm();
        await vi.waitFor(() => expect(root.querySelector('#otp-input').closest('.kp-input-group').hidden).toBe(false));
        root.querySelector('#otp-input').value = '123456';
        submitForm();

        await vi.waitFor(() => expect(root.querySelector('[role="alert"]')).not.toBeNull());
        expect(root.querySelector('[role="alert"]').textContent).toBe('پاسخ احراز هویت ساختار معتبری ندارد.');
        expect(sessionStorage.getItem('keypro_api_token')).toBeNull();
        expect(onLoginSuccess).not.toHaveBeenCalled();
    });

    it('shows backend OTP verification errors without persisting a token', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(successfulResponse())
            .mockResolvedValueOnce(new Response(JSON.stringify({
                success: false,
                message: 'کد تایید واردشده اشتباه است.',
            }), {
                status: 422,
                headers: { 'Content-Type': 'application/json' },
            }));
        vi.stubGlobal('fetch', fetchMock);
        mount();
        root.querySelector('#phone-input').value = '09123456789';
        submitForm();
        await vi.waitFor(() => expect(root.querySelector('#otp-input').closest('.kp-input-group').hidden).toBe(false));
        root.querySelector('#otp-input').value = '123456';
        submitForm();

        await vi.waitFor(() => expect(root.querySelector('[role="alert"]')).not.toBeNull());
        expect(root.querySelector('[role="alert"]').textContent).toBe('کد تایید واردشده اشتباه است.');
        expect(sessionStorage.getItem('keypro_api_token')).toBeNull();
        expect(onLoginSuccess).not.toHaveBeenCalled();
    });

    it('surfaces network failures without logging credentials', async () => {
        const fetchMock = vi.fn().mockRejectedValue(new TypeError('network down'));
        const consoleError = vi.spyOn(console, 'error');
        vi.stubGlobal('fetch', fetchMock);
        mount();
        root.querySelector('#phone-input').value = '09123456789';
        submitForm();

        await vi.waitFor(() => expect(root.querySelector('[role="alert"]')).not.toBeNull());
        expect(root.querySelector('[role="alert"]').textContent).toContain('network down');
        expect(consoleError).not.toHaveBeenCalled();
    });

    it('prevents duplicate OTP requests while the first request is pending', async () => {
        let resolveRequest;
        const fetchMock = vi.fn().mockReturnValue(new Promise((resolve) => {
            resolveRequest = resolve;
        }));
        vi.stubGlobal('fetch', fetchMock);
        mount();
        root.querySelector('#phone-input').value = '09123456789';

        submitForm();
        submitForm();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(root.querySelector('button[type="submit"]').disabled).toBe(true);
        resolveRequest(successfulResponse());
        await vi.waitFor(() => expect(root.querySelector('#otp-input').closest('.kp-input-group').hidden).toBe(false));
    });
});
