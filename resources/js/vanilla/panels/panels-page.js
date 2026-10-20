import { apiClient } from '../api/client.js';

const roles = [
    { key: 'customer', label: '👤 پنل مشتری', color: 'cyan' },
    { key: 'technician', label: '🔧 پنل متخصص', color: 'gold' },
    { key: 'operator', label: '📡 دیسپچ و اپراتوری', color: 'cyan' },
    { key: 'accountant', label: '💰 دفتر کل مالی', color: 'emerald' },
];

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

function createCard(className = '') {
    return createElement('section', {
        className: `kp-card${className ? ` ${className}` : ''}`,
    });
}

function appendParagraph(parent, text, className = '') {
    parent.append(createElement('p', { className, text }));
}

function createTable(headers, rows, emptyMessage = 'موردی یافت نشد.') {
    const wrapper = createElement('div', { className: 'kp-table-wrapper' });
    if (rows.length === 0) {
        wrapper.append(createElement('div', {
            className: 'kp-vanilla-table-empty',
            text: emptyMessage,
            attributes: { role: 'status' },
        }));
        return wrapper;
    }

    const table = createElement('table', { className: 'kp-table' });
    const head = document.createElement('thead');
    const headingRow = document.createElement('tr');
    headers.forEach((header) => headingRow.append(createElement('th', {
        text: header,
        attributes: { scope: 'col' },
    })));
    head.append(headingRow);

    const body = document.createElement('tbody');
    rows.forEach((row) => {
        const tableRow = document.createElement('tr');
        row.forEach((cell) => tableRow.append(createElement('td', { text: cell })));
        body.append(tableRow);
    });

    table.append(head, body);
    wrapper.append(table);
    return wrapper;
}

function renderCustomerPanel() {
    const panel = createElement('div', { className: 'kp-vanilla-panel-content' });
    const activeOrder = createCard('kp-vanilla-active-order');
    const orderDetails = document.createElement('div');
    orderDetails.append(
        createElement('h2', {
            className: 'kp-vanilla-panel-accent-cyan',
            text: 'سفارش فعال: بازگشایی درب ضدسرقت',
        }),
        createElement('p', {
            className: 'kp-vanilla-panel-muted',
            text: 'کد پیگیری: #KP-88219 • مبدأ: تهران، سعادت‌آباد',
        }),
    );
    activeOrder.append(
        orderDetails,
        createElement('span', {
            className: 'kp-vanilla-order-status',
            text: 'در حال اعزام متخصص',
        }),
    );

    const informationGrid = createElement('div', { className: 'kp-vanilla-panel-grid' });
    const technician = createCard();
    technician.append(createElement('h3', { text: 'مشخصات کلیدساز اعزامی' }));
    appendParagraph(technician, 'نام: علیرضا محمدی (کد نظام صنفی: ۱۲۸۹)');
    appendParagraph(technician, 'وسیله نقلیه: موتور هوندا (پلاک ایران ۲۲)');
    appendParagraph(technician, 'امتیاز رضایت: ۴.۹ از ۵ (۱۲۰ خدمت موفق)', 'kp-vanilla-panel-accent-gold');

    const invoice = createCard();
    invoice.append(createElement('h3', { text: 'فاکتور و برآورد قیمت' }));
    appendParagraph(invoice, 'اجرت پایه باز کردن قفل: ۴۵۰,۰۰۰ تومان');
    appendParagraph(invoice, 'ایاب و ذهاب استاندارد: ۸۰,۰۰۰ تومان');
    appendParagraph(invoice, 'مجموع قابل پرداخت: ۵۳۰,۰۰۰ تومان', 'kp-vanilla-panel-total');
    informationGrid.append(technician, invoice);
    panel.append(activeOrder, informationGrid);
    return panel;
}

