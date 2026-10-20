import { icon } from '../ui/icons.js';
import { el, renderShell, routerLink } from '../ui/shell.js';

const BOOKING = '/booking?frontend=vanilla';

const SERVICES = [
    { ic: 'alert', title: 'بازگشایی اضطراری', text: 'درخواست فوری برای درب قفل‌شده و بررسی متخصص آزاد نزدیک شما.' },
    { ic: 'house', title: 'کلیدساز منزل', text: 'تعویض سیلندر، تغییر رمز قفل و ارتقای امنیت آپارتمان و ویلا.' },
    { ic: 'building', title: 'اداری و تجاری', text: 'سیستم شاه‌کلید، کنترل تردد و امنیت فروشگاه و دفتر کار.' },
    { ic: 'car', title: 'کلیدساز خودرو', text: 'بازگشایی درب خودرو، ساخت سوئیچ و تعریف ریموت ایموبلایزر.' },
    { ic: 'smartphone', title: 'نصب قفل هوشمند', text: 'مشاوره و نصب قفل دیجیتال متناسب با درب و سخت‌افزار انتخابی.' },
    { ic: 'key', title: 'تغییر رمز قفل', text: 'تعویض مغزی و ساخت کلید جدید بدون نیاز به تعویض کل قفل.' },
    { ic: 'safe', title: 'گاوصندوق', text: 'رمزگشایی مکانیکی و دیجیتال و تعمیر قفل گاوصندوق.' },
    { ic: 'clock', title: 'کلیدساز شبانه‌روزی', text: 'پوشش ساعات بامداد و تعطیلات رسمی با پشتیبانی مستقیم.' },
];

const STEPS = [
    { ic: 'camera', title: 'نیاز خود را ثبت کنید', text: 'نوع خدمت را انتخاب کنید، نشانی را روی نقشه مشخص کنید و در صورت امکان عکسی از قفل بفرستید.' },
    { ic: 'mapPin', title: 'بدانید چه کسی می‌آید', text: 'پس از پذیرش درخواست، مشخصات و پروانه متخصص را ببینید و مسیر او را لحظه‌ای دنبال کنید.' },
    { ic: 'receipt', title: 'ریز قیمت را بررسی کنید', text: 'اجرت، قطعات و هزینه ایاب‌وذهاب را پیش از تایید نهایی درخواست مشاهده کنید.' },
    { ic: 'wallet', title: 'کار و فاکتور را تحویل بگیرید', text: 'پس از پایان کار، پرداخت امن آنلاین انجام دهید و فاکتور رسمی را نگه دارید.' },
];

const FEATURES = [
    { ic: 'shield', title: 'احراز اصالت و امنیت کامل', text: 'تایید هویت دوطرفه، گواهی عدم سوءپیشینه و بررسی رسمی مالکیت ملک یا خودرو قبل از هرگونه اقدام به بازگشایی درب.' },
    { ic: 'mapPin', title: 'تطبیق مکانی هوشمند', text: 'اعزام سریع‌ترین متخصص در شعاع ۵ الی ۱۰ کیلومتری شما و پیگیری لحظه‌ای مسیر روی نقشه.' },
    { ic: 'wallet', title: 'تعرفه مصوب و پرداخت امن', text: 'محاسبه شفاف برآورد قیمت بر اساس نرخ اتحادیه و پرداخت پس از پایان کار از طریق درگاه شاپرک با فاکتور رسمی.' },
    { ic: 'circleCheck', title: 'تایید تغییرات کار', text: 'اگر کار اضافه‌ای لازم شد، دلیل و قیمت جدید را می‌بینید و تا تایید شما کاری انجام نمی‌شود.' },
];

const AREAS = ['تهران', 'کرج', 'اصفهان', 'شیراز', 'مشهد'];

