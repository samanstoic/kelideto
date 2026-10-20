import { apiClient } from '../api/client.js';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const SECTIONS = [
    ['dashboard', 'داشبورد'],
    ['users', 'کاربران و نقش‌ها'],
    ['technicians', 'متخصصان'],
    ['bookings', 'سفارش و اعزام'],
    ['map', 'نقشه عملیات'],
    ['catalog', 'خدمات و دسته‌بندی'],
    ['areas', 'مناطق و تعرفه'],
    ['finance', 'مالی و تسویه'],
    ['tickets', 'شکایت و پشتیبانی'],
    ['notifications', 'اعلان و قالب‌ها'],
    ['content', 'محتوا و صفحات'],
    ['settings', 'تنظیمات و اتصال‌ها'],
    ['audit', 'امنیت و ممیزی'],
    ['reports', 'گزارش‌ها و خروجی'],
    ['access', 'سیاست‌های دسترسی'],
];

function element(tag, { text, className, type, attributes = {} } = {}) {
    const node = document.createElement(tag);
    if (text !== undefined && text !== null) node.textContent = String(text);
    if (className) node.className = className;
    if (type) node.type = type;
    Object.entries(attributes).forEach(([key, value]) => {
        if (value !== undefined && value !== null) node.setAttribute(key, String(value));
    });
    return node;
}

function input(label, name, { value = '', required = false, kind = 'text', placeholder = '' } = {}) {
    const wrapper = element('label', { className: 'kp-admin-field' });
    wrapper.append(element('span', { text: label }));
    const control = kind === 'textarea'
        ? element('textarea', { attributes: { name, required: required ? 'required' : undefined, rows: '5' } })
        : element('input', {
            type: kind,
            attributes: { name, required: required ? 'required' : undefined, placeholder },
        });
    control.value = value;
    wrapper.append(control);
    return { wrapper, control };
}

function formatValue(value) {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}

function jsonRecord(record) {
    return Object.fromEntries(Object.entries(record).filter(([key]) =>
        !['id', 'created_at', 'updated_at', 'deleted_at'].includes(key)));
}