function renderTechnicianPanel() {
    const panel = createElement('div', { className: 'kp-vanilla-panel-content' });
    const status = createElement('p', {
        className: 'kp-vanilla-panel-muted',
        text: 'در حال دریافت اطلاعات متخصص از سرور…',
        attributes: { role: 'status', 'aria-live': 'polite' },
    });
    panel.append(status);

    const showError = (error) => {
        panel.replaceChildren();
        panel.append(createElement('p', {
            className: 'kp-error-text',
            text: error?.message || 'دریافت اطلاعات پنل متخصص ناموفق بود.',
            attributes: { role: 'alert' },
        }));
        panel.append(createElement('button', {
            className: 'kp-btn kp-btn-secondary',
            text: 'تلاش دوباره',
            attributes: { type: 'button' },
        }));
        panel.querySelector('button').addEventListener('click', loadDashboard);
    };

    async function loadDashboard() {
        panel.replaceChildren(status);
        try {
            const result = await apiClient.request('/technician/dashboard');
            renderDashboard(result.data);
        } catch (error) {
            if (error?.status === 403 && error?.code === 'FORBIDDEN') {
                renderRegistration();
                return;
            }
            showError(error);
        }
    }

    function renderRegistration() {
        panel.replaceChildren();
        const card = createCard();
        card.append(
            createElement('h2', { text: 'ثبت‌نام متخصص' }),
            createElement('p', {
                text: 'برای تکمیل پروفایل و ارسال مدارک، ابتدا حساب خود را به پروفایل متخصص تبدیل کنید.',
            }),
        );
        const register = createElement('button', {
            className: 'kp-btn kp-btn-primary',
            text: 'شروع ثبت‌نام متخصص',
            attributes: { type: 'button' },
        });
        const feedback = createElement('p', { attributes: { role: 'status', 'aria-live': 'polite' } });
        register.addEventListener('click', async () => {
            register.disabled = true;
            try {
                await apiClient.request('/technician/register', { method: 'POST', body: {} });
                await loadDashboard();
            } catch (error) {
                feedback.textContent = error.message;
                feedback.setAttribute('role', 'alert');
                register.disabled = false;
            }
        });
        card.append(register, feedback);
        panel.append(card);
    }

    function renderDashboard(data) {
        panel.replaceChildren();
        const profile = data.profile;
        const summary = createCard('kp-vanilla-active-order');
        summary.append(
            createElement('h2', { text: `وضعیت پروفایل: ${profile.status}` }),
            createElement('p', {
                text: profile.status === 'verified'
                    ? 'مدارک شما تأیید شده‌اند؛ پس از تکمیل خدمات، مناطق و ساعت کاری می‌توانید آنلاین شوید.'
                    : 'تا تأیید مدارک الزامی امکان دریافت مأموریت وجود ندارد.',
                className: 'kp-vanilla-panel-muted',
            }),
        );
        panel.append(summary);

        const profileCard = createCard();
        profileCard.append(createElement('h2', { text: 'پروفایل و محدودهٔ کاری' }));
        const profileForm = createElement('form', { className: 'kp-vanilla-technician-form' });
        const name = createElement('input', {
            className: 'kp-input',
            attributes: { name: 'name', value: profile.name || '', required: true, maxlength: 120 },
        });
        const license = createElement('input', {
            className: 'kp-input',
            attributes: { name: 'license_number', value: profile.license_number || '', maxlength: 50 },
        });
        const guild = createElement('input', {
            className: 'kp-input',
            attributes: { name: 'guild_registration_no', value: profile.guild_registration_no || '', maxlength: 50 },
        });
        const vehicle = document.createElement('select');
        vehicle.className = 'kp-input';
        vehicle.name = 'vehicle_type';
        [['', 'انتخاب وسیله'], ['motor', 'موتور'], ['car', 'خودرو'], ['van', 'ون'], ['other', 'سایر']]
            .forEach(([value, label]) => {
                const option = createElement('option', { text: label, attributes: { value } });
                option.selected = value === (profile.vehicle_type || '');
                vehicle.append(option);
            });
        const plate = createElement('input', {
            className: 'kp-input',
            attributes: { name: 'vehicle_plate', value: profile.vehicle_plate || '', maxlength: 30 },
        });
        const field = (label, input) => {
            const wrapper = createElement('label', { className: 'kp-input-group' });
            wrapper.append(createElement('span', { className: 'kp-label', text: label }), input);
            return wrapper;
        };
        profileForm.append(
            field('نام کامل', name),
            field('شمارهٔ مجوز', license),
            field('شمارهٔ عضویت صنفی', guild),
            field('وسیلهٔ نقلیه', vehicle),
            field('پلاک وسیله (اختیاری)', plate),
        );

        const servicesField = createElement('fieldset', { className: 'kp-input-group' });
        servicesField.append(createElement('legend', { className: 'kp-label', text: 'خدمات قابل ارائه' }));
        data.services.forEach((service) => {
            const label = createElement('label', { className: 'kp-checkbox-label' });
            const checkbox = createElement('input', {
                attributes: {
                    type: 'checkbox',
                    name: 'service_ids',
                    value: service.id,
                    checked: profile.service_ids.map(Number).includes(Number(service.id)),
                },
            });
            label.append(checkbox, document.createTextNode(` ${service.title_fa}`));
            servicesField.append(label);
        });
        const areasField = createElement('fieldset', { className: 'kp-input-group' });
        areasField.append(createElement('legend', { className: 'kp-label', text: 'مناطق تحت پوشش' }));
        data.service_areas.forEach((area) => {
            const label = createElement('label', { className: 'kp-checkbox-label' });
            const checkbox = createElement('input', {
                attributes: {
                    type: 'checkbox',
                    name: 'service_area_ids',
                    value: area.id,
                    checked: profile.service_area_ids.map(Number).includes(Number(area.id)),
                },
            });
            label.append(checkbox, document.createTextNode(` ${area.city_name} - ${area.zone_name}`));
            areasField.append(label);
        });
        const hoursCard = createElement('fieldset', { className: 'kp-input-group' });
        hoursCard.append(createElement('legend', { className: 'kp-label', text: 'ساعت کاری' }));
        const day = document.createElement('select');
        day.className = 'kp-input';
        day.name = 'day_of_week';
        ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'].forEach((label, index) => {
            const option = createElement('option', { text: label, attributes: { value: index } });
            option.selected = Number(profile.working_hours[0]?.day_of_week ?? 0) === index;
            day.append(option);
        });
        const starts = createElement('input', {
            className: 'kp-input',
            attributes: { type: 'time', name: 'starts_at', value: profile.working_hours[0]?.starts_at?.slice(0, 5) || '08:00', required: true },
        });
        const ends = createElement('input', {
            className: 'kp-input',
            attributes: { type: 'time', name: 'ends_at', value: profile.working_hours[0]?.ends_at?.slice(0, 5) || '18:00', required: true },
        });
        const capacity = createElement('input', {
            className: 'kp-input',
            attributes: { type: 'number', min: 1, max: 10, name: 'capacity', value: profile.working_hours[0]?.capacity || 1, required: true },
        });
        hoursCard.append(day, starts, ends, capacity);
        const profileFeedback = createElement('p', { attributes: { role: 'status', 'aria-live': 'polite' } });
        const saveProfile = createElement('button', {
            className: 'kp-btn kp-btn-primary',
            text: 'ذخیرهٔ پروفایل',
            attributes: { type: 'submit' },
        });
        profileForm.append(servicesField, areasField, hoursCard, saveProfile, profileFeedback);
        profileForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            saveProfile.disabled = true;
            profileFeedback.textContent = '';
            try {
                await apiClient.request('/technician/profile', {
                    method: 'PUT',
                    body: {
                        name: name.value.trim(),
                        license_number: license.value.trim() || null,
                        guild_registration_no: guild.value.trim() || null,
                        vehicle_type: vehicle.value || null,
                        vehicle_plate: plate.value.trim() || null,
                        service_ids: [...profileForm.querySelectorAll('[name="service_ids"]:checked')].map((input) => Number(input.value)),
                        service_area_ids: [...profileForm.querySelectorAll('[name="service_area_ids"]:checked')].map((input) => Number(input.value)),
                        working_hours: [{
                            day_of_week: Number(day.value),
                            starts_at: starts.value,
                            ends_at: ends.value,
                            capacity: Number(capacity.value),
                            is_active: true,
                        }],
                    },
                });
                profileFeedback.textContent = 'پروفایل ذخیره شد.';
                profileFeedback.setAttribute('role', 'status');
            } catch (error) {
                profileFeedback.textContent = error.message;
                profileFeedback.setAttribute('role', 'alert');
            } finally {
                saveProfile.disabled = false;
            }
        });
        profileCard.append(profileForm);

        const documentCard = createCard();
        documentCard.append(createElement('h2', { text: 'مدارک احراز هویت' }));
        documentCard.append(createTable(
            ['نوع مدرک', 'وضعیت', 'نتیجهٔ بررسی'],
            profile.documents.map((document) => [
                document.document_type,
                document.status,
                document.rejection_reason || '—',
            ]),
        ));
        const documentForm = createElement('form', { className: 'kp-vanilla-technician-form' });
        const documentType = document.createElement('select');
        documentType.className = 'kp-input';
        documentType.name = 'document_type';
        [['national_id', 'کارت ملی'], ['guild_license', 'مجوز صنفی'], ['criminal_record', 'گواهی سوءپیشینه'], ['vehicle_card', 'مدرک خودرو']]
            .forEach(([value, label]) => documentType.append(createElement('option', { text: label, attributes: { value } })));
        const documentFile = createElement('input', {
            className: 'kp-input',
            attributes: { type: 'file', name: 'document', accept: '.jpg,.jpeg,.png,.pdf', required: true },
        });
        const documentFeedback = createElement('p', { attributes: { role: 'status', 'aria-live': 'polite' } });
        const uploadDocument = createElement('button', {
            className: 'kp-btn kp-btn-secondary',
            text: 'بارگذاری مدرک',
            attributes: { type: 'submit' },
        });
        documentForm.append(field('نوع مدرک', documentType), field('فایل (حداکثر ۵ مگابایت)', documentFile), uploadDocument, documentFeedback);
        documentForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const file = documentFile.files[0];
            if (!file) return;
            const body = new FormData();
            body.append('document_type', documentType.value);
            body.append('document', file);
            uploadDocument.disabled = true;
            try {
                await apiClient.request('/technician/documents', { method: 'POST', body });
                documentFeedback.textContent = 'مدرک برای بررسی ارسال شد.';
                documentFeedback.setAttribute('role', 'status');
                await loadDashboard();
            } catch (error) {
                documentFeedback.textContent = error.message;
                documentFeedback.setAttribute('role', 'alert');
                uploadDocument.disabled = false;
            }
        });
        documentCard.append(documentForm);

        const availabilityCard = createCard();
        availabilityCard.append(createElement('h2', { text: 'وضعیت دسترسی به مأموریت' }));
        const availabilityFeedback = createElement('p', { attributes: { role: 'status', 'aria-live': 'polite' } });
        const availabilityButton = createElement('button', {
            className: `kp-btn ${profile.online ? 'kp-btn-danger' : 'kp-btn-primary'}`,
            text: profile.online ? 'خروج از شیفت' : 'آنلاین‌شدن',
            attributes: { type: 'button', disabled: profile.status !== 'verified' },
        });
        availabilityButton.addEventListener('click', async () => {
            const online = !profile.online;
            const position = online ? await getTechnicianPosition(availabilityFeedback) : null;
            if (online && !position) return;
            availabilityButton.disabled = true;
            try {
                await apiClient.request('/technician/availability', {
                    method: 'PUT',
                    body: online ? { online, ...position } : { online },
                });
                await loadDashboard();
            } catch (error) {
                availabilityFeedback.textContent = error.message;
                availabilityFeedback.setAttribute('role', 'alert');
                availabilityButton.disabled = false;
            }
        });
        availabilityCard.append(availabilityButton, availabilityFeedback);

        panel.append(profileCard, documentCard, availabilityCard);
        loadOperations();

        async function loadOperations() {
            const [offersResult, missionsResult, earningsResult] = await Promise.allSettled([
                apiClient.request('/technician/offers'),
                apiClient.request('/technician/missions'),
                apiClient.request('/technician/earnings'),
            ]);
            const operationCard = createCard();
            operationCard.append(createElement('h2', { text: 'پیشنهادها و مأموریت‌ها' }));
            if (offersResult.status === 'fulfilled') {
                offersResult.value.data.forEach((offer) => {
                    const offerCard = createCard();
                    offerCard.append(
                        createElement('h3', { text: offer.service_name }),
                        createElement('p', { text: `فاصله: ${offer.distance_km} کیلومتر · دریافتی: ${Number(offer.offered_earnings).toLocaleString('fa-IR')} ریال` }),
                        createElement('p', { text: `مهلت پاسخ: ${new Date(offer.expires_at).toLocaleString('fa-IR')}` }),
                    );
                    ['accepted', 'rejected'].forEach((decision) => {
                        const button = createElement('button', {
                            className: `kp-btn ${decision === 'accepted' ? 'kp-btn-primary' : 'kp-btn-secondary'}`,
                            text: decision === 'accepted' ? 'پذیرش مأموریت' : 'رد پیشنهاد',
                            attributes: { type: 'button' },
                        });
                        button.addEventListener('click', async () => {
                            button.disabled = true;
                            try {
                                await apiClient.request(`/technician/offers/${offer.id}/respond`, {
                                    method: 'POST',
                                    body: { decision },
                                });
                                await loadDashboard();
                            } catch (error) {
                                availabilityFeedback.textContent = error.message;
                                availabilityFeedback.setAttribute('role', 'alert');
                                button.disabled = false;
                            }
                        });
                        offerCard.append(button);
                    });
                    operationCard.append(offerCard);
                });
            } else {
                operationCard.append(createElement('p', {
                    text: offersResult.reason.message,
                    attributes: { role: 'alert' },
                }));
            }
            if (missionsResult.status === 'fulfilled') {
                missionsResult.value.data.forEach((mission) => {
                    const missionCard = createCard();
                    missionCard.append(
                        createElement('h3', { text: mission.service_name }),
                        createElement('p', { text: `درخواست ${mission.booking_code} · وضعیت ${mission.status}` }),
                    );
                    const details = createElement('button', {
                        className: 'kp-btn kp-btn-secondary',
                        text: 'مشاهدهٔ جزئیات و نشانی',
                        attributes: { type: 'button' },
                    });
                    details.addEventListener('click', async () => {
                        try {
                            const result = await apiClient.request(`/technician/missions/${encodeURIComponent(mission.booking_code)}`);
                            renderMissionDetails(missionCard, result.data);
                        } catch (error) {
                            missionCard.append(createElement('p', { text: error.message, attributes: { role: 'alert' } }));
                        }
                    });
                    missionCard.append(details);
                    operationCard.append(missionCard);
                });
            } else {
                operationCard.append(createElement('p', {
                    text: `دریافت مأموریت‌ها ناموفق بود: ${missionsResult.reason.message}`,
                    attributes: { role: 'alert' },
                }));
            }
            if (earningsResult.status === 'fulfilled') {
                const earnings = earningsResult.value.data;
                const earningsCard = createCard();
                earningsCard.append(
                    createElement('h3', { text: 'درآمد و تسویه' }),
                    createElement('p', { text: `درآمد ناخالص: ${Number(earnings.gross).toLocaleString('fa-IR')} ریال` }),
                    createElement('p', { text: `کارمزد: ${Number(earnings.platform_commission).toLocaleString('fa-IR')} ریال` }),
                    createElement('p', { text: `درآمد خالص ثبت‌شده: ${Number(earnings.net_earnings).toLocaleString('fa-IR')} ریال` }),
                    createElement('p', { text: `سهم پرداخت‌شده: ${Number(earnings.paid_earnings_minor ?? 0).toLocaleString('fa-IR')} ریال` }),
                    createElement('p', { text: `مانده قابل برداشت: ${Number(earnings.available_balance_minor ?? 0).toLocaleString('fa-IR')} ریال` }),
                    createElement('p', { text: `حداقل تسویه: ${Number(earnings.minimum_settlement_minor ?? 0).toLocaleString('fa-IR')} ریال` }),
                );
                earningsCard.append(createTable(
                    ['شناسه تسویه', 'مبلغ', 'وضعیت'],
                    earnings.settlements.map((item) => [
                        item.settlement_uuid,
                        `${Number(item.amount_minor ?? item.amount).toLocaleString('fa-IR')} ریال`,
                        item.status,
                    ]),
                ));
                operationCard.append(earningsCard);
            } else {
                operationCard.append(createElement('p', {
                    text: `دریافت اطلاعات درآمد ناموفق بود: ${earningsResult.reason.message}`,
                    attributes: { role: 'alert' },
                }));
            }
            panel.append(operationCard);
        }

        function renderMissionDetails(container, mission) {
            container.replaceChildren(
                createElement('h3', { text: mission.service_name }),
                createElement('p', { text: `نشانی: ${mission.street_address}` }),
                createElement('p', { text: `مختصات: ${mission.latitude}, ${mission.longitude}`, attributes: { dir: 'ltr' } }),
                createElement('p', { text: `وضعیت: ${mission.status} · مهلت دسترسی: ${new Date(mission.location_access_expires_at).toLocaleString('fa-IR')}` }),
                createElement('p', { text: mission.problem_description || 'شرحی برای مشکل ثبت نشده است.' }),
            );
            const nextStatus = {
                assigned: 'en_route',
                en_route: 'arrived',
                arrived: 'in_progress',
                in_progress: 'payment_pending',
            }[mission.status];
            if (nextStatus) {
                const update = createElement('button', {
                    className: 'kp-btn kp-btn-primary',
                    text: {
                        en_route: 'شروع حرکت',
                        arrived: 'ثبت رسیدن',
                        in_progress: 'شروع کار',
                        payment_pending: 'ثبت پایان کار',
                    }[nextStatus],
                    attributes: { type: 'button' },
                });
                update.addEventListener('click', async () => {
                    try {
                        await apiClient.request(`/technician/missions/${encodeURIComponent(mission.booking_code)}/status`, {
                            method: 'POST',
                            body: { status: nextStatus },
                        });
                        await loadDashboard();
                    } catch (error) {
                        container.append(createElement('p', { text: error.message, attributes: { role: 'alert' } }));
                    }
                });
                container.append(update);
            }
            if (mission.requires_completion_evidence) {
                const evidence = createElement('input', {
                    className: 'kp-input',
                    attributes: { type: 'file', accept: '.jpg,.jpeg,.png,.pdf', 'aria-label': 'مدرک انجام کار' },
                });
                const upload = createElement('button', {
                    className: 'kp-btn kp-btn-secondary',
                    text: 'ارسال مدرک انجام کار',
                    attributes: { type: 'button' },
                });
                upload.addEventListener('click', async () => {
                    if (!evidence.files[0]) return;
                    const body = new FormData();
                    body.append('evidence', evidence.files[0]);
                    try {
                        await apiClient.request(`/technician/missions/${encodeURIComponent(mission.booking_code)}/evidence`, {
                            method: 'POST',
                            body,
                        });
                        upload.textContent = 'مدرک ارسال شد';
                        upload.disabled = true;
                    } catch (error) {
                        container.append(createElement('p', { text: error.message, attributes: { role: 'alert' } }));
                    }
                });
                container.append(evidence, upload);
            }
            if (
                mission.requires_ownership_check &&
                mission.status === 'arrived' &&
                mission.ownership_status === 'customer_attested'
            ) {
                const authority = createElement('button', {
                    className: 'kp-btn kp-btn-secondary',
                    text: 'ثبت بررسی حضوری اختیار مشتری',
                    attributes: { type: 'button' },
                });
                authority.addEventListener('click', async () => {
                    if (!window.confirm('آیا مدارک و اختیار مشتری را حضوری بررسی و تأیید کرده‌اید؟')) return;
                    authority.disabled = true;
                    try {
                        await apiClient.request(`/bookings/${mission.id}/customer-authority/verify`, {
                            method: 'POST',
                            body: { verified: true },
                        });
                        await loadDashboard();
                    } catch (error) {
                        container.append(createElement('p', { text: error.message, attributes: { role: 'alert' } }));
                        authority.disabled = false;
                    }
                });
                container.append(authority);
            }
        }
    }

    loadDashboard();
    return panel;
}

