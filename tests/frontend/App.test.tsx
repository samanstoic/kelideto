import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import { App } from '../../resources/js/app';

describe('KeyPro Frontend App Navigation & Page Views', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it('renders Landing Page by default with hero call to action', () => {
    render(<App />);

    expect(screen.getByText('KeyPro | کلیدتو')).toBeInTheDocument();
    expect(screen.getByText('سامانه هوشمند اعزام فوری کلیدساز در محل (۲۴ ساعته)')).toBeInTheDocument();
    expect(screen.getByText('قفل درب باز نمی‌شود؟ کلید در خودرو جا مانده است؟')).toBeInTheDocument();
  });

  it('navigates to Booking/Service Selection page when clicking action button', () => {
    render(<App />);

    // Click on "درخواست کلیدساز" in header nav
    const bookingNavBtn = screen.getAllByRole('button', { name: 'درخواست کلیدساز' })[0];
    fireEvent.click(bookingNavBtn);

    expect(screen.getByText('انتخاب خدمت کلیدسازی و امنیت')).toBeInTheDocument();
    expect(screen.getByText('همه خدمات')).toBeInTheDocument();
    expect(screen.getByText('بازگشایی انواع قفل درب ضدسرقت و معمولی')).toBeInTheDocument();
  });

  it('navigates to Auth page and displays login form', () => {
    render(<App />);

    const authBtn = screen.getByRole('button', { name: 'ورود / ثبت‌نام' });
    fireEvent.click(authBtn);

    expect(screen.getByText('ورود / ثبت‌نام در کلیدتو')).toBeInTheDocument();
    expect(screen.getByText('مشتری (خدمات قفل)')).toBeInTheDocument();
    expect(screen.getByText('متخصص و کلیدساز')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'دریافت کد تایید یکبار مصرف' })).toBeInTheDocument();
  });

  it('navigates to Panels and allows switching roles', () => {
    render(<App />);

    const panelsNavBtn = screen.getAllByRole('button', { name: 'داشبورد نقش‌ها' })[0];
    fireEvent.click(panelsNavBtn);

    expect(screen.getByRole('button', { name: '👤 پنل مشتری' })).toBeInTheDocument();
    expect(screen.getByText('سفارش فعال: بازگشایی درب ضدسرقت')).toBeInTheDocument();

    // Switch to Technician panel
    const techBtn = screen.getByRole('button', { name: '🔧 پنل متخصص' });
    fireEvent.click(techBtn);
    expect(screen.getByText(/وضعیت شیفت کاری: آنلاین و آماده پذیرش/)).toBeInTheDocument();

    // Switch to Operator panel
    const opBtn = screen.getByRole('button', { name: '📡 دیسپچ و اپراتوری' });
    fireEvent.click(opBtn);
    expect(screen.getByText('درخواست‌های در انتظار دیسپچ')).toBeInTheDocument();

    // Switch to Accountant panel
    const accBtn = screen.getByRole('button', { name: '💰 دفتر کل مالی' });
    fireEvent.click(accBtn);
    expect(screen.getByText(/موجودی دفتر کل/)).toBeInTheDocument();
  });
});
