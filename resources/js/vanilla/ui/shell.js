import { icon } from './icons.js';

export function el(tagName, options = {}, children = []) {
    const element = document.createElement(tagName);
    if (options.className) element.className = options.className;
    if (options.text !== undefined) element.textContent = options.text;
    if (options.attributes) {
        Object.entries(options.attributes).forEach(([name, value]) => {
            if (value !== undefined && value !== null && value !== false) {
                element.setAttribute(name, value === true ? '' : String(value));
            }
        });
    }
    children.forEach((child) => {
        if (child instanceof Node) element.append(child);
        else if (child !== undefined && child !== null) element.append(document.createTextNode(String(child)));
    });
    return element;
}

export function routerLink(label, href, className = '', extra = {}) {
    return el('a', {
        className,
        text: label,
        attributes: { href, 'data-router-link': '', ...extra },
    });
}

const NAV_ITEMS = [
    { key: 'landing', label: 'معرفی و خدمات', href: '/?frontend=vanilla' },
    { key: 'booking', label: 'درخواست کلیدساز', href: '/booking?frontend=vanilla' },
    { key: 'orders', label: 'پیگیری سفارش‌ها', href: '/orders?frontend=vanilla' },
    { key: 'support', label: 'پشتیبانی و شکایت', href: '/support?frontend=vanilla' },
    { key: 'notifications', label: 'اعلان‌ها', href: '/notifications?frontend=vanilla' },
    { key: 'location', label: 'نقشه و موقعیت', href: '/location?frontend=vanilla' },
    { key: 'panels', label: 'داشبورد نقش‌ها', href: '/panels?frontend=vanilla' },
];

function navLink(item, activePage, className) {
    const active = item.key === activePage;
    return routerLink(item.label, item.href, `${className}${active ? ' is-active' : ''}`, {
        'aria-current': active ? 'page' : null,
    });
}

function createBrand() {
    const brand = el('a', {
        className: 'kp-vanilla-nav-link kp-vanilla-brand kp-la-brand',
        attributes: { href: '/?frontend=vanilla', 'data-router-link': '', 'aria-label': 'KeyPro کلیدتو - صفحه اصلی' },
    });
    brand.append(
        el('span', { className: 'kp-la-brand-mark' }, [icon('key')]),
        el('span', { className: 'kp-la-brand-text' }, [
            el('strong', { text: 'KeyPro' }),
            el('small', { text: 'کلیدتو' }),
        ]),
    );
    return brand;
}

function createHeader(activePage) {
    const header = el('header', { className: 'kp-navbar kp-vanilla-navbar kp-la-nav' });
    const inner = el('div', { className: 'kp-la-container kp-la-nav-inner' });

    const desktopNav = el('nav', {
        className: 'kp-nav-links kp-la-nav-links',
        attributes: { 'aria-label': 'ناوبری اصلی' },
    }, NAV_ITEMS.map((item) => navLink(item, activePage, 'kp-vanilla-nav-link kp-la-nav-link')));

    const actions = el('div', { className: 'kp-la-nav-actions' });
    const authLink = routerLink('ورود / ثبت‌نام', '/auth?frontend=vanilla',
        `kp-vanilla-nav-link kp-vanilla-auth-link kp-la-btn kp-la-btn-outline${activePage === 'auth' ? ' is-active' : ''}`,
        { 'aria-current': activePage === 'auth' ? 'page' : null });
    const helpLink = routerLink('درخواست فوری', '/booking?frontend=vanilla', 'kp-la-btn kp-la-btn-primary kp-la-nav-cta');

    const menuButton = el('button', {
        className: 'kp-la-menu-btn',
        attributes: {
            type: 'button',
            'aria-label': 'باز کردن منوی ناوبری',
            'aria-expanded': 'false',
            'aria-controls': 'kp-la-drawer',
        },
    }, [icon('menu')]);

    actions.append(authLink, helpLink, menuButton);
    inner.append(createBrand(), desktopNav, actions);
    header.append(inner);

    const drawer = el('div', {
        className: 'kp-la-drawer',
        attributes: { id: 'kp-la-drawer', hidden: true, 'aria-label': 'منوی ناوبری' },
    });
    const drawerPanel = el('div', { className: 'kp-la-drawer-panel' });
    const closeButton = el('button', {
        className: 'kp-la-menu-btn',
        attributes: { type: 'button', 'aria-label': 'بستن منو' },
    }, [icon('x')]);
    drawerPanel.append(
        el('div', { className: 'kp-la-drawer-head' }, [createBrand(), closeButton]),
        el('nav', { className: 'kp-la-drawer-links', attributes: { 'aria-label': 'ناوبری موبایل' } },
            [...NAV_ITEMS, { key: 'auth', label: 'ورود / ثبت‌نام', href: '/auth?frontend=vanilla' }]
                .map((item) => navLink(item, activePage, 'kp-la-drawer-link'))),
        routerLink('درخواست فوری کلیدساز', '/booking?frontend=vanilla', 'kp-la-btn kp-la-btn-primary kp-la-btn-block'),
    );
    drawer.append(drawerPanel);

    const setOpen = (open) => {
        drawer.hidden = !open;
        if (open) {
            drawer.setAttribute('role', 'dialog');
            drawer.setAttribute('aria-modal', 'true');
        } else {
            drawer.removeAttribute('role');
            drawer.removeAttribute('aria-modal');
        }
        menuButton.setAttribute('aria-expanded', String(open));
        document.body.classList.toggle('kp-la-lock-scroll', open);
        if (open) closeButton.focus();
    };
    menuButton.addEventListener('click', () => setOpen(true));
    closeButton.addEventListener('click', () => setOpen(false));
    drawer.addEventListener('click', (event) => {
        if (event.target === drawer || event.target.closest('a')) setOpen(false);
    });
    drawer.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setOpen(false);
            menuButton.focus();
        }
    });

    return { header, drawer };
}

