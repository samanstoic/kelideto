function createElement(tagName, { className, text, attributes } = {}) {
    const element = document.createElement(tagName);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    if (attributes) {
        Object.entries(attributes).forEach(([name, value]) => {
            if (value !== undefined && value !== null && value !== false) {
                element.setAttribute(name, value === true ? '' : String(value));
            }
        });
    }
    return element;
}

function appendContent(parent, content) {
    if (content instanceof Node) parent.append(content);
    else if (content !== undefined && content !== null) parent.append(document.createTextNode(String(content)));
}

export function createButton({
    variant = 'primary',
    label,
    content = label,
    loading = false,
    disabled = false,
    className = '',
    attributes = {},
    onClick,
} = {}) {
    const button = createElement('button', {
        className: `kp-btn kp-btn-${variant}${className ? ` ${className}` : ''}`,
        attributes: { type: 'button', ...attributes, disabled: disabled || loading },
    });
    if (loading) {
        const spinner = createElement('span', {
            className: 'kp-vanilla-spinner',
            text: '⌛',
            attributes: { 'aria-hidden': 'true' },
        });
        button.append(spinner, document.createTextNode(' '));
    }
    appendContent(button, content);
    if (onClick) button.addEventListener('click', onClick);
    return button;
}

export function createInput({
    id,
    label,
    error,
    className = '',
    attributes = {},
} = {}) {
    const group = createElement('div', { className: 'kp-input-group' });
    if (label) {
        group.append(createElement('label', {
            className: 'kp-label',
            text: label,
            attributes: { for: id },
        }));
    }
    const input = createElement('input', {
        className: `kp-input${error ? ' kp-input-error' : ''}${className ? ` ${className}` : ''}`,
        attributes: {
            id,
            ...attributes,
            'aria-invalid': error ? 'true' : undefined,
            'aria-describedby': error ? `${id}-error` : undefined,
        },
    });
    group.append(input);
    if (error) {
        group.append(createElement('span', {
            className: 'kp-error-text',
            text: error,
            attributes: { id: `${id}-error` },
        }));
    }
    return { element: group, input };
}

let modalSequence = 0;

function focusableElements(root) {
    return [...root.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
    )];
}

export function createModal({ title, content, onClose = () => {} } = {}) {
    modalSequence += 1;
    const titleId = `kp-modal-title-${modalSequence}`;
    const overlay = createElement('div', {
        className: 'kp-modal-overlay',
        attributes: { role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId, tabindex: '-1' },
    });
    const panel = createElement('div', { className: 'kp-modal-content' });
    const header = createElement('header', { className: 'kp-vanilla-modal-header' });
    const heading = createElement('h2', {
        text: title,
        attributes: { id: titleId },
    });
    const closeButton = createButton({
        variant: 'secondary',
        label: '✕',
        className: 'kp-vanilla-modal-close',
        attributes: { 'aria-label': 'بستن پنجره' },
        onClick: close,
    });
    header.append(heading, closeButton);
    const body = createElement('div', { className: 'kp-vanilla-modal-body' });
    appendContent(body, content);
    panel.append(header, body);
    overlay.append(panel);

    const previousActiveElement = document.activeElement;
    const previousOverflow = document.body.style.overflow;
    let destroyed = false;

    function handleKeyDown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }
        if (event.key !== 'Tab') return;
        const focusable = focusableElements(panel);
        if (!focusable.length) {
            event.preventDefault();
            overlay.focus();
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function closeFromBackdrop(event) {
        if (event.target === overlay) close();
    }

    function destroy() {
        if (destroyed) return;
        destroyed = true;
        window.removeEventListener('keydown', handleKeyDown);
        overlay.removeEventListener('click', closeFromBackdrop);
        overlay.remove();
        document.body.style.overflow = previousOverflow;
        if (previousActiveElement instanceof HTMLElement && previousActiveElement.isConnected) {
            previousActiveElement.focus();
        }
    }

    function close() {
        onClose();
        destroy();
    }

    window.addEventListener('keydown', handleKeyDown);
    overlay.addEventListener('click', closeFromBackdrop);
    document.body.style.overflow = 'hidden';
    document.body.append(overlay);
    (focusableElements(panel)[0] ?? overlay).focus();

    return { element: overlay, close, destroy };
}