async function getTechnicianPosition(feedback) {
    if (!globalThis.isSecureContext || !navigator.geolocation) {
        feedback.textContent = 'برای آنلاین‌شدن، موقعیت‌یاب در محیط امن و پشتیبانی‌شدهٔ مرورگر لازم است.';
        feedback.setAttribute('role', 'alert');
        return null;
    }
    feedback.textContent = 'موقعیت فقط پس از اجازهٔ شما دریافت می‌شود.';
    try {
        const position = await new Promise((resolve, reject) => navigator.geolocation.getCurrentPosition(resolve, reject, {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 30000,
        }));
        return {
            latitude: Number(position.coords.latitude.toFixed(7)),
            longitude: Number(position.coords.longitude.toFixed(7)),
        };
    } catch (error) {
        feedback.textContent = error.code === 1
            ? 'اجازهٔ موقعیت‌یابی داده نشد؛ برای آنلاین‌شدن باید مجوز بدهید.'
            : 'موقعیت فعلی دریافت نشد؛ مجوزها و اتصال GPS یا مکان‌یابی مرورگر را بررسی کنید.';
        feedback.setAttribute('role', 'alert');
        return null;
    }
}

function renderOperatorPanel(currentPage, onPageChange) {
    const panel = createElement('div', { className: 'kp-vanilla-panel-content' });
    const metrics = createElement('div', { className: 'kp-vanilla-panel-grid kp-vanilla-metric-grid' });
    [
        ['درخواست‌های در انتظار دیسپچ', '۱۴', 'cyan'],
        ['متخصصان فعال در شیفت', '۳۸', 'gold'],
        ['ماموریت‌های انجام شده امروز', '۱۸۴', 'emerald'],
    ].forEach(([label, value, color]) => {
        const metric = createCard(`kp-vanilla-metric-${color}`);
        metric.append(
            createElement('span', { className: 'kp-vanilla-panel-muted', text: label }),
            createElement('p', { className: `kp-vanilla-metric-value kp-vanilla-panel-accent-${color}`, text: value }),
        );
        metrics.append(metric);
    });

    const dispatch = createCard();
    dispatch.append(createElement('h2', {
        className: 'kp-vanilla-panel-section-title',
        text: 'مانیتورینگ زنده صف اعزام و صلاحیت‌ها',
    }));
    dispatch.append(createTable(
        ['شناسه', 'مشتری', 'منطقه شهری', 'وضعیت صلاحیت', 'تکنسین انتخابی', 'اقدام اپراتور'],
        [
            ['#1082', 'سارا رستمی', 'منطقه ۲ (گیشا)', 'احراز سند مالکیت تایید شد', 'رضا کاظمی', 'تایید اعزام'],
            ['#1083', 'حسین علوی', 'منطقه ۶ (یوسف‌آباد)', 'در حال بررسی کارت ماشین', 'در انتظار تطبیق', 'تماس با مشتری'],
        ],
    ));
    dispatch.append(createPagination(currentPage, 5, onPageChange));
    panel.append(metrics, dispatch);
    return panel;
}