const FOOTER_COLUMNS = [
    {
        id: 'services',
        title: 'خدمات',
        links: [
            ['بازگشایی درب منزل', '/booking?frontend=vanilla'],
            ['کلیدساز خودرو', '/booking?frontend=vanilla'],
            ['گاوصندوق و اداری', '/booking?frontend=vanilla'],
            ['تعویض سیلندر', '/booking?frontend=vanilla'],
            ['کلیدساز شبانه‌روزی', '/booking?frontend=vanilla'],
        ],
    },
    {
        id: 'areas',
        title: 'مناطق تحت پوشش',
        links: [
            ['تهران', '/location?frontend=vanilla'],
            ['کرج', '/location?frontend=vanilla'],
            ['اصفهان', '/location?frontend=vanilla'],
            ['شیراز', '/location?frontend=vanilla'],
            ['مشهد', '/location?frontend=vanilla'],
        ],
    },
    {
        id: 'customers',
        title: 'مشتریان',
        links: [
            ['نحوه کار', '/?frontend=vanilla#how-it-works'],
            ['سوالات متداول', '/?frontend=vanilla#faq'],
            ['ثبت درخواست', '/booking?frontend=vanilla'],
            ['پیگیری سفارش‌ها', '/orders?frontend=vanilla'],
            ['حساب کاربری', '/auth?frontend=vanilla'],
        ],
    },
    {
        id: 'technicians',
        title: 'متخصصان',
        links: [
            ['همکاری با کلیدتو', '/auth?frontend=vanilla'],
            ['پنل تکنسین', '/panels?frontend=vanilla'],
            ['بسته‌های نقشه آفلاین', '/location?frontend=vanilla'],
        ],
    },
];

