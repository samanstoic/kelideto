import React, { useState } from 'react';
import { Button } from '../components/ui/Button';
import { Input } from '../components/ui/Input';
import { Toast } from '../components/ui/DataDisplay';

interface AuthPageProps {
  onLoginSuccess?: (role: string, phone: string) => void;
}

export const AuthPage: React.FC<AuthPageProps> = ({ onLoginSuccess }) => {
  const [role, setRole] = useState<'customer' | 'technician'>('customer');
  const [step, setStep] = useState<'phone' | 'otp'>('phone');
  const [phone, setPhone] = useState<string>('');
  const [otp, setOtp] = useState<string>('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState<boolean>(false);
  const [toastMsg, setToastMsg] = useState<string | null>(null);

  const handleSendOtp = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    const iranPhoneRegex = /^09[0-9]{9}$/;
    if (!iranPhoneRegex.test(phone)) {
      setError('شماره موبایل باید با ۰۹ شروع شده و ۱۱ رقم باشد (مثال: ۰۹۱۲۳۴۵۶۷۸۹)');
      return;
    }

    setLoading(true);
    try {
      const response = await fetch('/api/auth/otp/send', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ phone, role }),
      });

      const data = await response.json();
      if (!response.ok) {
        throw new Error(data.message || 'خطا در ارسال کد تایید');
      }

      setToastMsg(`کد تایید پیامکی ارسال شد. (کد تستی محیط توسعه: ${data.debug_otp || 'ارسال به SMS'})`);
      setStep('otp');
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'خطای ارتباط با سرور');
    } finally {
      setLoading(false);
    }
  };

  const handleVerifyOtp = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (otp.length !== 6) {
      setError('کد تایید باید ۶ رقمی باشد');
      return;
    }

    setLoading(true);
    try {
      const response = await fetch('/api/auth/otp/verify', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ phone, otp, role }),
      });

      const data = await response.json();
      if (!response.ok) {
        throw new Error(data.message || 'کد وارد شده نامعتبر یا منقضی شده است');
      }

      setToastMsg('احراز هویت موفقیت‌آمیز بود! در حال انتقال...');
      if (onLoginSuccess) {
        onLoginSuccess(data.user?.role || role, phone);
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'خطای تایید کد');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div style={{ maxWidth: '440px', margin: '40px auto' }}>
      {toastMsg && <Toast message={toastMsg} type="success" onClose={() => setToastMsg(null)} />}

      <div className="kp-card" style={{ padding: '32px' }}>
        {/* Role Segmented Selector */}
        <div
          style={{
            display: 'flex',
            background: 'var(--bg-secondary)',
            borderRadius: 'var(--radius-sm)',
            padding: '4px',
            marginBottom: '24px',
            border: '1px solid var(--border-color)',
          }}
        >
          <button
            type="button"
            onClick={() => {
              setRole('customer');
              setStep('phone');
              setError(null);
            }}
            style={{
              flex: 1,
              padding: '10px',
              borderRadius: 'var(--radius-sm)',
              background: role === 'customer' ? 'var(--accent-cyan)' : 'transparent',
              color: role === 'customer' ? '#000' : 'var(--text-secondary)',
              fontWeight: 700,
              fontSize: '0.9rem',
              transition: 'all 0.2s',
            }}
          >
            مشتری (خدمات قفل)
          </button>
          <button
            type="button"
            onClick={() => {
              setRole('technician');
              setStep('phone');
              setError(null);
            }}
            style={{
              flex: 1,
              padding: '10px',
              borderRadius: 'var(--radius-sm)',
              background: role === 'technician' ? 'var(--accent-gold)' : 'transparent',
              color: role === 'technician' ? '#000' : 'var(--text-secondary)',
              fontWeight: 700,
              fontSize: '0.9rem',
              transition: 'all 0.2s',
            }}
          >
            متخصص و کلیدساز
          </button>
        </div>

        <h2 style={{ fontSize: '1.4rem', fontWeight: 800, marginBottom: '8px', textAlign: 'center' }}>
          {step === 'phone' ? 'ورود / ثبت‌نام در کلیدتو' : 'تایید شماره موبایل'}
        </h2>
        <p style={{ color: 'var(--text-muted)', fontSize: '0.875rem', marginBottom: '24px', textAlign: 'center' }}>
          {step === 'phone'
            ? 'شماره موبایل خود را وارد کنید تا کد ورود یکبار مصرف ارسال شود'
            : `کد ۶ رقمی ارسال‌شده به شماره ${phone} را وارد نمایید`}
        </p>

        {step === 'phone' ? (
          <form onSubmit={handleSendOtp} style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
            <Input
              id="phone-input"
              label="شماره تلفن همراه"
              placeholder="۰۹۱۲۳۴۵۶۷۸۹"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
              error={error || undefined}
              required
            />
            <Button
              type="submit"
              variant={role === 'customer' ? 'primary' : 'gold'}
              disabled={loading}
              style={{ width: '100%', padding: '12px' }}
            >
              {loading ? 'در حال ارسال کد...' : 'دریافت کد تایید یکبار مصرف'}
            </Button>
          </form>
        ) : (
          <form onSubmit={handleVerifyOtp} style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
            <Input
              id="otp-input"
              label="کد تایید ۶ رقمی"
              placeholder="------"
              maxLength={6}
              value={otp}
              onChange={(e) => setOtp(e.target.value)}
              error={error || undefined}
              required
            />
            <Button
              type="submit"
              variant="primary"
              disabled={loading}
              style={{ width: '100%', padding: '12px' }}
            >
              {loading ? 'در حال بررسی...' : 'تایید و ورود به حساب'}
            </Button>
            <button
              type="button"
              onClick={() => {
                setStep('phone');
                setOtp('');
                setError(null);
              }}
              style={{
                background: 'transparent',
                color: 'var(--text-muted)',
                fontSize: '0.85rem',
                cursor: 'pointer',
                textAlign: 'center',
                marginTop: '8px',
              }}
            >
              ویرایش شماره موبایل
            </button>
          </form>
        )}
      </div>
    </div>
  );
};