export function createToast({ message, type = 'info', onClose } = {}) {
    const toast = createElement('div', {
        className: `kp-toast kp-toast-${type}`,
        attributes: { role: type === 'error' ? 'alert' : 'status' },
    });
    toast.append(createElement('span', {
        text: type === 'success' ? '✓' : type === 'error' ? '⚠' : 'ℹ',
        attributes: { 'aria-hidden': 'true' },
    }));
    toast.append(createElement('span', { text: message }));
    if (onClose) {
        toast.append(createButton({
            variant: 'secondary',
            label: '✕',
            className: 'kp-vanilla-toast-close',
            attributes: { 'aria-label': 'بستن پیام' },
            onClick: onClose,
        }));
    }
    return toast;
}

export function createTable({ columns = [], data = [], emptyMessage = 'موردی یافت نشد.' } = {}) {
    if (!data.length) {
        return createElement('div', {
            className: 'kp-vanilla-table-empty',
            text: emptyMessage,
            attributes: { role: 'status' },
        });
    }
    const wrapper = createElement('div', { className: 'kp-table-wrapper' });
    const table = createElement('table', { className: 'kp-table' });
    const head = createElement('thead');
    const headerRow = createElement('tr');
    columns.forEach((column) => headerRow.append(createElement('th', {
        text: column.header,
        attributes: { scope: 'col' },
    })));
    head.append(headerRow);
    const body = createElement('tbody');
    data.forEach((item) => {
        const row = createElement('tr');
        columns.forEach((column) => {
            const cell = createElement('td');
            appendContent(cell, column.render(item));
            row.append(cell);
        });
        body.append(row);
    });
    table.append(head, body);
    wrapper.append(table);
    return wrapper;
}

export function createPagination({ currentPage, totalPages, onPageChange } = {}) {
    const pagination = createElement('nav', {
        className: 'kp-pagination',
        attributes: { 'aria-label': 'صفحه‌بندی' },
    });
    pagination.append(
        createButton({
            variant: 'secondary',
            label: 'قبلی',
            attributes: { disabled: currentPage <= 1 },
            onClick: () => onPageChange(currentPage - 1),
        }),
        createElement('span', {
            text: `صفحه ${currentPage.toLocaleString('fa-IR')} از ${totalPages.toLocaleString('fa-IR')}`,
            attributes: { 'aria-current': 'page' },
        }),
        createButton({
            variant: 'secondary',
            label: 'بعدی',
            attributes: { disabled: currentPage >= totalPages },
            onClick: () => onPageChange(currentPage + 1),
        }),
    );
    return pagination;
}

export function createLoadingState(message = 'در حال بارگذاری اطلاعات...') {
    const state = createElement('div', {
        className: 'kp-vanilla-loading-state',
        attributes: { role: 'status', 'aria-live': 'polite' },
    });
    state.append(
        createElement('span', { text: '⏳', attributes: { 'aria-hidden': 'true' } }),
        createElement('p', { text: message }),
    );
    return state;
}

export function createErrorState({ title, description, actionText = 'تلاش مجدد', onAction } = {}) {
    const state = createElement('section', {
        className: 'kp-vanilla-error-state',
        attributes: { role: 'alert' },
    });
    state.append(createElement('h2', { text: title }));
    if (description) state.append(createElement('p', { text: description }));
    if (onAction) {
        state.append(createButton({ variant: 'danger', label: actionText, onClick: onAction }));
    }
    return state;
}

export function createEmptyState({ title, description, actionText, onAction } = {}) {
    const state = createElement('section', {
        className: 'kp-vanilla-empty-content',
        attributes: { role: 'status' },
    });
    state.append(createElement('h2', { text: title }));
    if (description) state.append(createElement('p', { text: description }));
    if (actionText && onAction) {
        state.append(createButton({ variant: 'primary', label: actionText, onClick: onAction }));
    }
    return state;
}

export function createOfflineBanner() {
    return createElement('div', {
        className: 'kp-vanilla-offline-banner',
        text: '📡 اتصال اینترنت قطع است — پیش‌نویس‌ها و اطلاعات ذخیره‌شده در دسترس‌اند.',
        attributes: { role: 'status' },
    });
}