const FAQS = [
    ['قیمت‌گذاری کلیدتو چگونه است؟', 'نوع کار را توضیح دهید و عکس قفل را اضافه کنید. پیش از تایید، برآورد هزینه شامل اجرت، قطعات و ایاب‌وذهاب را می‌بینید. اگر در محل دامنه کار تغییر کند، ابتدا قیمت جدید را تایید می‌کنید.'],
    ['آیا کلیدسازان دارای مجوز هستند؟', 'همه متخصصان پروانه کسب معتبر از اتحادیه دارند و هویت، سوابق و مدارک آن‌ها پیش از فعال‌سازی حساب بررسی می‌شود. پیش از شروع کار می‌توانید مشخصات متخصص اعزامی را تطبیق دهید.'],
    ['کلیدساز چقدر سریع می‌رسد؟', 'زمان رسیدن به موقعیت، ترافیک و تعداد متخصصان آزاد بستگی دارد. میانگین حضور در شهرهای تحت پوشش حدود ۱۵ تا ۳۰ دقیقه است و می‌توانید مسیر را روی نقشه دنبال کنید.'],
    ['هزینه خدمات چقدر است؟', 'هزینه به نوع کار، قفل و قطعات مورد نیاز بستگی دارد. حداقل تعرفه هر خدمت در صفحه درخواست نمایش داده می‌شود و ریز قیمت پیش از تایید در دسترس است.'],
    ['برای بازگشایی درب چه مدارکی لازم است؟', 'برای حفظ امنیت، ارائه کارت ملی و مدرکی دال بر سکونت یا مالکیت ملک یا خودرو الزامی است. متخصص پیش از بازگشایی آن را بررسی می‌کند.'],
    ['در اینترنت ضعیف هم کار می‌کند؟', 'بله. درخواست‌ها به‌صورت پیش‌نویس آفلاین ذخیره می‌شوند و به محض اتصال، خودکار همگام‌سازی می‌شوند. بسته‌های نقشه آفلاین نیز قابل دریافت است.'],
];

function sectionHeading(title, subtitle) {
    return [
        el('h2', { className: 'kp-la-h2', text: title }),
        el('p', { className: 'kp-la-lead', text: subtitle }),
    ];
}

function createPhoneMockups() {
    const home = el('div', { className: 'kp-la-phone kp-la-phone-back', attributes: { 'aria-hidden': 'true' } }, [
        el('div', { className: 'kp-la-phone-notch' }),
        el('div', { className: 'kp-la-screen' }, [
            el('div', { className: 'kp-la-screen-top' }, [el('span', { text: 'سلام 👋' }), el('strong', { text: 'چه کمکی لازم دارید؟' })]),
            el('div', { className: 'kp-la-screen-map' }, [
                el('span', { className: 'kp-la-map-road kp-la-map-road-a' }),
                el('span', { className: 'kp-la-map-road kp-la-map-road-b' }),
                el('span', { className: 'kp-la-map-pin' }, [icon('mapPin')]),
                el('span', { className: 'kp-la-map-car' }, [icon('car')]),
            ]),
            el('div', { className: 'kp-la-screen-tiles' }, ['key', 'house', 'car', 'safe'].map((name) =>
                el('span', { className: 'kp-la-tile' }, [icon(name)]))),
        ]),
    ]);

    const rows = [['اجرت متخصص', '۴۵۰٬۰۰۰'], ['ایاب‌وذهاب', '۸۰٬۰۰۰'], ['قطعات', '۰'], ['مالیات', '۴۷٬۷۰۰']];
    const receipt = el('div', { className: 'kp-la-phone kp-la-phone-front', attributes: { 'aria-hidden': 'true' } }, [
        el('div', { className: 'kp-la-phone-notch' }),
        el('div', { className: 'kp-la-screen' }, [
            el('div', { className: 'kp-la-screen-top' }, [el('span', { text: 'بازگشایی درب' }), el('strong', { text: 'ریز قیمت' })]),
            el('div', { className: 'kp-la-tech' }, [
                el('span', { className: 'kp-la-tech-avatar' }, [icon('user')]),
                el('div', {}, [el('strong', { text: 'علی رضایی' }), el('small', {}, [icon('star', 'kp-icon kp-la-star'), ' ۴٫۹ · پروانه تایید شده'])]),
            ]),
            el('ul', { className: 'kp-la-breakdown' }, rows.map(([k, v]) => el('li', {}, [el('span', { text: k }), el('span', { text: v })]))),
            el('div', { className: 'kp-la-total' }, [el('span', { text: 'جمع کل' }), el('strong', { text: '۵۷۷٬۷۰۰ ت' })]),
            el('span', { className: 'kp-la-screen-btn', text: 'تایید و درخواست' }),
        ]),
    ]);

    return el('div', { className: 'kp-la-hero-visual' }, [el('div', { className: 'kp-la-hero-glow' }), home, receipt]);
}