function renderAccountantPanel() {
    const panel = createElement('div', { className: 'kp-vanilla-panel-content' });
    const metrics = createElement('div', { className: 'kp-vanilla-panel-grid kp-vanilla-metric-grid' });
    [
        ['موجودی دفتر کل (Ledger Assets)', '۸۴,۲۰۰,۰۰۰ تومان', 'emerald'],
        ['بستانکاری متخصصان جهت تسویه پایا', '۵۸,۴۵۰,۰۰۰ تومان', 'gold'],
    ].forEach(([label, value, color]) => {
        const metric = createCard();
        metric.append(
            createElement('span', { className: 'kp-vanilla-panel-muted', text: label }),
            createElement('p', { className: `kp-vanilla-accounting-value kp-vanilla-panel-accent-${color}`, text: value }),
        );
        metrics.append(metric);
    });

    const ledger = createCard();
    ledger.append(createElement('h2', {
        className: 'kp-vanilla-panel-section-title',
        text: 'دفتر روزنامه سندهای دوبل حسابداری (Double-Entry Log)',
    }));
    ledger.append(createTable(
        ['سند', 'کد حساب بدهکار', 'کد حساب بستانکار', 'مبلغ (ریال/تومان)', 'بابت'],
        [
            ['#TR-401', '1010-موجودی بانک', '2010-کیف پول مشتری', '۵۳۰,۰۰۰ تومان', 'شارژ کیف پول درگاه شاپرک'],
            ['#TR-402', '2010-کیف پول مشتری', '4010-درآمد کارمزد پلتفرم', '۱۰۶,۰۰۰ تومان', 'کارمزد ۲۰٪ سفارش #KP-88219'],
            ['#TR-403', '2010-کیف پول مشتری', '2020-بستانکاری کلیدساز', '۴۲۴,۰۰۰ تومان', 'سهم ۸۰٪ کلیدساز سفارش #KP-88219'],
        ],
    ));
    panel.append(metrics, ledger);
    return panel;
}

