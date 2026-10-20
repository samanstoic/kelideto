import { Passkeys } from '@laravel/passkeys';

const PHONE_PATTERN = /^09[0-9]{9}$/;
const TOKEN_STORAGE_KEY = 'keypro_api_token';

function notifyAuthChanged() {
    window.dispatchEvent(new Event('keypro:auth-changed'));
}

function createElement(tagName, { className, text, attributes } = {}) {
    const element = document.createElement(tagName);

    if (className) element.className = className;
    if (text) element.textContent = text;
    if (attributes) {
        Object.entries(attributes).forEach(([name, value]) => {
            if (value !== undefined && value !== null && value !== false) {
                element.setAttribute(name, value === true ? '' : String(value));
            }
        });
    }

    return element;
}

function createField({ id, label, type, placeholder, autocomplete, maxLength }) {
    const group = createElement('div', { className: 'kp-input-group kp-vanilla-auth-field' });
    const fieldLabel = createElement('label', {
        className: 'kp-label',
        text: label,
        attributes: { for: id },
    });
    const input = createElement('input', {
        className: 'kp-input',
        attributes: {
            id,
            name: id,
            type,
            placeholder,
            autocomplete,
            maxlength: maxLength,
            required: true,
            dir: 'ltr',
            inputmode: type === 'tel' || type === 'text' ? 'numeric' : null,
        },
    });
    const error = createElement('span', {
        className: 'kp-error-text',
        attributes: { id: `${id}-error`, hidden: true },
    });
    input.setAttribute('aria-describedby', `${id}-error`);
    group.append(fieldLabel, input, error);
    return { group, input, error };
}

function setFieldError(input, errorElement, message) {
    input.classList.toggle('kp-input-error', Boolean(message));
    input.setAttribute('aria-invalid', message ? 'true' : 'false');
    errorElement.textContent = message ?? '';
    errorElement.hidden = !message;
    if (message) errorElement.setAttribute('role', 'alert');
    else errorElement.removeAttribute('role');
}

function setLoading(button, loading, label) {
    button.disabled = loading;
    button.setAttribute('aria-busy', loading ? 'true' : 'false');
    button.textContent = loading ? label : button.dataset.defaultLabel;
}

async function readResponse(response, fallbackMessage) {
    let data;
    try {
        data = await response.json();
    } catch {
        throw new Error(response.ok ? 'پاسخ سرور قالب JSON معتبر ندارد.' : fallbackMessage);
    }

    if (!response.ok) {
        throw new Error(typeof data?.message === 'string' ? data.message : fallbackMessage);
    }

    return data;
}

