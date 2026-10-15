import React from 'react';
import { Button } from '../components/ui/Button';

export const LandingPage: React.FC<{ onNavigateToBooking: () => void }> = ({ onNavigateToBooking }) => {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '48px', padding: '16px 0' }}>
      {/* Hero Section */}
      <section
        className="kp-card"
        style={{
          textAlign: 'center',
          padding: '60px 24px',
          background: 'radial-gradient(circle at 50% 20%, rgba(6,182,212,0.15) 0%, rgba(18,24,38,0.95) 75%)',
          borderRadius: 'var(--radius-lg)',
        }}
      >
        <span
          style={{
            display: 'inline-block',
            padding: '6px 16px',
            background: 'rgba(245, 158, 11, 0.1)',
            color: 'var(--accent-gold)',
            borderRadius: 'var(--radius-full)',
            fontSize: '0.85rem',
            fontWeight: 600,
            marginBottom: '16px',
          }}
        >
          سامانه هوشمند اعزام فوری کلیدساز در محل (۲۴ ساعته)
        </span>
        <h1 style={{ fontSize: '2.5rem', fontWeight: 900, marginBottom: '16px', lineHeight: 1.3 }}>
          قفل درب باز نمی‌شود؟ کلید در خودرو جا مانده است؟
        </h1>
        <p style={{ color: 'var(--text-secondary)', maxWidth: '650px', margin: '0 auto 32px auto', fontSize: '1.1rem' }}>
          نزدیک‌ترین کلیدسازان تایید صلاحیت‌شده و دارای پروانه کسب رسمی در کمتر از ۱۵ دقیقه با تجهیزات کامل در محل شما حاضر خواهند بود.
        </p>
        <div style={{ display: 'flex', justifyContent: 'center', gap: '16px', flexWrap: 'wrap' }}>
          <Button variant="gold" onClick={onNavigateToBooking} style={{ fontSize: '1.1rem', padding: '14px 28px' }}>
            درخواست فوری کلیدساز در محل
          </Button>
          <a href="tel:02199998888" className="kp-btn kp-btn-secondary" style={{ fontSize: '1rem', padding: '14px 24px' }}>
            📞 تماس صوتی اضطراری
          </a>
        </div>
      </section>

      {/* Feature Highlights */}
      <section style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: '24px' }}>
        <div className="kp-card">
          <div style={{ fontSize: '2rem', marginBottom: '12px' }}>🛡️</div>
          <h3 style={{ fontSize: '1.2rem', marginBottom: '8px', color: 'var(--accent-cyan)' }}>احراز اصالت و امنیت کامل</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: '0.9rem' }}>
            تایید هویت دوطرفه، گواهی عدم سوءپیشینه و بررسی رسمی مالکیت ملک یا خودرو قبل از هرگونه اقدام به بازگشایی درب.
          </p>
        </div>

        <div className="kp-card">
          <div style={{ fontSize: '2rem', marginBottom: '12px' }}>📍</div>
          <h3 style={{ fontSize: '1.2rem', marginBottom: '8px', color: 'var(--accent-cyan)' }}>تطبیق مکانی هوشمند</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: '0.9rem' }}>
            الگوریتم دیسپچ مبتنی بر موتور مکانی PostGIS و اعزام سریع‌ترین متخصص در شعاع ۵ الی ۱۰ کیلومتری مبدأ.
          </p>
        </div>

        <div className="kp-card">
          <div style={{ fontSize: '2rem', marginBottom: '12px' }}>💳</div>
          <h3 style={{ fontSize: '1.2rem', marginBottom: '8px', color: 'var(--accent-cyan)' }}>تعرفه مصوب و پرداخت امن</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: '0.9rem' }}>
            محاسبه شفاف برآورد قیمت بر اساس نرخ اتحادیه و پرداخت پس از پایان کار از طریق درگاه شاپرک با فاکتور رسمی.
          </p>
        </div>
      </section>
    </div>
  );
};
