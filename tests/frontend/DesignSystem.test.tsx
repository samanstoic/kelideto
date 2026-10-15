import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import { Button } from '../../resources/js/components/ui/Button';
import { Input } from '../../resources/js/components/ui/Input';
import { Modal } from '../../resources/js/components/ui/Modal';
import { Toast, Table, Pagination } from '../../resources/js/components/ui/DataDisplay';
import { LoadingState, ErrorState, EmptyState, OfflineBanner } from '../../resources/js/components/ui/SystemStates';

describe('KeyPro Design System & UI Components', () => {
  it('renders Button with variants and handles click events', () => {
    const handleClick = vi.fn();
    render(
      <Button variant="gold" onClick={handleClick}>
        درخواست فوری کلیدساز
      </Button>
    );

    const btn = screen.getByRole('button', { name: 'درخواست فوری کلیدساز' });
    expect(btn).toBeInTheDocument();
    expect(btn).toHaveClass('kp-btn-gold');

    fireEvent.click(btn);
    expect(handleClick).toHaveBeenCalledTimes(1);
  });

  it('renders Input with Persian label and error message', () => {
    render(
      <Input
        id="phone"
        label="شماره تلفن همراه"
        placeholder="۰۹۱۲۳۴۵۶۷۸۹"
        error="شماره همراه نامعتبر است"
      />
    );

    expect(screen.getByText('شماره تلفن همراه')).toBeInTheDocument();
    expect(screen.getByPlaceholderText('۰۹۱۲۳۴۵۶۷۸۹')).toBeInTheDocument();
    expect(screen.getByText('شماره همراه نامعتبر است')).toBeInTheDocument();
    expect(screen.getByRole('textbox')).toHaveAttribute('aria-invalid', 'true');
  });

  it('renders Modal, traps focus, and supports closing via button and Escape key', () => {
    const handleClose = vi.fn();
    const { rerender } = render(
      <Modal isOpen={true} onClose={handleClose} title="تایید درخواست">
        <div>محتوای تست مودال</div>
      </Modal>
    );

    expect(screen.getByText('تایید درخواست')).toBeInTheDocument();
    expect(screen.getByText('محتوای تست مودال')).toBeInTheDocument();

    // Close via close button
    const closeBtn = screen.getByLabelText('بستن پنجره');
    fireEvent.click(closeBtn);
    expect(handleClose).toHaveBeenCalledTimes(1);

    // Close via Escape key
    fireEvent.keyDown(window, { key: 'Escape' });
    expect(handleClose).toHaveBeenCalledTimes(2);

    // Modal unmounts when isOpen is false
    rerender(
      <Modal isOpen={false} onClose={handleClose} title="تایید درخواست">
        <div>محتوای تست مودال</div>
      </Modal>
    );
    expect(screen.queryByText('تایید درخواست')).not.toBeInTheDocument();
  });

  it('renders Toast with auto-timer callback support', () => {
    const handleClose = vi.fn();
    render(<Toast message="عملیات موفق" type="success" onClose={handleClose} />);

    expect(screen.getByText('عملیات موفق')).toBeInTheDocument();
    const closeToastBtn = screen.getByLabelText('بستن پیام');
    fireEvent.click(closeToastBtn);
    expect(handleClose).toHaveBeenCalledTimes(1);
  });

  it('renders accessible Table with Persian data and headers', () => {
    render(
      <Table
        columns={[
          { header: 'شناسه', render: (item: { id: string }) => item.id },
          { header: 'خدمت', render: (item: { name: string }) => item.name },
          { header: 'هزینه', render: (item: { price: string }) => item.price },
        ]}
        data={[
          { id: '#101', name: 'باز کردن درب', price: '۴۵۰,۰۰۰ تومان' },
          { id: '#102', name: 'تعویض قفل', price: '۶۰۰,۰۰۰ تومان' },
        ]}
      />
    );

    expect(screen.getByText('شناسه')).toBeInTheDocument();
    expect(screen.getByText('باز کردن درب')).toBeInTheDocument();
    expect(screen.getByText('۶۰۰,۰۰۰ تومان')).toBeInTheDocument();
  });

  it('renders Pagination and fires onPageChange correctly', () => {
    const handlePageChange = vi.fn();
    render(<Pagination currentPage={2} totalPages={5} onPageChange={handlePageChange} />);

    expect(screen.getByText('صفحه ۲ از ۵')).toBeInTheDocument();

    const nextBtn = screen.getByText('بعدی');
    fireEvent.click(nextBtn);
    expect(handlePageChange).toHaveBeenCalledWith(3);

    const prevBtn = screen.getByText('قبلی');
    fireEvent.click(prevBtn);
    expect(handlePageChange).toHaveBeenCalledWith(1);
  });

  it('renders all System States: Loading, Error, Empty, and OfflineBanner', () => {
    const { rerender } = render(<LoadingState message="در حال محاسبه فاصله..." />);
    expect(screen.getByText('در حال محاسبه فاصله...')).toBeInTheDocument();

    const handleRetry = vi.fn();
    rerender(<ErrorState title="خطا در اتصال به GPS" description="سیگنال ماهواره ضعیف است" onAction={handleRetry} />);
    expect(screen.getByText('خطا در اتصال به GPS')).toBeInTheDocument();
    expect(screen.getByText('سیگنال ماهواره ضعیف است')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'تلاش مجدد' }));
    expect(handleRetry).toHaveBeenCalledTimes(1);

    rerender(<EmptyState title="سفارشی یافت نشد" description="شما هنوز درخواستی ثبت نکرده‌اید." />);
    expect(screen.getByText('سفارشی یافت نشد')).toBeInTheDocument();

    rerender(<OfflineBanner />);
    expect(screen.getByText(/اتصال اینترنت قطع است/)).toBeInTheDocument();
  });
});