function createPagination(currentPage, totalPages, onPageChange) {
    const pagination = createElement('nav', {
        className: 'kp-pagination',
        attributes: { 'aria-label': 'صفحه‌بندی' },
    });
    const previous = createElement('button', {
        className: 'kp-btn kp-btn-secondary',
        text: 'قبلی',
        attributes: { type: 'button', disabled: currentPage <= 1 },
    });
    previous.addEventListener('click', () => onPageChange(currentPage - 1));
    const next = createElement('button', {
        className: 'kp-btn kp-btn-secondary',
        text: 'بعدی',
        attributes: { type: 'button', disabled: currentPage >= totalPages },
    });
    next.addEventListener('click', () => onPageChange(currentPage + 1));
    pagination.append(
        previous,
        createElement('span', {
            text: `صفحه ${currentPage.toLocaleString('fa-IR')} از ${totalPages.toLocaleString('fa-IR')}`,
            attributes: { 'aria-live': 'polite' },
        }),
        next,
    );
    return pagination;
}

export function renderPanelsPage(root) {
    let activeRole = 'customer';
    let currentPage = 1;
    const page = createElement('main', { className: 'kp-vanilla-panels-page' });
    const notice = createElement('p', {
        className: 'kp-vanilla-demo-notice',
        text: 'اطلاعات این پنل صرفاً نمونهٔ نمایشی است و از دادهٔ زندهٔ سامانه دریافت نشده است.',
        attributes: { role: 'note' },
    });
    const roleSelector = createElement('nav', {
        className: 'kp-card kp-vanilla-panel-role-selector',
        attributes: { 'aria-label': 'انتخاب پنل نمایشی' },
    });
    const content = createElement('section', {
        className: 'kp-vanilla-panels-content',
        attributes: { 'aria-live': 'polite' },
    });

    function renderContent() {
        content.replaceChildren();
        notice.hidden = activeRole === 'technician';
        if (activeRole === 'customer') content.append(renderCustomerPanel());
        if (activeRole === 'technician') content.append(renderTechnicianPanel());
        if (activeRole === 'operator') {
            content.append(renderOperatorPanel(currentPage, (pageNumber) => {
                currentPage = pageNumber;
                renderContent();
            }));
        }
        if (activeRole === 'accountant') content.append(renderAccountantPanel());
    }

    roles.forEach((role) => {
        const button = createElement('button', {
            className: `kp-btn kp-vanilla-panel-role kp-vanilla-role-${role.color}${activeRole === role.key ? ' is-active' : ''}`,
            text: role.label,
            attributes: {
                type: 'button',
                'aria-pressed': activeRole === role.key ? 'true' : 'false',
            },
        });
        button.addEventListener('click', () => {
            activeRole = role.key;
            roleSelector.querySelectorAll('.kp-vanilla-panel-role').forEach((roleButton, index) => {
                const selected = roles[index].key === activeRole;
                roleButton.setAttribute('aria-pressed', selected ? 'true' : 'false');
                roleButton.classList.toggle('is-active', selected);
            });
            renderContent();
        });
        roleSelector.append(button);
    });

    page.append(notice, roleSelector, content);
    root.append(page);
    renderContent();
}
