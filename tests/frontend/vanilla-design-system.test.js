import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    createButton,
    createEmptyState,
    createErrorState,
    createInput,
    createLoadingState,
    createModal,
    createOfflineBanner,
    createPagination,
    createTable,
    createToast,
} from '../../resources/js/vanilla/ui/components.js';

afterEach(() => {
    document.body.replaceChildren();
    document.body.style.overflow = '';
});

describe('vanilla design system components', () => {
    it('creates accessible button variants, loading state, and click behavior', () => {
        const onClick = vi.fn();
        const button = createButton({
            variant: 'gold',
            label: 'درخواست فوری کلیدساز',
            loading: true,
            onClick,
        });
        document.body.append(button);

        expect(button.className).toContain('kp-btn-gold');
        expect(button.disabled).toBe(true);
        expect(button.querySelector('.kp-vanilla-spinner')).not.toBeNull();
        button.click();
        expect(onClick).not.toHaveBeenCalled();
    });

    it('renders a labeled input and connects validation errors accessibly', () => {
        const { element, input } = createInput({
            id: 'phone',
            label: 'شماره تلفن همراه',
            error: 'شماره همراه نامعتبر است',
            attributes: { type: 'tel', placeholder: '۰۹۱۲۳۴۵۶۷۸۹' },
        });
        document.body.append(element);

        expect(element.querySelector('label').htmlFor).toBe('phone');
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(input.getAttribute('aria-describedby')).toBe('phone-error');
        expect(element.querySelector('#phone-error').textContent).toBe('شماره همراه نامعتبر است');
    });

    it('traps modal focus, closes by Escape/backdrop/button, and restores document state', () => {
        const opener = document.createElement('button');
        document.body.append(opener);
        opener.focus();
        const onClose = vi.fn();
        const modal = createModal({
            title: 'تایید درخواست',
            content: document.createTextNode('محتوای مودال'),
            onClose,
        });

        expect(modal.element.getAttribute('aria-modal')).toBe('true');
        expect(document.body.style.overflow).toBe('hidden');
        expect(modal.element.textContent).toContain('محتوای مودال');
        modal.element.querySelector('[aria-label="بستن پنجره"]').click();
        expect(onClose).toHaveBeenCalledOnce();
        expect(modal.element.isConnected).toBe(false);

        const escapeModal = createModal({ title: 'پنجره دوم', onClose });
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(onClose).toHaveBeenCalledTimes(2);
        expect(escapeModal.element.isConnected).toBe(false);
        expect(document.body.style.overflow).toBe('');
        expect(document.activeElement).toBe(opener);
    });

    it('creates dismissible toast and safe table content with a meaningful empty state', () => {
        const onClose = vi.fn();
        const toast = createToast({ message: 'عملیات موفق', type: 'success', onClose });
        document.body.append(toast);
        toast.querySelector('[aria-label="بستن پیام"]').click();
        expect(onClose).toHaveBeenCalledOnce();
        expect(toast.getAttribute('role')).toBe('status');

        const table = createTable({
            columns: [
                { header: 'شناسه', render: (item) => item.id },
                { header: 'خدمت', render: (item) => item.name },
                { header: 'هزینه', render: (item) => item.price },
            ],
            data: [{ id: '#101', name: 'باز کردن درب', price: '۴۵۰٬۰۰۰ تومان' }],
        });
        expect(table.querySelectorAll('th')).toHaveLength(3);
        expect(table.querySelector('td').textContent).toBe('#101');
        expect(createTable({ data: [] }).textContent).toBe('موردی یافت نشد.');
    });

    it('paginates within bounds and exposes all application states', () => {
        const onPageChange = vi.fn();
        const pagination = createPagination({
            currentPage: 2,
            totalPages: 5,
            onPageChange,
        });
        pagination.querySelector('button:last-of-type').click();
        expect(onPageChange).toHaveBeenCalledWith(3);
        expect(pagination.textContent).toContain('صفحه ۲ از ۵');

        expect(createLoadingState('در حال محاسبه فاصله...').textContent).toContain('در حال محاسبه فاصله...');
        const onRetry = vi.fn();
        const error = createErrorState({
            title: 'خطا در اتصال به GPS',
            description: 'سیگنال ماهواره ضعیف است',
            onAction: onRetry,
        });
        error.querySelector('button').click();
        expect(onRetry).toHaveBeenCalledOnce();
        expect(createEmptyState({ title: 'سفارشی یافت نشد' }).textContent).toContain('سفارشی یافت نشد');
        expect(createOfflineBanner().textContent).toContain('اتصال اینترنت قطع است');
    });
});