function createHero() {
    const section = el('section', { className: 'kp-card kp-vanilla-hero kp-la-hero' });
    section.append(
        el('aside', { className: 'kp-la-notice', attributes: { 'aria-label': 'اطلاعیه' } }, [
            el('p', {}, [
                el('strong', { text: 'جدید: ' }),
                'ثبت درخواست در اینترنت ضعیف؛ پیش‌نویس شما آفلاین ذخیره و پس از اتصال خودکار ارسال می‌شود. ',
                routerLink('نقشه آفلاین ←', '/location?frontend=vanilla', 'kp-la-notice-link'),
            ]),
        ]),
        el('div', { className: 'kp-la-blob kp-la-blob-a' }),
        el('div', { className: 'kp-la-blob kp-la-blob-b' }),
    );

    const grid = el('div', { className: 'kp-la-container kp-la-hero-grid' });
    const copy = el('div', { className: 'kp-la-hero-copy kp-la-fade' });
    const title = el('h1', { className: 'kp-vanilla-title kp-la-h1' }, [
        'قفل درب باز نمی‌شود؟',
        el('br'),
        'کلید در خودرو جا مانده است؟',
        el('br'),
        el('span', { className: 'kp-la-accent', text: 'قیمت را ببینید' }),
        ' و بعد تایید کنید.',
    ]);
    const ctas = el('div', { className: 'kp-vanilla-actions kp-la-hero-ctas' }, [
        el('a', {
            className: 'kp-la-btn kp-la-btn-primary kp-la-btn-lg kp-la-glow',
            attributes: { href: BOOKING, 'aria-label': 'درخواست فوری کلیدساز در محل', 'data-router-link': '' },
        }, ['درخواست فوری کلیدساز در محل', icon('arrowLeft')]),
        el('a', {
            className: 'kp-la-btn kp-la-btn-outline-primary kp-la-btn-lg',
            attributes: { href: 'tel:02199998888', 'aria-label': 'تماس صوتی اضطراری' },
        }, [icon('phone'), 'تماس صوتی اضطراری']),
    ]);
    const trust = el('div', { className: 'kp-la-trust' }, [
        ['badgeCheck', 'متخصصان دارای پروانه'],
        ['shield', 'ریز قیمت پیش از تایید'],
        ['clock', 'پیگیری لحظه‌ای سفارش'],
    ].map(([ic, label]) => el('div', { className: 'kp-la-trust-item' }, [icon(ic), el('span', { text: label })])));

    copy.append(
        el('div', { className: 'kp-vanilla-badge kp-la-pill' }, [icon('sparkles'), el('span', { text: 'اعزام فوری کلیدساز در محل · ۲۴ ساعته' })]),
        title,
        el('p', {
            className: 'kp-vanilla-description kp-la-hero-desc',
            text: 'نزدیک‌ترین کلیدسازان تایید صلاحیت‌شده و دارای پروانه کسب رسمی در کمتر از ۱۵ دقیقه با تجهیزات کامل در محل شما حاضر خواهند بود.',
        }),
        ctas,
        trust,
    );
    grid.append(copy, createPhoneMockups());
    section.append(grid);
    return section;
}

function createServicesSection() {
    const section = el('section', { className: 'kp-la-section', attributes: { 'aria-labelledby': 'kp-la-services-title' } });
    const container = el('div', { className: 'kp-la-container kp-la-narrow-lg' });
    const [h2, lead] = sectionHeading('خدمات کلیدسازی کلیدتو', 'نوع کار را مشخص کنید، متخصص آزاد را ببینید و قیمت درخواست خود را بررسی کنید.');
    h2.id = 'kp-la-services-title';
    const grid = el('div', { className: 'kp-la-grid kp-la-grid-4' }, SERVICES.map((service) =>
        el('a', { className: 'kp-la-card kp-la-card-link', attributes: { href: BOOKING, 'data-router-link': '' } }, [
            icon(service.ic, 'kp-icon kp-la-card-icon'),
            el('h3', { text: service.title }),
            el('p', { text: service.text }),
        ])));
    container.append(h2, lead, grid);
    section.append(container);
    return section;
}

