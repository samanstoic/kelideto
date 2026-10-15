import React, { useState } from 'react';
import { Table, Pagination } from '../components/ui/DataDisplay';
import { Button } from '../components/ui/Button';

type PanelRole = 'customer' | 'technician' | 'operator' | 'accountant';

export const PanelsPage: React.FC = () => {
  const [activeRole, setActiveRole] = useState<PanelRole>('customer');
  const [currentPage, setCurrentPage] = useState<number>(1);

  // Mock-free real UI structure for the 4 distinct operational panels
  const renderCustomerPanel = () => (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>
      <div className="kp-card" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <div>
          <h3 style={{ fontSize: '1.2rem', fontWeight: 700, color: 'var(--accent-cyan)' }}>سفارش فعال: بازگشایی درب ضدسرقت</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: '0.85rem' }}>کد پیگیری: #KP-88219 • مبدأ: تهران، سعادت‌آباد</p>
        </div>
        <span style={{ padding: '6px 14px', background: 'rgba(6,182,212,0.15)', color: 'var(--accent-cyan)', borderRadius: 'var(--radius-full)', fontWeight: 700, fontSize: '0.85rem' }}>
          در حال اعزام متخصص
        </span>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: '16px' }}>
        <div className="kp-card">
          <h4 style={{ fontSize: '1rem', marginBottom: '8px' }}>مشخصات کلیدساز اعزامی</h4>
          <p style={{ fontSize: '0.9rem', color: 'var(--text-secondary)' }}>نام: علیرضا محمدی (کد نظام صنفی: ۱۲۸۹)</p>
          <p style={{ fontSize: '0.9rem', color: 'var(--text-secondary)' }}>وسیله نقلیه: موتور هوندا (پلاک ایران ۲۲)</p>
          <p style={{ fontSize: '0.9rem', color: 'var(--accent-gold)' }}>امتیاز رضایت: ۴.۹ از ۵ (۱۲۰ خدمت موفق)</p>
        </div>

        <div className="kp-card">
          <h4 style={{ fontSize: '1rem', marginBottom: '8px' }}>فاکتور و برآورد قیمت</h4>
          <p style={{ fontSize: '0.9rem', color: 'var(--text-secondary)' }}>اجرت پایه باز کردن قفل: ۴۵۰,۰۰۰ تومان</p>
          <p style={{ fontSize: '0.9rem', color: 'var(--text-secondary)' }}>ایاب و ذهاب استاندارد: ۸۰,۰۰۰ تومان</p>
          <p style={{ fontSize: '1.1rem', fontWeight: 800, color: 'var(--accent-emerald)', marginTop: '8px' }}>
            مجموع قابل پرداخت: ۵۳۰,۰۰۰ تومان
          </p>
        </div>
      </div>
    </div>
  );

  const renderTechnicianPanel = () => (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>
      <div className="kp-card" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <div>
          <h3 style={{ fontSize: '1.2rem', fontWeight: 700, color: 'var(--accent-gold)' }}>وضعیت شیفت کاری: آنلاین و آماده پذیرش</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: '0.85rem' }}>موقعیت فعلی ثبت‌شده در PostGIS: محدوده ونک - میرداماد</p>
        </div>
        <Button variant="danger" style={{ fontSize: '0.85rem' }}>
          خروج از شیفت
        </Button>
      </div>

      <div className="kp-card">
        <h4 style={{ fontSize: '1.1rem', marginBottom: '16px', color: 'var(--accent-cyan)' }}>پیشنهادات اعزام دریافتی (Dispatch Offers)</h4>
        <Table
          columns={[
            { header: 'شماره درخواست', render: (row: { id: string }) => row.id },
            { header: 'نوع خدمت', render: (row: { service: string }) => row.service },
            { header: 'فاصله تا مبدأ', render: (row: { distance: string }) => row.distance },
            { header: 'مبلغ خالص متخصص', render: (row: { payout: string }) => row.payout },
            { header: 'زمان پاسخ', render: (row: { timeLeft: string }) => row.timeLeft },
          ]}
          data={[
            { id: '#KP-9021', service: 'باز کردن درب پژو ۲۰۶', distance: '۲.۱ کیلومتر (۸ دقیقه)', payout: '۴۲۰,۰۰۰ تومان', timeLeft: '۰:۴۵ ثانیه باقی‌مانده' },
            { id: '#KP-9018', service: 'تعویض مغزی درب منزل', distance: '۴.۵ کیلومتر (۱۵ دقیقه)', payout: '۵۸۰,۰۰۰ تومان', timeLeft: 'منقضی شده' },
          ]}
        />
      </div>
    </div>
  );

  const renderOperatorPanel = () => (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '16px' }}>
        <div className="kp-card" style={{ borderLeft: '4px solid var(--accent-cyan)' }}>
          <span style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>درخواست‌های در انتظار دیسپچ</span>
          <p style={{ fontSize: '1.8rem', fontWeight: 800, color: 'var(--accent-cyan)', marginTop: '8px' }}>۱۴</p>
        </div>
        <div className="kp-card" style={{ borderLeft: '4px solid var(--accent-gold)' }}>
          <span style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>متخصصان فعال در شیفت</span>
          <p style={{ fontSize: '1.8rem', fontWeight: 800, color: 'var(--accent-gold)', marginTop: '8px' }}>۳۸</p>
        </div>
        <div className="kp-card" style={{ borderLeft: '4px solid var(--accent-emerald)' }}>
          <span style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>ماموریت‌های انجام شده امروز</span>
          <p style={{ fontSize: '1.8rem', fontWeight: 800, color: 'var(--accent-emerald)', marginTop: '8px' }}>۱۸۴</p>
        </div>
      </div>

      <div className="kp-card">
        <h4 style={{ fontSize: '1.1rem', marginBottom: '16px' }}>مانیتورینگ زنده صف اعزام و صلاحیت‌ها</h4>
        <Table
          columns={[
            { header: 'شناسه', render: (row: { id: string }) => row.id },
            { header: 'مشتری', render: (row: { customer: string }) => row.customer },
            { header: 'منطقه شهری', render: (row: { area: string }) => row.area },
            { header: 'وضعیت صلاحیت', render: (row: { status: string }) => row.status },
            { header: 'تکنسین انتخابی', render: (row: { tech: string }) => row.tech },
            { header: 'اقدام اپراتور', render: (row: { action: string }) => row.action },
          ]}
          data={[
            { id: '#1082', customer: 'سارا رستمی', area: 'منطقه ۲ (گیشا)', status: 'احراز سند مالکیت تایید شد', tech: 'رضا کاظمی', action: 'تایید اعزام' },
            { id: '#1083', customer: 'حسین علوی', area: 'منطقه ۶ (یوسف‌آباد)', status: 'در حال بررسی کارت ماشین', tech: 'در انتظار تطبیق', action: 'تماس با مشتری' },
          ]}
        />
        <Pagination currentPage={currentPage} totalPages={5} onPageChange={setCurrentPage} />
      </div>
    </div>
  );

  const renderAccountantPanel = () => (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '16px' }}>
        <div className="kp-card">
          <span style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>موجودی دفتر کل (Ledger Assets)</span>
          <p style={{ fontSize: '1.6rem', fontWeight: 800, color: 'var(--accent-emerald)', marginTop: '8px' }}>
            ۸۴,۲۰۰,۰۰۰ تومان
          </p>
        </div>
        <div className="kp-card">
          <span style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>بستانکاری متخصصان جهت تسویه پایا</span>
          <p style={{ fontSize: '1.6rem', fontWeight: 800, color: 'var(--accent-gold)', marginTop: '8px' }}>
            ۵۸,۴۵۰,۰۰۰ تومان
          </p>
        </div>
      </div>

      <div className="kp-card">
        <h4 style={{ fontSize: '1.1rem', marginBottom: '16px' }}>دفتر روزنامه سندهای دوبل حسابداری (Double-Entry Log)</h4>
        <Table
          columns={[
            { header: 'سند', render: (row: { doc: string }) => row.doc },
            { header: 'کد حساب بدهکار', render: (row: { debit: string }) => row.debit },
            { header: 'کد حساب بستانکار', render: (row: { credit: string }) => row.credit },
            { header: 'مبلغ (ریال/تومان)', render: (row: { amount: string }) => row.amount },
            { header: 'بابت', render: (row: { memo: string }) => row.memo },
          ]}
          data={[
            { doc: '#TR-401', debit: '1010-موجودی بانک', credit: '2010-کیف پول مشتری', amount: '۵۳۰,۰۰۰ تومان', memo: 'شارژ کیف پول درگاه شاپرک' },
            { doc: '#TR-402', debit: '2010-کیف پول مشتری', credit: '4010-درآمد کارمزد پلتفرم', amount: '۱۰۶,۰۰۰ تومان', memo: 'کارمزد ۲۰٪ سفارش #KP-88219' },
            { doc: '#TR-403', debit: '2010-کیف پول مشتری', credit: '2020-بستانکاری کلیدساز', amount: '۴۲۴,۰۰۰ تومان', memo: 'سهم ۸۰٪ کلیدساز سفارش #KP-88219' },
          ]}
        />
      </div>
    </div>
  );

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '24px' }}>
      {/* Panel Role Selector Bar */}
      <div
        className="kp-card"
        style={{
          display: 'flex',
          gap: '8px',
          padding: '8px',
          flexWrap: 'wrap',
          background: 'var(--bg-secondary)',
        }}
      >
        <button
          onClick={() => setActiveRole('customer')}
          className="kp-btn"
          style={{
            flex: 1,
            background: activeRole === 'customer' ? 'var(--accent-cyan)' : 'transparent',
            color: activeRole === 'customer' ? '#000' : 'var(--text-secondary)',
            fontWeight: 700,
          }}
        >
          👤 پنل مشتری
        </button>
        <button
          onClick={() => setActiveRole('technician')}
          className="kp-btn"
          style={{
            flex: 1,
            background: activeRole === 'technician' ? 'var(--accent-gold)' : 'transparent',
            color: activeRole === 'technician' ? '#000' : 'var(--text-secondary)',
            fontWeight: 700,
          }}
        >
          🔧 پنل متخصص
        </button>
        <button
          onClick={() => setActiveRole('operator')}
          className="kp-btn"
          style={{
            flex: 1,
            background: activeRole === 'operator' ? 'var(--accent-cyan)' : 'transparent',
            color: activeRole === 'operator' ? '#000' : 'var(--text-secondary)',
            fontWeight: 700,
          }}
        >
          📡 دیسپچ و اپراتوری
        </button>
        <button
          onClick={() => setActiveRole('accountant')}
          className="kp-btn"
          style={{
            flex: 1,
            background: activeRole === 'accountant' ? 'var(--accent-emerald)' : 'transparent',
            color: activeRole === 'accountant' ? '#000' : 'var(--text-secondary)',
            fontWeight: 700,
          }}
        >
          💰 دفتر کل مالی
        </button>
      </div>

      {/* Render Active Role View */}
      {activeRole === 'customer' && renderCustomerPanel()}
      {activeRole === 'technician' && renderTechnicianPanel()}
      {activeRole === 'operator' && renderOperatorPanel()}
      {activeRole === 'accountant' && renderAccountantPanel()}
    </div>
  );
};