export function renderAuthPage(root, { onLoginSuccess } = {}) {
    let role = 'customer';
    let step = 'phone';
    let phone = '';
    let otp = '';
    let loading = false;

    const page = createElement('main', { className: 'kp-vanilla-auth-page' });
    const card = createElement('section', {
        className: 'kp-card kp-vanilla-auth-card',
        attributes: { 'aria-labelledby': 'kp-vanilla-auth-title' },
    });
    const roleSelector = createElement('div', {
        className: 'kp-vanilla-auth-roles',
        attributes: { role: 'group', 'aria-label': 'نوع حساب کاربری' },
    });
    const customerButton = createElement('button', {
        className: 'kp-vanilla-auth-role',
        text: 'مشتری (خدمات قفل)',
        attributes: { type: 'button' },
    });
    const technicianButton = createElement('button', {
        className: 'kp-vanilla-auth-role',
        text: 'متخصص و کلیدساز',
        attributes: { type: 'button' },
    });
    const title = createElement('h1', { attributes: { id: 'kp-vanilla-auth-title' } });
    const description = createElement('p', { className: 'kp-vanilla-auth-description' });
    const toast = createElement('div', {
        className: 'kp-toast kp-toast-success kp-vanilla-auth-toast',
        attributes: { role: 'status', hidden: true },
    });
    const toastText = createElement('span');
    const closeToast = createElement('button', {
        className: 'kp-vanilla-auth-toast-close',
        text: '✕',
        attributes: { type: 'button', 'aria-label': 'بستن پیام' },
    });
    closeToast.addEventListener('click', () => {
        toast.hidden = true;
    });
    toast.append(toastText, closeToast);

    const form = createElement('form', {
        className: 'kp-vanilla-auth-form',
        attributes: { novalidate: true },
    });
    const phoneField = createField({
        id: 'phone-input',
        label: 'شماره تلفن همراه',
        type: 'tel',
        placeholder: '۰۹۱۲۳۴۵۶۷۸۹',
        autocomplete: 'tel',
        maxLength: 11,
    });
    const otpField = createField({
        id: 'otp-input',
        label: 'کد تایید ۶ رقمی',
        type: 'text',
        placeholder: '------',
        autocomplete: 'one-time-code',
        maxLength: 6,
    });
    const submitButton = createElement('button', {
        className: 'kp-btn kp-btn-primary kp-vanilla-auth-submit',
        attributes: { type: 'submit' },
    });
    submitButton.dataset.defaultLabel = 'دریافت کد تایید یکبار مصرف';
    submitButton.textContent = submitButton.dataset.defaultLabel;
    const editPhoneButton = createElement('button', {
        className: 'kp-vanilla-auth-edit-phone',
        text: 'ویرایش شماره موبایل',
        attributes: { type: 'button' },
    });
    editPhoneButton.hidden = true;

    function showError(message) {
        setFieldError(step === 'phone' ? phoneField.input : otpField.input,
            step === 'phone' ? phoneField.error : otpField.error, message);
    }

    function clearErrors() {
        setFieldError(phoneField.input, phoneField.error, '');
        setFieldError(otpField.input, otpField.error, '');
    }

    function renderStep() {
        const isPhoneStep = step === 'phone';
        title.textContent = isPhoneStep ? 'ورود / ثبت‌نام در کلیدتو' : 'تایید شماره موبایل';
        description.textContent = isPhoneStep
            ? 'شماره موبایل خود را وارد کنید تا کد ورود یکبار مصرف ارسال شود'
            : `کد ۶ رقمی ارسال‌شده به شماره ${phone} را وارد نمایید`;

        phoneField.group.hidden = !isPhoneStep;
        otpField.group.hidden = isPhoneStep;
        editPhoneButton.hidden = isPhoneStep;
        submitButton.classList.toggle('kp-btn-gold', role === 'technician' && isPhoneStep);
        submitButton.classList.toggle('kp-btn-primary', !(role === 'technician' && isPhoneStep));
        submitButton.dataset.defaultLabel = isPhoneStep
            ? 'دریافت کد تایید یکبار مصرف'
            : 'تایید و ورود به حساب';
        if (!loading) submitButton.textContent = submitButton.dataset.defaultLabel;
        customerButton.classList.toggle('is-active', role === 'customer');
        technicianButton.classList.toggle('is-active', role === 'technician');
        customerButton.setAttribute('aria-pressed', role === 'customer' ? 'true' : 'false');
        technicianButton.setAttribute('aria-pressed', role === 'technician' ? 'true' : 'false');
    }

    function selectRole(nextRole) {
        if (loading) return;
        role = nextRole;
        step = 'phone';
        clearErrors();
        toast.hidden = true;
        renderStep();
    }

    customerButton.addEventListener('click', () => selectRole('customer'));
    technicianButton.addEventListener('click', () => selectRole('technician'));

    editPhoneButton.addEventListener('click', () => {
        if (loading) return;
        step = 'phone';
        otp = '';
        otpField.input.value = '';
        toast.hidden = true;
        clearErrors();
        renderStep();
        phoneField.input.focus();
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (loading) return;
        clearErrors();

        if (step === 'phone') {
            phone = phoneField.input.value;
            if (!PHONE_PATTERN.test(phone)) {
                showError('شماره موبایل باید با ۰۹ شروع شده و ۱۱ رقم باشد (مثال: ۰۹۱۲۳۴۵۶۷۸۹)');
                return;
            }
        } else {
            otp = otpField.input.value;
            if (otp.length !== 6) {
                showError('کد تایید باید ۶ رقمی باشد');
                return;
            }
        }

        loading = true;
        toast.hidden = true;
        setLoading(
            submitButton,
            true,
            step === 'phone' ? 'در حال ارسال کد...' : 'در حال بررسی...',
        );
        customerButton.disabled = true;
        technicianButton.disabled = true;
        editPhoneButton.disabled = true;

        try {
            if (step === 'phone') {
                const response = await fetch('/api/auth/otp/request', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ phone_number: phone }),
                });
                const data = await readResponse(response, 'خطا در ارسال کد تایید');
                toastText.textContent = data?.message || 'کد تایید پیامکی ارسال شد.';
                toast.hidden = false;
                step = 'otp';
                renderStep();
                otpField.input.focus();
            } else {
                const response = await fetch('/api/auth/otp/verify', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ phone_number: phone, code: otp, role }),
                });
                const data = await readResponse(response, 'کد وارد شده نامعتبر یا منقضی شده است');
                const session = data?.data;
                if (!session?.token || !Array.isArray(session.user?.roles)) {
                    throw new Error('پاسخ احراز هویت ساختار معتبری ندارد.');
                }

                sessionStorage.setItem(TOKEN_STORAGE_KEY, session.token);
                notifyAuthChanged();
                toastText.textContent = 'احراز هویت موفقیت‌آمیز بود! در حال انتقال...';
                toast.hidden = false;
                const authenticatedRole = session.user.roles.find((userRole) =>
                    ['super_admin', 'operator', 'dispatcher', 'support', 'accountant', 'content_manager']
                        .includes(userRole))
                    ?? (session.user.roles.includes('technician') ? 'technician' : 'customer');
                onLoginSuccess?.(authenticatedRole, phone);
            }
        } catch (error) {
            showError(error instanceof Error ? error.message : 'خطای ارتباط با سرور');
        } finally {
            loading = false;
            setLoading(submitButton, false, '');
            customerButton.disabled = false;
            technicianButton.disabled = false;
            editPhoneButton.disabled = false;
        }
    });

    form.append(phoneField.group, otpField.group, submitButton, editPhoneButton);
    roleSelector.append(customerButton, technicianButton);

    const adminLogin = createElement('details', { className: 'kp-vanilla-admin-login' });
    const adminLoginSummary = createElement('summary', { text: 'ورود مدیر با نام کاربری و رمز عبور' });
    const adminLoginForm = createElement('form', {
        className: 'kp-vanilla-auth-form',
        attributes: { novalidate: true },
    });
    const adminUsername = createField({
        id: 'admin-username',
        label: 'نام کاربری یا ایمیل مدیر',
        type: 'text',
        placeholder: 'نام کاربری',
        autocomplete: 'username',
        maxLength: 255,
    });
    const adminPassword = createField({
        id: 'admin-password',
        label: 'رمز عبور',
        type: 'password',
        placeholder: '',
        autocomplete: 'current-password',
        maxLength: 200,
    });
    const adminLoginError = createElement('p', {
        className: 'kp-vanilla-admin-login-error',
        attributes: { hidden: true },
    });
    const adminLoginButton = createElement('button', {
        className: 'kp-btn kp-btn-primary kp-vanilla-auth-submit',
        text: 'ورود به پنل مدیریت',
        attributes: { type: 'submit' },
    });
    adminLoginForm.append(adminUsername.group, adminPassword.group, adminLoginError, adminLoginButton);
    adminLoginForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (adminLoginButton.disabled) return;
        adminLoginError.hidden = true;
        adminUsername.error.textContent = '';
        adminPassword.error.textContent = '';

        if (!adminUsername.input.value.trim() || !adminPassword.input.value) {
            adminLoginError.textContent = 'نام کاربری و رمز عبور را وارد کنید.';
            adminLoginError.hidden = false;
            adminLoginError.setAttribute('role', 'alert');
            return;
        }

        adminLoginButton.disabled = true;
        adminLoginButton.setAttribute('aria-busy', 'true');
        adminLoginButton.textContent = 'در حال بررسی اطلاعات...';
        try {
            const response = await fetch('/api/auth/admin/login', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    username: adminUsername.input.value.trim(),
                    password: adminPassword.input.value,
                }),
            });
            const data = await readResponse(response, 'ورود مدیر ناموفق بود.');
            const challenge = data?.data;
            if (!['enroll', 'verify'].includes(challenge?.mode)) {
                throw new Error('پاسخ مرحلهٔ احراز هویت مدیر معتبر نیست.');
            }

            adminLoginButton.textContent = challenge.mode === 'enroll'
                ? 'در حال ثبت Passkey مدیر...'
                : 'در انتظار تأیید Passkey مدیر...';
            const passkeyResponse = challenge.mode === 'enroll'
                ? await Passkeys.register({
                    name: 'کلید مدیریت',
                    routes: {
                        options: '/api/auth/admin/passkeys/options',
                        submit: '/api/auth/admin/passkeys/register',
                    },
                })
                : await Passkeys.verify({
                    routes: {
                        options: '/api/auth/admin/passkeys/options',
                        submit: '/api/auth/admin/passkeys/verify',
                    },
                });
            const session = passkeyResponse?.data;
            if (!session?.token || !Array.isArray(session.user?.roles)) {
                throw new Error('پاسخ احراز هویت مدیر ساختار معتبری ندارد.');
            }
            sessionStorage.setItem(TOKEN_STORAGE_KEY, session.token);
            notifyAuthChanged();
            onLoginSuccess?.(session.user.admin_role || 'super_admin');
        } catch (error) {
            adminLoginError.textContent = error instanceof Error ? error.message : 'خطای ارتباط با سرور';
            adminLoginError.hidden = false;
            adminLoginError.setAttribute('role', 'alert');
        } finally {
            adminLoginButton.disabled = false;
            adminLoginButton.removeAttribute('aria-busy');
            adminLoginButton.textContent = 'ورود به پنل مدیریت';
        }
    });
    adminLogin.append(adminLoginSummary, adminLoginForm);
    card.append(toast, roleSelector, title, description, form, adminLogin);
    page.append(card);
    root.append(page);
    renderStep();

    return {
        destroy() {},
    };
}