function createHowItWorks() {
    const section = el('section', {
        className: 'kp-la-section kp-la-section-alt kp-la-bordered',
        attributes: { id: 'how-it-works', 'aria-labelledby': 'kp-la-how-title' },
    });
    const grid = el('div', { className: 'kp-la-container kp-la-how-grid' });
    const intro = el('div', { className: 'kp-la-how-intro' }, [
        el('p', { className: 'kp-la-eyebrow', text: 'مرحله به مرحله' }),
        el('h2', { className: 'kp-la-h2 kp-la-start', text: 'کلیدتو چگونه کار می‌کند؟', attributes: { id: 'kp-la-how-title' } }),
        el('p', { className: 'kp-la-how-lead', text: 'از ثبت مشکل قفل تا دریافت فاکتور، این چیزی است که در سامانه انتظارش را دارید.' }),
        el('a', { className: 'kp-la-btn kp-la-btn-primary', attributes: { href: BOOKING, 'data-router-link': '' } }, ['شروع درخواست', icon('arrowLeft')]),
    ]);
    const steps = el('ol', { className: 'kp-la-steps' }, STEPS.map((step, index) =>
        el('li', { className: 'kp-la-step' }, [
            el('span', { className: 'kp-la-step-num', text: (index + 1).toLocaleString('fa-IR', { minimumIntegerDigits: 2 }), attributes: { 'aria-hidden': 'true' } }),
            el('div', { className: 'kp-la-step-body' }, [
                el('div', { className: 'kp-la-step-head' }, [el('h3', { text: step.title }), icon(step.ic)]),
                el('p', { text: step.text }),
            ]),
        ])));
    const callout = el('div', { className: 'kp-la-callout' }, [
        icon('shieldCheck', 'kp-icon kp-la-callout-icon'),
        el('div', {}, [
            el('h3', { text: 'هر تغییری در کار، با تصمیم شماست.' }),
            el('p', { text: 'اگر کار اضافه‌ای پیشنهاد شود، دلیل و ریز قیمت جدید را می‌بینید و پیش از ادامه کار آن را تایید یا رد می‌کنید.' }),
        ]),
    ]);
    grid.append(intro, steps, el('div', { className: 'kp-la-how-callout' }, [callout]));
    section.append(grid);
    return section;
}

function createFeatures() {
    const section = el('section', { className: 'kp-vanilla-feature-grid kp-la-section', attributes: { 'aria-labelledby': 'kp-la-why-title' } });
    const container = el('div', { className: 'kp-la-container kp-la-narrow' });
    const [h2, lead] = sectionHeading('چرا کلیدتو متفاوت است؟', 'پیش از اعزام، کار، قیمت و فردی که می‌آید را بشناسید.');
    h2.id = 'kp-la-why-title';
    const grid = el('div', { className: 'kp-la-grid kp-la-grid-2' }, FEATURES.map((feature) =>
        el('article', { className: 'kp-vanilla-feature-card kp-la-card' }, [
            el('div', { className: 'kp-vanilla-feature-icon' }, [icon(feature.ic, 'kp-icon kp-la-card-icon')]),
            el('h3', { className: 'kp-vanilla-feature-title', text: feature.title }),
            el('p', { className: 'kp-vanilla-feature-text', text: feature.text }),
        ])));
    container.append(h2, lead, grid);
    section.append(container);
    return section;
}

function createAreas() {
    const section = el('section', { className: 'kp-la-section kp-la-section-alt', attributes: { 'aria-labelledby': 'kp-la-areas-title' } });
    const container = el('div', { className: 'kp-la-container kp-la-narrow' });
    const [h2, lead] = sectionHeading('مناطق تحت پوشش', 'شهر خود را انتخاب کنید و دسترسی متخصصان را در موقعیت و زمان دلخواه بررسی کنید.');
    h2.id = 'kp-la-areas-title';
    const grid = el('div', { className: 'kp-la-grid kp-la-grid-5' }, AREAS.map((city) =>
        el('a', { className: 'kp-la-card kp-la-card-link kp-la-card-center kp-la-card-solid', attributes: { href: '/location?frontend=vanilla', 'data-router-link': '' } }, [
            icon('mapPin', 'kp-icon kp-la-card-icon'),
            el('h3', { text: city }),
            el('p', { className: 'kp-la-small', text: 'مشاهده خدمات' }),
        ])));
    container.append(h2, lead, grid);
    section.append(container);
    return section;
}