export function renderAdminConsole(root) {
    const controller = new AbortController();
    const page = element('main', { className: 'kp-admin-page', attributes: { dir: 'rtl' } });
    const intro = element('header', { className: 'kp-admin-heading' });
    intro.append(element('h1', { text: 'پنل مدیریت کلیدتو' }));
    intro.append(element('p', { text: 'مدیریت عملیاتی متصل به داده‌های سامانه؛ دسترسی هر بخش بر اساس مجوز سمت سرور کنترل می‌شود.' }));
    const layout = element('div', { className: 'kp-admin-layout' });
    const nav = element('nav', { className: 'kp-admin-nav', attributes: { 'aria-label': 'بخش‌های مدیریت' } });
    const content = element('section', { className: 'kp-admin-content', attributes: { 'aria-live': 'polite' } });
    layout.append(nav, content);
    page.append(intro, layout);
    root.append(page);

    let activeSection = new URLSearchParams(window.location.search).get('section') || 'dashboard';
    let searchTerm = '';
    let currentPage = 1;
    let redirectPage = 1;
    let supportView = 'tickets';
    let activeMap;

    function setNotice(message, isError = false) {
        let notice = content.querySelector('[data-admin-notice]');
        if (!notice) {
            notice = element('p', { className: 'kp-admin-notice', attributes: { 'data-admin-notice': '', role: 'status' } });
            content.prepend(notice);
        }
        notice.textContent = message;
        notice.classList.toggle('is-error', isError);
    }

    async function request(path, options) {
        return apiClient.request(path, { ...options, signal: controller.signal });
    }

    function renderNav() {
        nav.replaceChildren();
        SECTIONS.forEach(([key, label]) => {
            const button = element('button', {
                className: `kp-admin-nav-item${activeSection === key ? ' is-active' : ''}`,
                text: label,
                type: 'button',
                attributes: { 'aria-current': activeSection === key ? 'page' : undefined },
            });
            button.addEventListener('click', () => {
                activeSection = key;
                currentPage = 1;
                searchTerm = '';
                renderNav();
                loadSection();
            });
            nav.append(button);
        });
    }

    function renderTable(records, { emptyMessage = 'داده‌ای برای نمایش وجود ندارد.' } = {}) {
        if (!records.length) {
            content.append(element('p', { className: 'kp-admin-empty', text: emptyMessage, attributes: { role: 'status' } }));
            return;
        }
        const keys = [...new Set(records.flatMap((record) => Object.keys(record)))]
            .filter((key) => !['deleted_at'].includes(key));
        const wrapper = element('div', { className: 'kp-admin-table-wrap' });
        const table = element('table', { className: 'kp-admin-table' });
        const head = element('thead');
        const heading = element('tr');
        keys.forEach((key) => heading.append(element('th', { text: key, attributes: { scope: 'col' } })));
        heading.append(element('th', { text: 'عملیات', attributes: { scope: 'col' } }));
        head.append(heading);
        const body = element('tbody');
        records.forEach((record) => {
            const row = element('tr');
            keys.forEach((key) => row.append(element('td', { text: formatValue(record[key]) })));
            const actions = element('td', { className: 'kp-admin-actions' });
            actions.dataset.actionsFor = String(record.id ?? record.technician_id ?? record.booking_id ?? '');
            row.append(actions);
            body.append(row);
        });
        table.append(head, body);
        wrapper.append(table);
        content.append(wrapper);
        return body;
    }

    function renderPager(pageData, reload) {
        if (!pageData || !pageData.last_page || pageData.last_page <= 1) return;
        const pager = element('div', { className: 'kp-admin-pager' });
        const previous = element('button', { text: 'قبلی', type: 'button', attributes: { disabled: pageData.current_page <= 1 ? '' : undefined } });
        const pageLabel = element('span', { text: `صفحه ${pageData.current_page} از ${pageData.last_page} • ${pageData.total} مورد` });
        const next = element('button', { text: 'بعدی', type: 'button', attributes: { disabled: pageData.current_page >= pageData.last_page ? '' : undefined } });
        previous.addEventListener('click', () => { currentPage -= 1; reload(); });
        next.addEventListener('click', () => { currentPage += 1; reload(); });
        pager.append(previous, pageLabel, next);
        content.append(pager);
    }

    function searchForm(onSearch) {
        const form = element('form', { className: 'kp-admin-search' });
        const query = input('جست‌وجو', 'q', { placeholder: 'عبارت جست‌وجو' });
        query.control.value = searchTerm;
        const submit = element('button', { text: 'جست‌وجو', type: 'submit', className: 'kp-btn kp-btn-primary' });
        form.append(query.wrapper, submit);
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            searchTerm = query.control.value.trim();
            currentPage = 1;
            onSearch();
        });
        content.append(form);
    }

    function sensitiveFields(form, { includePassword = true } = {}) {
        const reason = input('دلیل (الزامی)', 'reason', { required: true, kind: 'textarea' });
        form.append(reason.wrapper);
        if (includePassword) {
            const password = input('رمز عبور فعلی (یا ورود مجدد OTP در ۵ دقیقه اخیر)', 'password', { kind: 'password' });
            const confirmation = input('برای تأیید، CONFIRM را وارد کنید', 'confirmation', { required: true });
            form.append(password.wrapper, confirmation.wrapper);
        }
    }

    function appendJsonEditor(record, onSave, { create = false, sensitive = false } = {}) {
        const form = element('form', { className: 'kp-admin-editor' });
        form.append(element('h3', { text: create ? 'ایجاد مورد' : `ویرایش مورد #${record.id}` }));
        const dataField = input('داده‌ها (JSON)', 'payload', {
            required: true,
            kind: 'textarea',
            value: JSON.stringify(create ? {} : jsonRecord(record), null, 2),
        });
        dataField.control.dir = 'ltr';
        form.append(dataField.wrapper);
        sensitiveFields(form, { includePassword: sensitive });
        const save = element('button', { type: 'submit', text: create ? 'ایجاد' : 'ذخیره تغییرات', className: 'kp-btn kp-btn-primary' });
        form.append(save);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            try {
                const payload = JSON.parse(dataField.control.value);
                if (!payload || Array.isArray(payload) || typeof payload !== 'object') throw new Error('داده باید یک شیء JSON باشد.');
                const formData = new FormData(form);
                const body = { ...payload, reason: formData.get('reason') };
                if (sensitive) {
                    body.password = formData.get('password');
                    body.confirmation = formData.get('confirmation');
                }
                await onSave(body);
                await loadSection();
                setNotice('تغییرات با موفقیت ذخیره شد.');
            } catch (error) {
                setNotice(error instanceof SyntaxError ? 'JSON واردشده معتبر نیست.' : error.message, true);
            }
        });
        return form;
    }

    function renderPaginated(path, records, actionFor, customSearch = false) {
        if (customSearch) searchForm(loadSection);
        const tbody = renderTable(records.data || []);
        if (tbody && actionFor) {
            [...tbody.rows].forEach((row, index) => {
                const actions = row.querySelector('[data-actions-for]');
                actionFor(records.data[index], actions);
            });
        }
        renderPager(records, loadSection);
    }

    async function loadDashboard() {
        const response = await request('admin/console/dashboard');
        const data = response.data;
        const cards = element('div', { className: 'kp-admin-stats' });
        [
            ['کاربران', data.users], ['کاربران فعال', data.active_users],
            ['متخصصان در انتظار', data.technicians_pending], ['متخصصان تأییدشده', data.technicians_verified],
            ['سفارش‌های جاری', data.open_bookings], ['تیکت‌های باز', data.open_tickets],
            ['تسویه‌های در انتظار', data.payments_pending_settlement],
        ].forEach(([label, value]) => {
            const card = element('article', { className: 'kp-admin-stat' });
            card.append(element('span', { text: label }), element('strong', { text: value }));
            cards.append(card);
        });
        content.append(cards, element('h2', { text: 'سفارش‌های اخیر' }));
        renderTable(data.recent_bookings);
        content.append(element('p', { className: 'kp-admin-muted', text: `به‌روزرسانی: ${data.as_of}` }));
    }

    async function loadUsers() {
        searchForm(loadSection);
        const response = await request(`admin/console/users?page=${currentPage}&q=${encodeURIComponent(searchTerm)}`);
        const { data: pageData } = response;
        renderPaginated('', pageData, (user, actions) => {
            const editButton = element('button', {
                type: 'button', className: 'kp-btn kp-btn-secondary', text: 'ویرایش وضعیت و نقش',
            });
            editButton.addEventListener('click', () => {
                const form = element('form', { className: 'kp-admin-inline-form' });
                const status = element('select', { attributes: { name: 'status', 'aria-label': 'وضعیت حساب' } });
                [['active', 'فعال'], ['suspended', 'معلق']].forEach(([value, label]) => {
                    const option = element('option', { text: label, attributes: { value } });
                    option.selected = value === user.status;
                    status.append(option);
                });
                const roles = input('نقش‌ها (با ویرگول جدا کنید)', 'roles', { value: (user.roles || []).join(',') });
                const reason = input('دلیل', 'reason', { required: true, kind: 'textarea' });
                const password = input('رمز عبور فعلی (یا ورود مجدد OTP در ۵ دقیقه اخیر)', 'password', { kind: 'password' });
                const confirmation = input('تأیید مجدد: CONFIRM', 'confirmation', { required: true });
                const submit = element('button', { text: 'اعمال', type: 'submit', className: 'kp-btn kp-btn-primary' });
                form.append(status, roles.wrapper, reason.wrapper, password.wrapper, confirmation.wrapper, submit);
                actions.append(form);
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    const data = new FormData(form);
                    try {
                        await request(`admin/console/users/${user.id}`, {
                            method: 'PATCH', body: {
                                status: data.get('status'),
                                roles: data.get('roles').split(',').map((role) => role.trim()).filter(Boolean),
                                reason: data.get('reason'), password: data.get('password'),
                                confirmation: data.get('confirmation'),
                            },
                        });
                        await loadSection();
                        setNotice('کاربر به‌روزرسانی شد.');
                    } catch (error) { setNotice(error.message, true); }
                });
            });
            actions.append(editButton);
        });
    }

    async function loadTechnicians() {
        searchForm(loadSection);
        const { data: pageData } = await request(`admin/console/technicians?page=${currentPage}&q=${encodeURIComponent(searchTerm)}`);
        renderPaginated('', pageData, (technician, actions) => {
            const button = element('button', { text: 'بررسی وضعیت', type: 'button', className: 'kp-btn kp-btn-secondary' });
            button.addEventListener('click', () => {
                const form = element('form', { className: 'kp-admin-inline-form' });
                const status = element('select', { attributes: { name: 'status', 'aria-label': 'وضعیت متخصص' } });
                [['verified', 'تأیید'], ['suspended', 'تعلیق'], ['rejected', 'رد']].forEach(([value, label]) =>
                    status.append(element('option', { text: label, attributes: { value } })));
                const reason = input('دلیل', 'reason', { required: true, kind: 'textarea' });
                const password = input('رمز عبور فعلی (یا ورود مجدد OTP در ۵ دقیقه اخیر)', 'password', { kind: 'password' });
                const confirmation = input('تأیید مجدد: CONFIRM', 'confirmation', { required: true });
                const submit = element('button', { text: 'ذخیره', type: 'submit', className: 'kp-btn kp-btn-primary' });
                form.append(status, reason.wrapper, password.wrapper, confirmation.wrapper, submit);
                actions.append(form);
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    const values = new FormData(form);
                    try {
                        await request(`admin/console/technicians/${technician.id}`, {
                            method: 'PATCH', body: Object.fromEntries(values.entries()),
                        });
                        content.append(element('h2', { text: 'مدارک در انتظار بررسی' }));
                        let pending;
                        try {
                            pending = await request('admin/technicians/documents/pending');
                        } catch (error) {
                            if (error.status !== 403) throw error;
                            content.append(element('p', { className: 'kp-admin-muted', text: 'مجوز بررسی اسناد برای نقش فعلی فعال نیست.' }));
                            return;
                        }
                        const documents = pending.data || [];
                        const body = renderTable(documents);
                        if (body) [...body.rows].forEach((row, index) => {
                            const documentRecord = documents[index];
                            const actions = row.querySelector('[data-actions-for]');
                            const view = element('button', { text: 'نمایش سند خصوصی', type: 'button', className: 'kp-btn kp-btn-secondary' });
                            view.addEventListener('click', async () => {
                                try {
                                    const token = sessionStorage.getItem('keypro_api_token');
                                    const response = await fetch(`/api/admin/technicians/${documentRecord.technician_id}/documents/${documentRecord.id}`, {
                                        headers: token ? { Authorization: `Bearer ${token}` } : {},
                                    });
                                    if (!response.ok) throw new Error(`نمایش سند با خطای ${response.status} روبه‌رو شد.`);
                                    const url = URL.createObjectURL(await response.blob());
                                    const link = element('a', { attributes: { href: url, target: '_blank', rel: 'noopener noreferrer' } });
                                    link.click();
                                    window.setTimeout(() => URL.revokeObjectURL(url), 60000);
                                } catch (error) { setNotice(error.message, true); }
                            });
                            const review = element('button', { text: 'تأیید یا رد سند', type: 'button', className: 'kp-btn kp-btn-secondary' });
                            review.addEventListener('click', () => {
                                const form = element('form', { className: 'kp-admin-inline-form' });
                                const decision = element('select', { attributes: { name: 'decision', 'aria-label': 'تصمیم سند' } });
                                [['approved', 'تأیید'], ['rejected', 'رد']].forEach(([value, label]) =>
                                    decision.append(element('option', { text: label, attributes: { value } })));
                                const reason = input('دلیل بررسی (برای رد الزامی)', 'reason');
                                form.append(decision, reason.wrapper,
                                    element('button', { text: 'ثبت تصمیم', type: 'submit', className: 'kp-btn kp-btn-primary' }));
                                actions.append(form);
                                form.addEventListener('submit', async (event) => {
                                    event.preventDefault();
                                    const values = new FormData(form);
                                    const choice = values.get('decision');
                                    const explanation = values.get('reason');
                                    if (choice === 'rejected' && explanation.trim().length < 3) {
                                        setNotice('برای رد سند، دلیل حداقل سه نویسه‌ای وارد کنید.', true);
                                        return;
                                    }
                                    try {
                                        await request(`admin/technicians/${documentRecord.technician_id}/documents/${documentRecord.id}/review`, {
                                            method: 'POST',
                                            body: {
                                                decision: choice,
                                                ...(choice === 'rejected' ? { rejection_reason: explanation } : {}),
                                                reason: explanation || 'بررسی مدارک متخصص',
                                            },
                                        });
                                        await loadSection();
                                        setNotice('تصمیم بررسی سند ثبت شد.');
                                    } catch (error) { setNotice(error.message, true); }
                                });
                            });
                            actions.append(view, review);
                        });
                        await loadSection();
                        setNotice('وضعیت متخصص به‌روزرسانی شد.');
                    } catch (error) { setNotice(error.message, true); }
                });
            });
            actions.append(button);
        });
    }

    async function loadBookings() {
        searchForm(loadSection);
        const { data: pageData } = await request(`admin/console/bookings?page=${currentPage}&q=${encodeURIComponent(searchTerm)}`);
        renderPaginated('', pageData, (booking, actions) => {
            const offers = element('button', { text: 'پیشنهاد خودکار', type: 'button', className: 'kp-btn kp-btn-secondary' });
            offers.addEventListener('click', async () => {
                try {
                    const result = await request(`admin/bookings/${booking.id}/dispatch`, {
                        method: 'POST', body: { response_window_seconds: 90 },
                    });
                    setNotice(result.data?.status === 'no_eligible_technicians'
                        ? 'متخصص واجد شرایطی برای این سفارش پیدا نشد.'
                        : 'پیشنهاد مأموریت ثبت شد.');
                    await loadSection();
                } catch (error) { setNotice(error.message, true); }
            });
            const assign = element('button', { text: 'تخصیص دستی', type: 'button', className: 'kp-btn kp-btn-secondary' });
            assign.addEventListener('click', () => {
                const form = element('form', { className: 'kp-admin-inline-form' });
                const technician = input('شناسه پروفایل متخصص', 'technician_id', { required: true, kind: 'number' });
                const reason = input('دلیل تخصیص دستی', 'reason', { required: true, kind: 'textarea' });
                const submit = element('button', { text: 'تخصیص', type: 'submit', className: 'kp-btn kp-btn-primary' });
                form.append(technician.wrapper, reason.wrapper, submit);
                actions.append(form);
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    try {
                        await request(`admin/bookings/${booking.id}/assign`, {
                            method: 'POST', body: Object.fromEntries(new FormData(form).entries()),
                        });
                        await loadSection();
                        setNotice('تخصیص مأموریت ثبت شد.');
                    } catch (error) { setNotice(error.message, true); }
                });
            });
            const schedule = element('button', { text: 'تغییر زمان', type: 'button', className: 'kp-btn kp-btn-secondary' });
            schedule.addEventListener('click', () => {
                const form = element('form', { className: 'kp-admin-inline-form' });
                const eventType = element('select', { attributes: { name: 'event', 'aria-label': 'نوع تغییر زمان' } });
                [['booking.delayed', 'تأخیر'], ['booking.rescheduled', 'تغییر زمان']].forEach(([value, label]) =>
                    eventType.append(element('option', { text: label, attributes: { value } })));
                const time = input('زمان جدید', 'scheduled_at', { required: true, kind: 'datetime-local' });
                const reason = input('دلیل تغییر زمان', 'reason');
                form.append(eventType, time.wrapper, reason.wrapper,
                    element('button', { text: 'ثبت زمان جدید', type: 'submit', className: 'kp-btn kp-btn-primary' }));
                actions.append(form);
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    try {
                        await request(`admin/bookings/${booking.id}/schedule`, {
                            method: 'POST', body: Object.fromEntries(new FormData(form).entries()),
                        });
                        await loadSection();
                        setNotice('زمان سفارش تغییر کرد و اعلان ثبت شد.');
                    } catch (error) { setNotice(error.message, true); }
                });
            });
            const history = element('button', { text: 'رویدادهای اعزام', type: 'button', className: 'kp-btn kp-btn-secondary' });
            history.addEventListener('click', async () => {
                try {
                    const events = await request(`admin/bookings/${booking.id}/dispatch-events`);
                    actions.append(element('pre', {
                        className: 'kp-admin-json',
                        text: JSON.stringify(events.data, null, 2),
                    }));
                } catch (error) { setNotice(error.message, true); }
            });
            actions.append(offers, assign, schedule, history);
        });
    }

    async function loadResource(resource, { create = true, sensitive = false } = {}) {
        searchForm(loadSection);
        const response = await request(`admin/console/resources/${resource}?page=${currentPage}&q=${encodeURIComponent(searchTerm)}`);
        const pageData = response.data;
        const tbody = renderTable(pageData.data);
        if (tbody) [...tbody.rows].forEach((row, index) => {
            const record = pageData.data[index];
            const action = row.querySelector('[data-actions-for]');
            if (resource === 'content') {
                const remove = element('button', { text: 'حذف نرم', type: 'button', className: 'kp-btn kp-btn-danger' });
                remove.addEventListener('click', async () => {
                    const form = element('form', { className: 'kp-admin-inline-form' });
                    sensitiveFields(form);
                    form.append(element('button', { text: 'تأیید حذف', type: 'submit', className: 'kp-btn kp-btn-danger' }));
                    action.append(form);
                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();
                        const data = Object.fromEntries(new FormData(form).entries());
                        try {
                            await request(`admin/console/content/${record.id}`, { method: 'DELETE', body: data });
                            await loadSection();
                            setNotice('صفحه به‌صورت نرم حذف شد.');
                        } catch (error) { setNotice(error.message, true); }
                    });
                });
                action.append(remove);
            }
            action.append(appendJsonEditor(record, (body) => request(
                `admin/console/resources/${resource}/${record.id}`, { method: 'PATCH', body },
            ), { sensitive }));
        });
        if (create) {
            content.append(appendJsonEditor({}, (body) => request(
                `admin/console/resources/${resource}`, { method: 'POST', body },
            ), { create: true, sensitive }));
        }
        renderPager(pageData, loadSection);
    }

    async function loadContent() {
        searchForm(loadSection);
        const mediaResponse = await request('admin/console/content/media?per_page=50');
        const mediaRecords = mediaResponse.data.data;
        const mediaForm = element('form', { className: 'kp-admin-editor' });
        mediaForm.append(element('h3', { text: 'بارگذاری رسانه' }));
        const uploadField = input('تصویر (JPEG، PNG یا WebP، حداکثر ۵ مگابایت)', 'image', {
            required: true,
            kind: 'file',
        });
        uploadField.control.accept = 'image/jpeg,image/png,image/webp';
        mediaForm.append(uploadField.wrapper,
            element('button', { text: 'بارگذاری تصویر', type: 'submit', className: 'kp-btn kp-btn-primary' }));
        mediaForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            try {
                const body = new FormData();
                body.append('image', uploadField.control.files[0]);
                const uploaded = await request('admin/console/content/media', { method: 'POST', body });
                setNotice(`تصویر بارگذاری شد (شناسهٔ رسانه: ${uploaded.data.id}). هنگام انتشار باید آن را به صفحه پیوند دهید.`);
                await loadSection();
            } catch (error) { setNotice(error.message, true); }
        });
        content.append(mediaForm);

        content.append(element('h2', { text: 'رسانه‌های بارگذاری‌شده' }));
        renderTable(mediaRecords);
        renderPager(mediaResponse.data, loadSection);

        const editor = element('section', { className: 'kp-admin-content-editor' });
        const editorForm = (record = {}) => {
            const form = element('form', { className: 'kp-admin-editor' });
            form.append(element('h2', { text: record.id ? `ویرایش صفحه #${record.id}` : 'ایجاد صفحه یا مقاله' }));
            const title = input('عنوان', 'title', { required: true, value: record.title || '' });
            const slug = input('Slug لاتین یکتا', 'slug', { required: true, value: record.slug || '' });
            const excerpt = input('خلاصه', 'excerpt', { kind: 'textarea', value: record.excerpt || '' });
            const body = input('متن (متن ساده؛ HTML اجرا نمی‌شود)', 'body', {
                required: true, kind: 'textarea', value: record.body || '',
            });
            const metaTitle = input('Meta title', 'meta_title', { value: record.meta_title || '' });
            const metaDescription = input('Meta description', 'meta_description', {
                kind: 'textarea', value: record.meta_description || '',
            });
            const canonical = input('Canonical URL (اختیاری؛ فقط دامنهٔ همین سایت)', 'canonical_url', {
                value: record.canonical_url || '',
            });
            const image = element('select', { attributes: { name: 'og_image_id', 'aria-label': 'تصویر Open Graph' } });
            image.append(element('option', { text: 'بدون تصویر', attributes: { value: '' } }));
            mediaRecords.forEach((media) => image.append(element('option', {
                text: `#${media.id} ${media.original_name} (${media.width}×${media.height})`,
                attributes: {
                    value: media.id,
                    selected: String(media.id) === String(record.og_image_id) ? 'selected' : undefined,
                },
            })));
            const imageLabel = element('label', { className: 'kp-admin-field' });
            imageLabel.append(element('span', { text: 'تصویر Open Graph' }), image);
            const contentType = element('select', { attributes: { name: 'content_type', required: '' } });
            [['page', 'صفحه'], ['article', 'مقاله']].forEach(([value, label]) => contentType.append(element('option', {
                text: label,
                attributes: { value, selected: (record.content_type || 'page') === value ? 'selected' : undefined },
            })));
            const typeLabel = element('label', { className: 'kp-admin-field' });
            typeLabel.append(element('span', { text: 'نوع محتوا' }), contentType);
            const status = element('select', { attributes: { name: 'status', required: '' } });
            [['draft', 'پیش‌نویس'], ['published', 'انتشار'], ['archived', 'بایگانی']].forEach(([value, label]) => status.append(element('option', {
                text: label,
                attributes: { value, selected: (record.status || 'draft') === value ? 'selected' : undefined },
            })));
            const statusLabel = element('label', { className: 'kp-admin-field' });
            statusLabel.append(element('span', { text: 'وضعیت' }), status);
            const scheduled = input('زمان انتشار (اختیاری)', 'scheduled_at', {
                kind: 'datetime-local',
                value: record.scheduled_at ? record.scheduled_at.replace(' ', 'T').slice(0, 16) : '',
            });
            const reason = input('دلیل تغییر (الزامی)', 'reason', { required: true, kind: 'textarea' });
            form.append(title.wrapper, slug.wrapper, typeLabel, excerpt.wrapper, body.wrapper,
                metaTitle.wrapper, metaDescription.wrapper, canonical.wrapper, imageLabel, statusLabel, scheduled.wrapper,
                reason.wrapper);
            const actions = element('div', { className: 'kp-admin-actions' });
            const save = element('button', {
                text: record.id ? 'ذخیره محتوا' : 'ایجاد محتوا',
                type: 'submit',
                className: 'kp-btn kp-btn-primary',
            });
            const preview = element('button', {
                text: 'پیش‌نمایش',
                type: 'button',
                className: 'kp-btn kp-btn-secondary',
            });
            actions.append(save, preview);
            form.append(actions);
            const previewFrame = element('iframe', {
                className: 'kp-admin-content-preview',
                attributes: {
                    title: 'پیش‌نمایش محتوا',
                    sandbox: '',
                    hidden: true,
                },
            });
            preview.addEventListener('click', async () => {
                try {
                    const values = Object.fromEntries(new FormData(form).entries());
                    delete values.reason;
                    values.og_image_id = values.og_image_id || null;
                    const response = await request('admin/console/content/preview', { method: 'POST', body: values });
                    previewFrame.srcdoc = response.data.html;
                    previewFrame.hidden = false;
                } catch (error) { setNotice(error.message, true); }
            });
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const values = Object.fromEntries(new FormData(form).entries());
                values.excerpt = values.excerpt || null;
                values.meta_title = values.meta_title || null;
                values.meta_description = values.meta_description || null;
                values.canonical_url = values.canonical_url || null;
                values.og_image_id = values.og_image_id || null;
                values.scheduled_at = values.scheduled_at || null;
                try {
                    await request(record.id
                        ? `admin/console/resources/content/${record.id}`
                        : 'admin/console/resources/content', {
                        method: record.id ? 'PATCH' : 'POST',
                        body: values,
                    });
                    await loadSection();
                    setNotice(values.scheduled_at ? 'محتوا برای انتشار زمان‌بندی شد.' : 'محتوا ذخیره شد.');
                } catch (error) { setNotice(error.message, true); }
            });
            form.append(previewFrame);
            return form;
        };

        const response = await request(`admin/console/resources/content?page=${currentPage}&q=${encodeURIComponent(searchTerm)}`);
        const pageData = response.data;
        const visibleRecords = pageData.data.map((record) => ({
            id: record.id,
            title: record.title,
            slug: record.slug,
            content_type: record.content_type,
            status: record.status,
            scheduled_at: record.scheduled_at,
            published_at: record.published_at,
            updated_at: record.updated_at,
        }));
        const tbody = renderTable(visibleRecords);
        if (tbody) [...tbody.rows].forEach((row, index) => {
            const record = pageData.data[index];
            const actions = row.querySelector('[data-actions-for]');
            const edit = element('button', {
                text: 'ویرایش',
                type: 'button',
                className: 'kp-btn kp-btn-secondary',
            });
            edit.addEventListener('click', () => editor.replaceChildren(editorForm(record)));
            actions.append(edit);
            const remove = element('button', {
                text: 'حذف نرم',
                type: 'button',
                className: 'kp-btn kp-btn-danger',
            });
            remove.addEventListener('click', () => {
                const form = element('form', { className: 'kp-admin-inline-form' });
                sensitiveFields(form);
                form.append(element('button', { text: 'تأیید حذف', type: 'submit', className: 'kp-btn kp-btn-danger' }));
                actions.append(form);
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    try {
                        await request(`admin/console/content/${record.id}`, {
                            method: 'DELETE',
                            body: Object.fromEntries(new FormData(form).entries()),
                        });
                        await loadSection();
                        setNotice('محتوا به‌صورت نرم حذف شد.');
                    } catch (error) { setNotice(error.message, true); }
                });
            });
            actions.append(remove);
        });
        editor.append(editorForm());
        content.append(editor);
        renderPager(pageData, loadSection);

        content.append(element('h2', { text: 'بازنویسی و تغییر مسیر URL' }));
        const redirectResponse = await request(`admin/console/content/redirects?page=${redirectPage}&per_page=20`);
        const redirectData = redirectResponse.data;
        const redirectBody = renderTable(redirectData.data);
        if (redirectBody) [...redirectBody.rows].forEach((row, index) => {
            const redirect = redirectData.data[index];
            const actions = row.querySelector('[data-actions-for]');
            actions.append(appendJsonEditor({
                id: redirect.id,
                source_path: redirect.source_path,
                target_path: redirect.target_path,
                status_code: redirect.status_code,
                is_active: Boolean(redirect.is_active),
            }, (body) => request(`admin/console/content/redirects/${redirect.id}`, {
                method: 'PATCH',
                body,
            })));
            const remove = element('button', {
                text: 'حذف مسیر',
                type: 'button',
                className: 'kp-btn kp-btn-danger',
            });
            remove.addEventListener('click', () => {
                const form = element('form', { className: 'kp-admin-inline-form' });
                const reason = input('دلیل حذف مسیر', 'reason', { required: true, kind: 'textarea' });
                form.append(reason.wrapper, element('button', {
                    text: 'تأیید حذف',
                    type: 'submit',
                    className: 'kp-btn kp-btn-danger',
                }));
                actions.append(form);
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    try {
                        await request(`admin/console/content/redirects/${redirect.id}`, {
                            method: 'DELETE',
                            body: Object.fromEntries(new FormData(form).entries()),
                        });
                        await loadSection();
                        setNotice('مسیر بازنویسی حذف شد.');
                    } catch (error) { setNotice(error.message, true); }
                });
            });
            actions.append(remove);
        });
        if (redirectData.last_page > 1) {
            const redirectPager = element('div', { className: 'kp-admin-pager' });
            const previous = element('button', {
                text: 'مسیرهای جدیدتر',
                type: 'button',
                attributes: { disabled: redirectPage <= 1 ? '' : undefined },
            });
            const next = element('button', {
                text: 'مسیرهای قدیمی‌تر',
                type: 'button',
                attributes: { disabled: redirectPage >= redirectData.last_page ? '' : undefined },
            });
            previous.addEventListener('click', () => {
                redirectPage -= 1;
                void loadSection();
            });
            next.addEventListener('click', () => {
                redirectPage += 1;
                void loadSection();
            });
            redirectPager.append(previous, element('span', {
                text: `صفحه ${redirectData.current_page} از ${redirectData.last_page} • ${redirectData.total} مسیر`,
            }), next);
            content.append(redirectPager);
        }
        content.append(appendJsonEditor({
            source_path: '/old-page',
            target_path: '/pages/new-page',
            status_code: 301,
            is_active: true,
        }, (body) => request('admin/console/content/redirects', {
            method: 'POST',
            body,
        }), { create: true }));
    }

    async function loadTickets() {
        const tabs = element('div', { className: 'kp-admin-actions' });
        [
            ['tickets', 'تیکت‌ها'],
            ['reviews', 'نظرات در انتظار بررسی'],
            ['quality', 'گزارش کیفیت'],
        ].forEach(([view, label]) => {
            const button = element('button', {
                text: label,
                type: 'button',
                className: `kp-btn ${supportView === view ? 'kp-btn-primary' : 'kp-btn-secondary'}`,
            });
            button.addEventListener('click', () => {
                supportView = view;
                currentPage = 1;
                loadSection();
            });
            tabs.append(button);
        });
        content.append(tabs);
        if (supportView === 'reviews') return loadReviewModeration();
        if (supportView === 'quality') return loadQualityReport();

        searchForm(loadSection);
        const { data: pageData } = await request(
            `admin/support/tickets?page=${currentPage}&q=${encodeURIComponent(searchTerm)}`,
        );
        const tbody = renderTable(pageData.data);
        if (tbody) [...tbody.rows].forEach((row, index) => {
            const ticket = pageData.data[index];
            const actions = row.querySelector('[data-actions-for]');
            const conversation = element('section', { className: 'kp-admin-editor' });
            const messagesButton = element('button', {
                text: 'مشاهدهٔ پیام‌ها و تاریخچه',
                type: 'button',
                className: 'kp-btn kp-btn-secondary',
            });
            messagesButton.addEventListener('click', async () => {
                try {
                    const result = await request(`support/tickets/${encodeURIComponent(ticket.ticket_code)}`);
                    const details = result.data;
                    conversation.replaceChildren(
                        element('h3', { text: 'گفت‌وگو' }),
                        ...details.messages.data.map((message) => element('p', {
                            text: `${message.sender_user_id}: ${message.message}`,
                        })),
                        element('h3', { text: 'تاریخچهٔ رسیدگی' }),
                        ...details.history.map((entry) => element('p', {
                            text: `${entry.created_at} • ${entry.actor_name || 'سامانه'} • ${entry.event_type}${entry.reason ? ` • ${entry.reason}` : ''}`,
                        })),
                    );
                    actions.append(conversation);
                } catch (error) {
                    setNotice(error.message, true);
                }
            });
            actions.append(messagesButton);

            const triage = element('form', { className: 'kp-admin-inline-form' });
            const status = element('select', { attributes: { name: 'status', 'aria-label': 'وضعیت تیکت' } });
            [['open', 'باز'], ['in_progress', 'در حال رسیدگی'], ['resolved', 'حل‌شده'], ['closed', 'بسته']].forEach(([value, label]) => {
                const option = element('option', { text: label, attributes: { value } });
                option.selected = ticket.status === value;
                status.append(option);
            });
            const priority = element('select', { attributes: { name: 'priority', 'aria-label': 'اولویت تیکت' } });
            [['low', 'کم'], ['medium', 'متوسط'], ['high', 'زیاد'], ['emergency', 'فوری']].forEach(([value, label]) => {
                const option = element('option', { text: label, attributes: { value } });
                option.selected = ticket.priority === value;
                priority.append(option);
            });
            const assignee = input('شناسه کارشناس (خالی برای لغو ارجاع)', 'assigned_agent_id', {
                value: ticket.assigned_agent_id ?? '',
                kind: 'number',
            });
            const reason = input('دلیل تغییر یا ارجاع', 'reason', { required: true, kind: 'textarea' });
            triage.append(status, priority, assignee.wrapper, reason.wrapper,
                element('button', { type: 'submit', text: 'ثبت تغییرات', className: 'kp-btn kp-btn-primary' }));
            triage.addEventListener('submit', async (event) => {
                event.preventDefault();
                const data = Object.fromEntries(new FormData(triage).entries());
                data.assigned_agent_id = data.assigned_agent_id ? Number(data.assigned_agent_id) : null;
                try {
                    await request(`admin/support/tickets/${encodeURIComponent(ticket.ticket_code)}`, {
                        method: 'PATCH',
                        body: data,
                    });
                    await loadSection();
                    setNotice('تغییرات تیکت ثبت شد.');
                } catch (error) {
                    setNotice(error.message, true);
                }
            });
            actions.append(triage);

            if (ticket.status !== 'closed') {
                const reply = element('form', { className: 'kp-admin-inline-form' });
                const responseMessage = input('پاسخ برای مشتری', 'message', { required: true, kind: 'textarea' });
                reply.append(responseMessage.wrapper,
                    element('button', { type: 'submit', text: 'ارسال پاسخ', className: 'kp-btn kp-btn-secondary' }));
                let idempotencyKey = globalThis.crypto?.randomUUID?.()
                    ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`;
                reply.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    try {
                        await request(`support/tickets/${encodeURIComponent(ticket.ticket_code)}/messages`, {
                            method: 'POST',
                            body: {
                                message: responseMessage.control.value.trim(),
                                idempotency_key: idempotencyKey,
                            },
                        });
                        idempotencyKey = globalThis.crypto?.randomUUID?.()
                            ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`;
                        await loadSection();
                        setNotice('پاسخ برای مشتری ثبت شد.');
                    } catch (error) {
                        setNotice(error.message, true);
                    }
                });
                actions.append(reply);
            }

            if (['cost_review', 'payment_dispute'].includes(ticket.category)) {
                const decision = element('form', { className: 'kp-admin-inline-form' });
                const choice = element('select', { attributes: { name: 'decision', 'aria-label': 'تصمیم رسیدگی' } });
                [['reject', 'رد درخواست'], ['no_action', 'بدون اقدام'], ['cost_corrected', 'اصلاح هزینه'], ['full_refund', 'بازپرداخت کامل']].forEach(([value, label]) =>
                    choice.append(element('option', { text: label, attributes: { value } })));
                const decisionReason = input('دلیل مستند تصمیم', 'reason', { required: true, kind: 'textarea' });
                const password = input('رمز مدیر برای بازپرداخت', 'password', { kind: 'password' });
                const confirmation = input('برای تأیید بازپرداخت، CONFIRM را وارد کنید', 'confirmation');
                decision.append(choice, decisionReason.wrapper, password.wrapper, confirmation.wrapper,
                    element('button', { type: 'submit', text: 'ثبت تصمیم', className: 'kp-btn kp-btn-primary' }));
                decision.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    const data = Object.fromEntries(new FormData(decision).entries());
                    if (data.decision !== 'full_refund') {
                        delete data.password;
                        delete data.confirmation;
                    }
                    try {
                        await request(`admin/support/tickets/${encodeURIComponent(ticket.ticket_code)}/decision`, {
                            method: 'POST',
                            body: data,
                        });
                        await loadSection();
                        setNotice('تصمیم رسیدگی ثبت شد.');
                    } catch (error) {
                        setNotice(error.message, true);
                    }
                });
                actions.append(decision);
            }
        });
        renderPager(pageData, loadSection);
    }

    async function loadReviewModeration() {
        const { data: pageData } = await request(`admin/support/reviews?page=${currentPage}`);
        const tbody = renderTable(pageData.data);
        if (tbody) [...tbody.rows].forEach((row, index) => {
            const review = pageData.data[index];
            const form = element('form', { className: 'kp-admin-inline-form' });
            const reason = input('دلیل تصمیم moderation', 'reason', { required: true, kind: 'textarea' });
            const publish = element('input', { type: 'checkbox', attributes: { 'aria-label': 'انتشار نظر' } });
            const publishLabel = element('label', { text: 'انتشار نظر' });
            publishLabel.prepend(publish);
            form.append(publishLabel, reason.wrapper,
                element('button', { type: 'submit', text: 'ثبت تصمیم', className: 'kp-btn kp-btn-primary' }));
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                try {
                    await request(`admin/support/reviews/${review.id}`, {
                        method: 'PATCH',
                        body: { is_published: publish.checked, reason: reason.control.value },
                    });
                    await loadSection();
                    setNotice('تصمیم انتشار نظر ثبت شد.');
                } catch (error) {
                    setNotice(error.message, true);
                }
            });
            row.querySelector('[data-actions-for]').append(form);
        });
        renderPager(pageData, loadSection);
    }

    async function loadQualityReport() {
        const response = await request(`admin/support/quality?page=${currentPage}`);
        const data = response.data;
        content.append(element('h2', { text: 'شاخص‌های متخصصان' }));
        const pageData = data.technicians;
        renderTable(pageData.data);
        content.append(element('h2', { text: 'تیکت‌ها بر اساس وضعیت' }));
        renderTable(data.tickets_by_status);
        content.append(element('h2', { text: 'تیکت‌ها بر اساس دسته‌بندی' }));
        renderTable(data.tickets_by_category);
        content.append(element('p', { text: `زمان گزارش: ${data.generated_at}` }));
        renderPager(pageData, loadSection);
    }

    async function loadNotifications() {
        const [services, templates] = await Promise.all([
            request('admin/notifications/services'),
            request(`admin/console/resources/templates?page=${currentPage}&q=${encodeURIComponent(searchTerm)}`),
        ]);
        content.append(element('h2', { text: 'وضعیت اتصال سرویس‌ها' }));
        content.append(element('pre', { className: 'kp-admin-json', text: JSON.stringify(services.data, null, 2) }));
        content.append(element('h2', { text: 'قالب‌های اعلان' }));
        const body = renderTable(templates.data.data);
        if (body) [...body.rows].forEach((row, index) => {
            const template = templates.data.data[index];
            const actions = row.querySelector('[data-actions-for]');
            const form = element('form', { className: 'kp-admin-inline-form' });
            const title = input('عنوان فارسی', 'title_fa', { value: template.title_fa || '' });
            const text = input('متن پیام', 'body_fa', { required: true, kind: 'textarea', value: template.body_fa });
            const reason = input('دلیل تغییر قالب', 'reason', { required: true });
            const active = element('input', { type: 'checkbox', attributes: { name: 'is_active', value: '1' } });
            active.checked = Boolean(template.is_active);
            const activeLabel = element('label', { text: 'قالب فعال' });
            activeLabel.prepend(active);
            form.append(title.wrapper, text.wrapper, reason.wrapper, activeLabel,
                element('button', { type: 'submit', text: 'ذخیره قالب', className: 'kp-btn kp-btn-primary' }));
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const values = new FormData(form);
                try {
                    await request(`admin/notifications/templates/${encodeURIComponent(template.event_key)}/${encodeURIComponent(template.channel)}`, {
                        method: 'PATCH',
                        body: {
                            title_fa: values.get('title_fa'), body_fa: values.get('body_fa'),
                            is_active: active.checked, reason: values.get('reason'),
                        },
                    });
                    setNotice('قالب اعلان ذخیره شد.');
                } catch (error) { setNotice(error.message, true); }
            });
            actions.append(form);
        });
        renderPager(templates.data, loadSection);
    }

    async function loadFinance() {
        const [summary, gateway, ledger, settlements, payments] = await Promise.all([
            request('finance/reports/summary'), request('finance/gateway'),
            request('finance/ledger'), request('finance/settlements'),
            request(`admin/console/payments?page=${currentPage}&per_page=100`),
        ]);
        content.append(element('h2', { text: 'وضعیت درگاه' }),
            element('pre', { className: 'kp-admin-json', text: JSON.stringify(gateway.data, null, 2) }),
            element('h2', { text: 'گزارش درآمد و کارمزد' }),
            element('pre', { className: 'kp-admin-json', text: JSON.stringify(summary.data, null, 2) }),
            element('h2', { text: 'تسویه‌ها' }));
        const settlementBody = renderTable(settlements.data);
        if (settlementBody) [...settlementBody.rows].forEach((row, index) => {
            const settlement = settlements.data[index];
            const actions = row.querySelector('[data-actions-for]');
            if (!actions || settlement.status !== 'pending' && settlement.status !== 'approved' && settlement.status !== 'processing') return;
            const form = element('form', { className: 'kp-admin-inline-form' });
            const decision = element('select', { attributes: { name: 'decision', 'aria-label': 'تصمیم تسویه' } });
            [['approve', 'تأیید'], ['reject', 'رد'], ['settled', 'ثبت واریز']].forEach(([value, label]) =>
                decision.append(element('option', { text: label, attributes: { value } })));
            const reason = input('دلیل', 'reason', { required: true });
            const reference = input('شناسه حواله (برای ثبت واریز)', 'payout_reference');
            const password = input('رمز عبور فعلی (یا ورود مجدد OTP در ۵ دقیقه اخیر)', 'password', { kind: 'password' });
            const confirmation = input('تأیید مجدد: CONFIRM', 'confirmation', { required: true });
            form.append(decision, reason.wrapper, reference.wrapper, password.wrapper, confirmation.wrapper,
                element('button', { text: 'ثبت تصمیم', type: 'submit', className: 'kp-btn kp-btn-primary' }));
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                try {
                    await request(`admin/console/settlements/${settlement.settlement_uuid}/review`, {
                        method: 'POST', body: Object.fromEntries(new FormData(form).entries()),
                    });
                    await loadSection();
                    setNotice('تصمیم تسویه ثبت شد.');
                } catch (error) { setNotice(error.message, true); }
            });
            actions.append(form);
        });
        content.append(element('h2', { text: 'تراکنش‌های پرداخت' }));
        searchForm(async () => {
            try {
                const response = await request(`admin/console/payments?page=${currentPage}&q=${encodeURIComponent(searchTerm)}`);
                content.replaceChildren(element('h2', { text: 'تراکنش‌های پرداخت' }));
                renderPaymentTable(response.data);
            } catch (error) { setNotice(error.message, true); }
        });
        renderPaymentTable(payments.data);
        content.append(element('h2', { text: 'دفترکل' }));
        renderTable(ledger.data);

        function renderPaymentTable(pageData) {
            const body = renderTable(pageData.data);
            if (!body) return;
            [...body.rows].forEach((row, index) => {
                const payment = pageData.data[index];
                const actions = row.querySelector('[data-actions-for]');
                if (!actions || payment.status !== 'paid') return;
                const button = element('button', { text: 'بازپرداخت', type: 'button', className: 'kp-btn kp-btn-danger' });
                button.addEventListener('click', () => {
                    const form = element('form', { className: 'kp-admin-inline-form' });
                    const reason = input('دلیل بازپرداخت', 'reason', { required: true, kind: 'textarea' });
                    const password = input('رمز عبور فعلی (یا ورود مجدد OTP در ۵ دقیقه اخیر)', 'password', { kind: 'password' });
                    const confirmation = input('تأیید مجدد: CONFIRM', 'confirmation', { required: true });
                    form.append(reason.wrapper, password.wrapper, confirmation.wrapper,
                        element('button', { text: 'تأیید بازپرداخت', type: 'submit', className: 'kp-btn kp-btn-danger' }));
                    actions.append(form);
                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();
                        const data = Object.fromEntries(new FormData(form).entries());
                        data.idempotency_key = `admin-refund-${payment.id}-${crypto.randomUUID()}`;
                        try {
                            await request(`admin/console/payments/${payment.id}/refund`, { method: 'POST', body: data });
                            await loadSection();
                            setNotice('بازپرداخت به کیف پول ثبت شد.');
                        } catch (error) { setNotice(error.message, true); }
                    });
                });
                actions.append(button);
            });
            renderPager(pageData, loadSection);
        }
    }

    async function loadAccess() {
        const [roles, policies] = await Promise.all([
            request('admin/console/access/roles'),
            request(`admin/console/access/policies?page=${currentPage}`),
        ]);
        content.append(element('h2', { text: 'مجوزهای نقش‌ها' }));
        const roleBody = renderTable(roles.data.roles.map(({ id, name, display_name, permissions }) =>
            ({ id, name, display_name, permissions: permissions.join(', ') })));
        if (roleBody) [...roleBody.rows].forEach((row, index) => {
            const role = roles.data.roles[index];
            const actions = row.querySelector('[data-actions-for]');
            if (role.name === 'super_admin') return;
            const form = element('form', { className: 'kp-admin-inline-form' });
            const displayName = input('نام نمایشی نقش', 'display_name', { value: role.display_name });
            const grants = input('مجوزها با ویرگول جدا شوند', 'permissions', {
                value: (role.permissions || []).filter((permission) => permission.startsWith('admin.')).join(','),
            });
            const reason = input('دلیل تغییر', 'reason', { required: true, kind: 'textarea' });
            const password = input('رمز عبور فعلی (یا ورود مجدد OTP در ۵ دقیقه اخیر)', 'password', { kind: 'password' });
            const confirmation = input('تأیید مجدد: CONFIRM', 'confirmation', { required: true });
            form.append(displayName.wrapper, grants.wrapper, reason.wrapper, password.wrapper, confirmation.wrapper,
                element('button', { text: 'ذخیره مجوزها', type: 'submit', className: 'kp-btn kp-btn-primary' }));
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const values = new FormData(form);
                try {
                    await request(`admin/console/access/roles/${role.id}`, {
                        method: 'PATCH', body: {
                            display_name: values.get('display_name'),
                            permissions: values.get('permissions').split(',').map((value) => value.trim()).filter(Boolean),
                            reason: values.get('reason'), password: values.get('password'), confirmation: values.get('confirmation'),
                        },
                    });
                    await loadSection();
                    setNotice('مجوزهای نقش ذخیره شد.');
                } catch (error) { setNotice(error.message, true); }
            });
            actions.append(form);
        });
        const createRole = element('form', { className: 'kp-admin-editor' });
        createRole.append(element('h3', { text: 'ایجاد نقش عملیاتی' }));
        const roleName = input('شناسه لاتین نقش', 'name', { required: true, placeholder: 'content_reviewer' });
        const roleTitle = input('نام نمایشی', 'display_name', { required: true });
        const description = input('شرح نقش', 'description');
        const reason = input('دلیل ایجاد نقش', 'reason', { required: true, kind: 'textarea' });
        const password = input('رمز عبور فعلی (یا ورود مجدد OTP در ۵ دقیقه اخیر)', 'password', { kind: 'password' });
        const confirmation = input('تأیید مجدد: CONFIRM', 'confirmation', { required: true });
        createRole.append(roleName.wrapper, roleTitle.wrapper, description.wrapper, reason.wrapper,
            password.wrapper, confirmation.wrapper,
            element('button', { text: 'ایجاد نقش', type: 'submit', className: 'kp-btn kp-btn-primary' }));
        createRole.addEventListener('submit', async (event) => {
            event.preventDefault();
            try {
                await request('admin/console/access/roles', {
                    method: 'POST', body: Object.fromEntries(new FormData(createRole).entries()),
                });
                await loadSection();
                setNotice('نقش جدید ساخته شد. مجوزهای آن تا زمان تخصیص خالی هستند.');
            } catch (error) { setNotice(error.message, true); }
        });
        content.append(createRole);
        content.append(element('h2', { text: 'سیاست‌های امنیتی' }));
        const policyBody = renderTable(policies.data.data);
        if (policyBody) [...policyBody.rows].forEach((row, index) => {
            const policy = policies.data.data[index];
            const actions = row.querySelector('[data-actions-for]');
            const value = typeof policy.policy_value === 'string' ? JSON.parse(policy.policy_value) : policy.policy_value;
            actions.append(appendJsonEditor({ id: policy.policy_key, policy_value: value }, async (body) =>
                request(`admin/console/access/policies/${encodeURIComponent(policy.policy_key)}`, {
                    method: 'PUT',
                    body: {
                        value: body.policy_value,
                        reason: body.reason,
                        password: body.password,
                        confirmation: body.confirmation,
                    },
                }), { sensitive: true }));
        });
        if (!policies.data.data.some((policy) => policy.policy_key === 'admin.location_retention_hours')) {
            content.append(appendJsonEditor({
                id: 'admin.location_retention_hours',
                policy_value: { hours: 8 },
            }, (body) => request('admin/console/access/policies/admin.location_retention_hours', {
                method: 'PUT',
                body: {
                    value: body.policy_value,
                    reason: body.reason,
                    password: body.password,
                    confirmation: body.confirmation,
                },
            }), { sensitive: true }));
        }
        renderPager(policies.data, loadSection);
    }

    async function loadAreas() {
        await loadResource('areas');
        content.append(element('h2', { text: 'قواعد قیمت‌گذاری و پوشش' }));
        await loadResource('pricing');
        content.append(element('h2', { text: 'نسخه‌های تعرفه (تغییرات با ایجاد نسخه جدید ثبت می‌شوند)' }));
        const tariffs = await request('admin/pricing/tariffs');
        renderTable(tariffs.data || []);
        const form = element('form', { className: 'kp-admin-editor' });
        form.append(element('h3', { text: 'ایجاد نسخه تعرفه' }));
        [
            ['شناسه خدمت', 'service_id', 'number'], ['شناسه محدوده', 'service_area_id', 'number'],
            ['هزینه پایه', 'base_fare', 'number'], ['هزینه اعزام', 'dispatch_fee', 'number'],
            ['هزینه هر کیلومتر', 'per_km_rate', 'number'], ['هزینه هر دقیقه', 'per_minute_rate', 'number'],
            ['حداقل مبلغ', 'minimum_total', 'number'], ['حداقل مسافت', 'minimum_trip_km', 'number'],
            ['حاشیه تخمین درصدی', 'estimate_margin_percent', 'number'], ['ضریب شب', 'night_multiplier', 'number'],
            ['شروع نرخ شب', 'night_start', 'time'], ['پایان نرخ شب', 'night_end', 'time'],
            ['شروع اعتبار', 'effective_from', 'date'], ['پایان اعتبار اختیاری', 'effective_until', 'date'],
        ].forEach(([label, name, kind]) => {
            const field = input(label, name, { required: name !== 'effective_until', kind });
            form.append(field.wrapper);
        });
        form.append(element('p', {
            className: 'kp-admin-muted',
            text: 'مبالغ تعرفه در واحد ریال هستند. نسخه‌های قبلی قفل می‌شوند و برای اصلاح، نسخهٔ جدید بسازید.',
        }));
        form.append(element('button', { text: 'ایجاد تعرفه', type: 'submit', className: 'kp-btn kp-btn-primary' }));
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            try {
                await request('admin/pricing/tariffs', {
                    method: 'POST', body: Object.fromEntries(new FormData(form).entries()),
                });
                await loadSection();
                setNotice('نسخه تعرفه ساخته شد.');
            } catch (error) { setNotice(error.message, true); }
        });
        content.append(form);
    }

    async function loadSection() {
        if (controller.signal.aborted) return;
        activeMap?.remove();
        activeMap = undefined;
        content.replaceChildren(element('h2', { text: SECTIONS.find(([key]) => key === activeSection)?.[1] || 'مدیریت' }));
        try {
            switch (activeSection) {
                case 'dashboard': await loadDashboard(); break;
                case 'users': await loadUsers(); break;
                case 'technicians': await loadTechnicians(); break;
                case 'bookings': await loadBookings(); break;
                case 'map': {
                    const response = await request('admin/console/operations-map');
                    content.append(element('p', { className: 'kp-admin-muted', text: 'فقط مأموریت‌های تخصیص‌یافته و در حال اجرا با تخصیص فعال حداکثر ۸ ساعت اخیر نمایش داده می‌شوند.' }));
                    const map = element('div', { className: 'kp-admin-map', attributes: { role: 'region', 'aria-label': 'نقشه سفارش‌های فعال مجاز' } });
                    content.append(map);
                    renderTable(response.data);
                    activeMap = L.map(map, { scrollWheelZoom: false }).setView([35.6892, 51.389], 11);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19,
                        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer">OpenStreetMap</a> contributors',
                    }).addTo(activeMap);
                    const markers = response.data
                        .map((booking) => ({
                            booking,
                            latitude: Number(booking.latitude),
                            longitude: Number(booking.longitude),
                        }))
                        .filter(({ latitude, longitude }) =>
                            Number.isFinite(latitude) && Number.isFinite(longitude)
                            && latitude >= -90 && latitude <= 90 && longitude >= -180 && longitude <= 180);
                    markers.forEach(({ booking, latitude, longitude }) => {
                        const marker = L.marker([latitude, longitude]).addTo(activeMap);
                        const popup = element('div');
                        popup.append(
                            element('strong', { text: booking.booking_code }),
                            element('p', { text: booking.street_address }),
                            element('span', { text: booking.status }),
                        );
                        marker.bindPopup(popup);
                    });
                    if (markers.length) {
                        activeMap.fitBounds(markers.map(({ latitude, longitude }) => [latitude, longitude]), { padding: [24, 24] });
                    }
                    window.setTimeout(() => activeMap?.invalidateSize(), 0);
                    break;
                }
                case 'catalog': await loadResource('categories'); await loadResource('services'); break;
                case 'areas': await loadAreas(); break;
                case 'finance': await loadFinance(); break;
                case 'tickets': await loadTickets(); break;
                case 'notifications': await loadNotifications(); break;
                case 'content': await loadContent(); break;
                case 'settings':
                    await loadResource('settings', { sensitive: true });
                    content.append(element('h2', { text: 'اتصال سرویس اعلان' }));
                    content.append(element('pre', { className: 'kp-admin-json', text: JSON.stringify((await request('admin/notifications/services')).data, null, 2) }));
                    content.append(element('h2', { text: 'آمادگی درگاه پرداخت' }));
                    content.append(element('pre', { className: 'kp-admin-json', text: JSON.stringify((await request('finance/gateway')).data, null, 2) }));
                    break;
                case 'audit': {
                    searchForm(loadSection);
                    const response = await request(`admin/console/audit-logs?page=${currentPage}&q=${encodeURIComponent(searchTerm)}`);
                    renderTable(response.data.data);
                    renderPager(response.data, loadSection);
                    break;
                }
                case 'reports': {
                    const response = await request('admin/console/reports');
                    content.append(element('pre', { className: 'kp-admin-json', text: JSON.stringify(response.data, null, 2) }));
                    const download = element('button', { text: 'دریافت خروجی CSV', type: 'button', className: 'kp-btn kp-btn-secondary' });
                    download.addEventListener('click', async () => {
                        try {
                            const { data } = await request('admin/console/reports/export');
                            const columns = ['booking_code', 'status', 'estimated_price', 'final_price', 'created_at'];
                            const csv = [columns.join(','), ...data.map((row) => columns.map((column) =>
                                `"${String(row[column] ?? '').replaceAll('"', '""')}"`).join(','))].join('\r\n');
                            const url = URL.createObjectURL(new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8' }));
                            const link = element('a', { attributes: { href: url, download: 'keypro-bookings-report.csv' } });
                            link.click();
                            URL.revokeObjectURL(url);
                        } catch (error) { setNotice(error.message, true); }
                    });
                    content.append(download);
                    break;
                }
                case 'access': await loadAccess(); break;
                default: content.append(element('p', { text: 'بخش انتخاب‌شده معتبر نیست.' }));
            }
        } catch (error) {
            if (error.name !== 'AbortError') setNotice(error.message || 'بارگذاری داده‌ها ناموفق بود.', true);
        }
    }

    renderNav();
    loadSection();
    return () => {
        controller.abort();
        activeMap?.remove();
    };
}
