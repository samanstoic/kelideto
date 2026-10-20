function element(tag, { text, className, attributes } = {}) {
    const node = document.createElement(tag);
    if (text) node.textContent = text;
    if (className) node.className = className;
    if (attributes) {
        Object.entries(attributes).forEach(([name, value]) => {
            if (value === undefined || value === null || value === false) return;
            node.setAttribute(name, value === true ? '' : String(value));
        });
    }
    return node;
}

function field(id, label, type, { required = true, autocomplete, minLength, value } = {}) {
    const wrapper = element('label', { className: 'kp-setup-field', text: label });
    const input = element('input', {
        attributes: {
            id,
            name: id,
            type,
            required,
            autocomplete,
            minlength: minLength,
            value,
        },
    });
    const error = element('span', { className: 'kp-setup-field-error', attributes: { hidden: true } });
    wrapper.append(input, error);
    return { wrapper, input, error };
}

async function jsonResponse(response) {
    const data = await response.json();
    if (!response.ok) throw new Error(data?.message || 'درخواست در سرور انجام نشد.');
    return data;
}

export function renderInitialSetupPage(root) {
    const page = element('main', {
        className: 'kp-initial-setup',
        attributes: { 'aria-labelledby': 'kp-setup-title' },
    });
    const card = element('section', { className: 'kp-card kp-setup-card' });
    const title = element('h1', { text: 'راه‌اندازی اولیه کلیدتو', attributes: { id: 'kp-setup-title' } });
    const introduction = element('p', {
        className: 'kp-setup-introduction',
        text: 'اتصال سرور را بررسی کنید، مدیر ارشد را بسازید و تنظیمات پایه را ذخیره کنید.',
    });
    const notice = element('p', { className: 'kp-setup-notice', attributes: { role: 'status', hidden: true } });
    const readiness = element('section', {
        className: 'kp-setup-readiness',
        attributes: { 'aria-labelledby': 'kp-setup-readiness-title' },
    });
    readiness.append(element('h2', { text: 'آمادگی سرور', attributes: { id: 'kp-setup-readiness-title' } }));
    const requirements = element('ul', { className: 'kp-setup-checks' });
    const services = element('ul', { className: 'kp-setup-checks kp-setup-services' });
    readiness.append(requirements, services);
    const form = element('form', { className: 'kp-setup-form' });
    form.hidden = true;
    const adminSection = element('fieldset', { className: 'kp-setup-section' });
    adminSection.append(element('legend', { text: 'حساب مدیر ارشد' }));
    const name = field('setup-name', 'نام مدیر', 'text', { autocomplete: 'name' });
    const username = field('setup-username', 'نام کاربری', 'text', { autocomplete: 'username', minLength: 3 });
    username.input.autocomplete = 'username';
    username.input.pattern = '[A-Za-z0-9_-]+';
    const email = field('setup-email', 'ایمیل (اختیاری)', 'email', { required: false, autocomplete: 'email' });
    const setupToken = field('setup-token', 'کلید یک‌بارمصرف نصب سرور', 'password', {
        autocomplete: 'off',
        minLength: 32,
    });
    const password = field('setup-password', 'رمز عبور (حداقل ۱۲ نویسه)', 'password', {
        autocomplete: 'new-password',
        minLength: 12,
    });
    const passwordConfirmation = field('setup-password-confirmation', 'تکرار رمز عبور', 'password', {
        autocomplete: 'new-password',
        minLength: 12,
    });
    adminSection.append(
        name.wrapper,
        username.wrapper,
        email.wrapper,
        setupToken.wrapper,
        password.wrapper,
        passwordConfirmation.wrapper,
    );

    const settingsSection = element('fieldset', { className: 'kp-setup-section' });
    settingsSection.append(element('legend', { text: 'تنظیمات پایه پروژه' }));
    const siteName = field('setup-site-name', 'نام نمایشی سامانه', 'text', { value: 'کلیدتو' });
    const contactEmail = field('setup-contact-email', 'ایمیل پشتیبانی', 'email', { required: false });
    const supportPhone = field('setup-support-phone', 'تلفن پشتیبانی', 'tel', {
        required: false,
        autocomplete: 'tel',
    });
    const timezoneLabel = element('label', { className: 'kp-setup-field', text: 'منطقه زمانی' });
    const timezone = element('select', { attributes: { id: 'setup-timezone', name: 'timezone', required: true } });
    timezone.append(element('option', { text: 'تهران (Asia/Tehran)', attributes: { value: 'Asia/Tehran' } }));
    timezoneLabel.append(timezone);
    const responseWindow = field('setup-response-window', 'مهلت پاسخ متخصص (ثانیه)', 'number', { value: '90' });
    responseWindow.input.min = '30';
    responseWindow.input.max = '600';
    responseWindow.input.step = '1';
    const retention = field('setup-location-retention', 'نگهداری موقعیت مأموریت (ساعت)', 'number', { value: '4' });
    retention.input.min = '1';
    retention.input.max = '8';
    retention.input.step = '1';
    const currency = element('p', {
        className: 'kp-setup-fixed-setting',
        text: 'واحد پول سامانه: ریال (IRR، بدون تبدیل خودکار)',
    });
    settingsSection.append(
        siteName.wrapper,
        contactEmail.wrapper,
        supportPhone.wrapper,
        timezoneLabel,
        responseWindow.wrapper,
        retention.wrapper,
        currency,
    );
    const submit = element('button', {
        className: 'kp-btn kp-btn-primary kp-setup-submit',
        text: 'ذخیره تنظیمات و ایجاد مدیر',
        attributes: { type: 'submit' },
    });
    form.append(adminSection, settingsSection, submit);

    const loginLink = element('a', {
        text: 'رفتن به صفحه ورود مدیر',
        attributes: { href: '/auth?frontend=vanilla', 'data-router-link': '' },
    });
    loginLink.hidden = true;
    card.append(title, introduction, notice, readiness, form, loginLink);
    page.append(card);
    root.append(page);

    let setupReady = false;
    let destroyed = false;
    const setNotice = (message, isError = false) => {
        notice.textContent = message;
        notice.hidden = !message;
        notice.classList.toggle('is-error', isError);
    };

    const refreshStatus = async () => {
        const response = await fetch('/api/setup/status', { headers: { Accept: 'application/json' } });
        const result = await jsonResponse(response);
        const data = result.data;
        requirements.replaceChildren();
        data.requirements.forEach((item) => {
            requirements.append(element('li', {
                text: `${item.ready ? 'آماده' : 'نیازمند تنظیم'} — ${item.label}`,
                className: item.ready ? 'is-ready' : 'is-not-ready',
            }));
        });
        services.replaceChildren();
        Object.entries(data.services).forEach(([key, service]) => {
            const labels = { sms: 'پیامک', web_push: 'اعلان وب', payment_gateway: 'درگاه پرداخت' };
            services.append(element('li', {
                text: `${labels[key] || key}: ${service.status === 'connected' ? 'متصل' : 'قطع/تأییدنشده'} (${service.provider || 'سرویس'})`,
                className: service.status === 'connected' ? 'is-ready' : 'is-not-ready',
            }));
        });
        if (data.setup_required && data.ready) {
            setupReady = true;
            form.hidden = false;
            setNotice('');
        } else if (data.setup_required) {
            setupReady = false;
            form.hidden = true;
            setNotice('پیش‌نیازهای سرور کامل نیست. موارد بالا را در تنظیمات سرور بررسی کنید.', true);
        } else {
            setupReady = false;
            form.hidden = true;
            loginLink.hidden = false;
            setNotice('راه‌اندازی قبلاً انجام شده است؛ برای ورود به پنل از صفحه ورود مدیر استفاده کنید.');
        }
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!setupReady || submit.disabled) return;
        if (password.input.value !== passwordConfirmation.input.value) {
            passwordConfirmation.error.textContent = 'تکرار رمز عبور با رمز واردشده یکسان نیست.';
            passwordConfirmation.error.hidden = false;
            passwordConfirmation.input.focus();
            return;
        }

        submit.disabled = true;
        submit.setAttribute('aria-busy', 'true');
        submit.textContent = 'در حال ذخیره امن تنظیمات...';
        setNotice('');
        try {
            const response = await fetch('/api/setup/complete', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    name: name.input.value,
                    username: username.input.value,
                    email: email.input.value || null,
                    setup_token: setupToken.input.value,
                    password: password.input.value,
                    password_confirmation: passwordConfirmation.input.value,
                    settings: {
                        site_name: siteName.input.value,
                        contact_email: contactEmail.input.value || null,
                        support_phone: supportPhone.input.value || null,
                        timezone: timezone.value,
                        dispatch_response_window_seconds: Number(responseWindow.input.value),
                        location_retention_hours: Number(retention.input.value),
                    },
                }),
            });
            await jsonResponse(response);
            setupToken.input.value = '';
            password.input.value = '';
            passwordConfirmation.input.value = '';
            form.hidden = true;
            loginLink.hidden = false;
            setNotice('مدیر ارشد و تنظیمات با موفقیت در سرور ثبت شدند. اکنون می‌توانید وارد شوید.');
        } catch (error) {
            setNotice(error instanceof Error ? error.message : 'ارتباط با سرور ناموفق بود.', true);
        } finally {
            if (!destroyed) {
                submit.disabled = false;
                submit.removeAttribute('aria-busy');
                submit.textContent = 'ذخیره تنظیمات و ایجاد مدیر';
            }
        }
    });

    refreshStatus().catch((error) => {
        setupReady = false;
        form.hidden = true;
        setNotice(error instanceof Error ? error.message : 'دریافت وضعیت سرور ناموفق بود.', true);
    });

    return () => {
        destroyed = true;
    };
}