function createTrustSection() {
    const section = el('section', { className: 'kp-la-section' });
    const container = el('div', { className: 'kp-la-container kp-la-narrow kp-la-center' });
    container.append(
        el('h2', { className: 'kp-la-h2', text: 'پیش از شروع کار، جزئیات را بررسی کنید' }),
        el('p', { className: 'kp-la-lead', text: 'نام و شماره پروانه متخصص اعزامی را با اطلاعات درج‌شده در سامانه تطبیق دهید. نظرات فقط پس از انجام سفارش واقعی با برچسب «سفارش تاییدشده» منتشر می‌شوند.' }),
        el('div', { className: 'kp-la-pills' }, ['بررسی مدارک', 'مشاهده برآورد', 'تایید تغییرات'].map((label) =>
            el('span', { className: 'kp-la-pill kp-la-pill-lg', text: label }))),
        el('div', { className: 'kp-la-row-center' }, [
            el('a', { className: 'kp-la-btn kp-la-btn-primary kp-la-glow', attributes: { href: BOOKING, 'data-router-link': '' } }, ['مشاهده خدمات و قیمت‌ها', icon('arrowLeft')]),
            el('a', { className: 'kp-la-btn kp-la-btn-outline-primary', attributes: { href: '/auth?frontend=vanilla', 'data-router-link': '' } }, ['همکاری به‌عنوان متخصص', icon('shield')]),
        ]),
    );
    section.append(container);
    return section;
}

function createFaq() {
    const section = el('section', { className: 'kp-la-section kp-la-section-alt', attributes: { id: 'faq', 'aria-labelledby': 'kp-la-faq-title' } });
    const container = el('div', { className: 'kp-la-container kp-la-narrow-sm' });
    const [h2, lead] = sectionHeading('سوالات متداول', 'پاسخ صریح درباره قیمت، مجوز، زمان رسیدن و مناطق تحت پوشش.');
    h2.id = 'kp-la-faq-title';
    const list = el('div', { className: 'kp-la-accordion' });
    FAQS.forEach(([question, answer], index) => {
        const buttonId = `kp-la-faq-q${index}`;
        const panelId = `kp-la-faq-a${index}`;
        const button = el('button', {
            className: 'kp-la-acc-trigger',
            attributes: { type: 'button', id: buttonId, 'aria-expanded': 'false', 'aria-controls': panelId },
        }, [el('span', { text: question }), icon('chevronDown')]);
        const panel = el('div', {
            className: 'kp-la-acc-panel',
            attributes: { id: panelId, role: 'region', 'aria-labelledby': buttonId, hidden: true },
        }, [el('p', { text: answer })]);
        button.addEventListener('click', () => {
            const open = button.getAttribute('aria-expanded') !== 'true';
            button.setAttribute('aria-expanded', String(open));
            panel.hidden = !open;
        });
        list.append(el('div', { className: 'kp-la-acc-item' }, [el('h3', {}, [button]), panel]));
    });
    container.append(h2, lead, list);
    section.append(container);
    return section;
}

function createQuickLinks() {
    const section = el('section', { className: 'kp-la-section-sm kp-la-section-alt' });
    const container = el('div', { className: 'kp-la-container kp-la-narrow' });
    container.append(el('div', { className: 'kp-la-grid kp-la-grid-quick' }, [
        ['خدمات', 'تعرفه شفاف', BOOKING],
        ['نقشه', 'موقعیت و آفلاین', '/location?frontend=vanilla'],
        ['حساب کاربری', 'ورود با پیامک', '/auth?frontend=vanilla'],
        ['متخصصان', 'پنل همکاران', '/panels?frontend=vanilla'],
    ].map(([title, sub, href]) => el('a', { className: 'kp-la-quick', attributes: { href, 'data-router-link': '' } }, [
        el('strong', { text: title }),
        el('span', { text: sub }),
    ]))));
    section.append(container);
    return section;
}

export function renderLandingPage(root) {
    const landing = el('main', { className: 'kp-vanilla-landing' });
    landing.append(
        createHero(),
        createServicesSection(),
        createHowItWorks(),
        createFeatures(),
        createAreas(),
        createTrustSection(),
        createFaq(),
        createQuickLinks(),
    );
    renderShell(root, 'landing', landing);
}