function createSiteFooter() {
    const footer = el('footer', { className: 'kp-la-footer', attributes: { 'aria-label': 'پاورقی سایت' } });
    const container = el('div', { className: 'kp-la-container' });
    const grid = el('div', { className: 'kp-la-footer-grid' });

    grid.append(el('div', { className: 'kp-la-footer-brand' }, [
        el('h2', { text: 'KeyPro | کلیدتو' }),
        el('p', { text: 'درخواست کلیدساز، شفافیت قیمت و پیگیری لحظه‌ای سفارش، همه در یک سامانه.' }),
        el('a', { className: 'kp-la-footer-phone', attributes: { href: 'tel:02199998888' } }, [icon('phone'), '۰۲۱-۹۹۹۹۸۸۸۸']),
        el('a', { className: 'kp-la-footer-mail', attributes: { href: 'mailto:support@kelideto.com' } }, [icon('mail'), 'support@kelideto.com']),
    ]));

    FOOTER_COLUMNS.forEach((column) => {
        const listId = `kp-la-footer-${column.id}`;
        const toggle = el('button', {
            className: 'kp-la-footer-toggle',
            attributes: { type: 'button', 'aria-expanded': 'false', 'aria-controls': listId },
        }, [el('span', { text: column.title }), icon('chevronDown')]);
        const list = el('ul', { className: 'kp-la-footer-list', attributes: { id: listId } },
            column.links.map(([label, href]) => el('li', {}, [routerLink(label, href)])));
        toggle.addEventListener('click', () => {
            const open = toggle.getAttribute('aria-expanded') !== 'true';
            toggle.setAttribute('aria-expanded', String(open));
            list.classList.toggle('is-open', open);
        });
        grid.append(el('div', { className: 'kp-la-footer-col' }, [
            toggle,
            el('h3', { className: 'kp-la-footer-title', text: column.title }),
            list,
        ]));
    });

    const bottom = el('div', { className: 'kp-la-footer-bottom' }, [
        el('div', { className: 'kp-la-footer-tagline' }, [
            el('strong', { text: 'قیمت را قبل از تایید ببینید' }),
            el('span', { text: '•' }),
            el('span', { text: 'مشخصات متخصص را بررسی کنید' }),
            el('span', { text: '•' }),
            el('span', { text: 'سفارش را لحظه‌ای دنبال کنید' }),
        ]),
        el('div', { className: 'kp-la-socials' }, [
            el('a', { className: 'kp-la-social', attributes: { href: 'https://instagram.com', target: '_blank', rel: 'noopener noreferrer', 'aria-label': 'اینستاگرام کلیدتو' } }, [icon('instagram')]),
            el('a', { className: 'kp-la-social', attributes: { href: 'https://t.me', target: '_blank', rel: 'noopener noreferrer', 'aria-label': 'تلگرام کلیدتو' } }, [icon('send')]),
            el('a', { className: 'kp-la-social', attributes: { href: 'tel:02199998888', 'aria-label': 'تماس با پشتیبانی' } }, [icon('phone')]),
        ]),
        el('p', { className: 'kp-la-copy', text: `© ${new Date().getFullYear().toLocaleString('fa-IR', { useGrouping: false })} کلیدتو. تمامی حقوق محفوظ است.` }),
    ]);

    container.append(grid, bottom);
    footer.append(container);
    return footer;
}

function createMobileNav(activePage) {
    return el('footer', {
        className: 'kp-vanilla-mobile-nav kp-la-mobile-nav',
        attributes: { 'aria-label': 'ناوبری پایین' },
    }, [
        { key: 'landing', label: 'خانه', href: '/?frontend=vanilla', ic: 'house' },
        { key: 'booking', label: 'درخواست خدمت', href: '/booking?frontend=vanilla', ic: 'key' },
        { key: 'orders', label: 'پیگیری سفارش', href: '/orders?frontend=vanilla', ic: 'receipt' },
        { key: 'notifications', label: 'اعلان‌ها', href: '/notifications?frontend=vanilla', ic: 'message' },
        { key: 'location', label: 'نقشه', href: '/location?frontend=vanilla', ic: 'mapPin' },
        { key: 'panels', label: 'پنل‌ها', href: '/panels?frontend=vanilla', ic: 'receipt' },
        { key: 'auth', label: 'حساب کاربری', href: '/auth?frontend=vanilla', ic: 'user' },
    ].map((item) => {
        const link = navLink(item, activePage, 'kp-vanilla-nav-link kp-la-mobile-link');
        link.textContent = '';
        link.append(icon(item.ic), el('span', { text: item.label }));
        return link;
    }));
}

function createChatButton() {
    return el('a', {
        className: 'kp-la-chat',
        attributes: { href: 'tel:02199998888', 'aria-label': 'گفتگو با پشتیبانی کلیدتو' },
    }, [icon('message')]);
}

export function renderShell(root, activePage, content) {
    const { header, drawer } = createHeader(activePage);
    const layout = el('div', { className: 'kp-la-layout' });
    content.classList.add('kp-la-main');
    layout.append(header, drawer, content, createSiteFooter(), createMobileNav(activePage), createChatButton());
    root.append(layout);
}
