import React, { useState } from 'react';
import { Button } from '../components/ui/Button';
import { Modal } from '../components/ui/Modal';
import { Toast } from '../components/ui/DataDisplay';

interface ServiceItem {
  id: string;
  name: string;
  category: 'residential' | 'automotive' | 'commercial' | 'emergency';
  categoryLabel: string;
  description: string;
  basePrice: number;
  estMinutes: number;
  badge?: string;
  icon: string;
}

const DEMO_SERVICES: ServiceItem[] = [
  {
    id: 'res-open-door',
    name: 'بازگشایی انواع قفل درب ضدسرقت و معمولی',
    category: 'residential',
    categoryLabel: 'منازل و آپارتمان',
    description: 'باز کردن تخصصی درب‌های ضدسرقت بدون تخریب سیلندر و کمترین آسیب با ابزار تخصصی',
    basePrice: 450000,
    estMinutes: 20,
    badge: 'پرفروش‌ترین',
    icon: '🚪',
  },
  {
    id: 'res-cylinder-change',
    name: 'تعویض سیلندر و قفل درب حیاط و ورودی',
    category: 'residential',
    categoryLabel: 'منازل و آپارتمان',
    description: 'نصب مغزی ضداسید و کلیدهای کامپیوتری دوجداره برندهای معتبر کاله و داف',
    basePrice: 650000,
    estMinutes: 30,
    icon: '🔒',
  },
  {
    id: 'auto-open-car',
    name: 'باز کردن درب خودرو سواری و وانت',
    category: 'automotive',
    categoryLabel: 'خودرو و وسایل نقلیه',
    description: 'بازگشایی درب بسته خودرو بدون خم کردن لچکی با پمپ هوا و ابزار استاندارد',
    basePrice: 500000,
    estMinutes: 15,
    badge: 'فوری / اورژانسی',
    icon: '🚗',
  },
  {
    id: 'auto-immo-key',
    name: 'تراش کلید و تعریف ریموت ایموبلایزر',
    category: 'automotive',
    categoryLabel: 'خودرو و وسایل نقلیه',
    description: 'کددهی سوئیچ و تعریف چیپ ضدسرقت خودروهای داخلی و وارداتی در محل',
    basePrice: 1200000,
    estMinutes: 45,
    icon: '🔑',
  },
  {
    id: 'comm-safe-box',
    name: 'بازگشایی و تعمیر تخصصی قفل گاوصندوق',
    category: 'commercial',
    categoryLabel: 'اداری و تجاری',
    description: 'رمزگشایی مکانیکی و دیجیتال گاوصندوق‌های کاوه، ایران کاوه و گنجینه',
    basePrice: 1500000,
    estMinutes: 60,
    icon: '💼',
  },
  {
    id: 'emg-night-locksmith',
    name: 'اعزام کلیدساز شبانه‌روزی اورژانسی',
    category: 'emergency',
    categoryLabel: 'اورژانس ۲۴ ساعته',
    description: 'خدمت فوری در ساعات بامداد و تعطیلات رسمی با پشتیبانی دیسپچ مستقیم',
    basePrice: 700000,
    estMinutes: 15,
    badge: '۲۴ ساعته',
    icon: '🚨',
  },
];

export const ServiceSelectionPage: React.FC = () => {
  const [selectedCategory, setSelectedCategory] = useState<string>('all');
  const [activeModalService, setActiveModalService] = useState<ServiceItem | null>(null);
  const [toastMsg, setToastMsg] = useState<string | null>(null);

  const categories = [
    { key: 'all', label: 'همه خدمات' },
    { key: 'residential', label: 'درب و منازل' },
    { key: 'automotive', label: 'خودرو' },
    { key: 'commercial', label: 'گاوصندوق و اداری' },
    { key: 'emergency', label: 'اورژانس شبانه' },
  ];

  const filteredServices =
    selectedCategory === 'all'
      ? DEMO_SERVICES
      : DEMO_SERVICES.filter((s) => s.category === selectedCategory);

  const handleBookingSubmit = () => {
    const serviceName = activeModalService?.name;
    setActiveModalService(null);
    setToastMsg(`درخواست خدمت "${serviceName}" با موفقیت ثبت اولیه شد. متخصص به‌زودی اعزام خواهد شد.`);
  };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '28px' }}>
      {toastMsg && <Toast message={toastMsg} type="success" onClose={() => setToastMsg(null)} />}

      <header style={{ textAlign: 'center', marginBottom: '8px' }}>
        <h1 style={{ fontSize: '1.8rem', fontWeight: 800, marginBottom: '8px', color: 'var(--accent-cyan)' }}>
          انتخاب خدمت کلیدسازی و امنیت
        </h1>
        <p style={{ color: 'var(--text-secondary)', fontSize: '0.95rem' }}>
          نوع مشکل یا نیاز خود را انتخاب نمایید تا نزدیک‌ترین متخصص مجهز به ابزار مناسب به محل شما اعزام گردد.
        </p>
      </header>

      {/* Category Filter Pills */}
      <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap', justifyContent: 'center' }}>
        {categories.map((cat) => (
          <button
            key={cat.key}
            onClick={() => setSelectedCategory(cat.key)}
            className="kp-btn"
            style={{
              padding: '8px 18px',
              borderRadius: 'var(--radius-full)',
              background: selectedCategory === cat.key ? 'var(--accent-cyan)' : 'var(--bg-secondary)',
              color: selectedCategory === cat.key ? '#000' : 'var(--text-secondary)',
              fontWeight: 600,
              fontSize: '0.875rem',
            }}
          >
            {cat.label}
          </button>
        ))}
      </div>

      {/* Service Cards Grid */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(320px, 1fr))', gap: '20px' }}>
        {filteredServices.map((service) => (
          <div
            key={service.id}
            className="kp-card"
            style={{
              display: 'flex',
              flexDirection: 'column',
              justifyContent: 'space-between',
              transition: 'transform 0.2s, box-shadow 0.2s',
            }}
          >
            <div>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '12px' }}>
                <span style={{ fontSize: '2rem' }}>{service.icon}</span>
                {service.badge && (
                  <span
                    style={{
                      background: 'rgba(245, 158, 11, 0.15)',
                      color: 'var(--accent-gold)',
                      fontSize: '0.75rem',
                      fontWeight: 700,
                      padding: '4px 10px',
                      borderRadius: 'var(--radius-full)',
                    }}
                  >
                    {service.badge}
                  </span>
                )}
              </div>
              <h3 style={{ fontSize: '1.15rem', fontWeight: 700, marginBottom: '8px', lineHeight: 1.4 }}>
                {service.name}
              </h3>
              <p style={{ color: 'var(--text-muted)', fontSize: '0.85rem', lineHeight: 1.5, marginBottom: '16px' }}>
                {service.description}
              </p>
            </div>

            <div style={{ borderTop: '1px solid var(--border-color)', paddingTop: '16px' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '12px' }}>
                <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>حداقل تعرفه مصوب:</span>
                <span style={{ fontWeight: 800, fontSize: '1.1rem', color: 'var(--accent-cyan)' }}>
                  {service.basePrice.toLocaleString('fa-IR')} تومان
                </span>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
                <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>میانگین حضور در محل:</span>
                <span style={{ fontSize: '0.85rem', color: 'var(--text-secondary)' }}>
                  ~ {service.estMinutes} دقیقه
                </span>
              </div>
              <Button
                variant="primary"
                onClick={() => setActiveModalService(service)}
                style={{ width: '100%', padding: '10px' }}
              >
                ثبت درخواست این خدمت
              </Button>
            </div>
          </div>
        ))}
      </div>

      {/* Confirmation & Order Modal */}
      {activeModalService && (
        <Modal
          title={`درخواست خدمت: ${activeModalService.name}`}
          isOpen={!!activeModalService}
          onClose={() => setActiveModalService(null)}
        >
          <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
            <p style={{ fontSize: '0.9rem', color: 'var(--text-secondary)' }}>
              لطفاً جهت اعزام تکنسین، نشانی و وضعیت قفل یا سوئیچ را تایید فرمایید:
            </p>

            <div style={{ padding: '12px', background: 'var(--bg-secondary)', borderRadius: 'var(--radius-sm)' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '6px' }}>
                <span style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>نوع خدمت:</span>
                <span style={{ fontWeight: 600 }}>{activeModalService.name}</span>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <span style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>هزینه برآورد پایه:</span>
                <span style={{ color: 'var(--accent-gold)', fontWeight: 700 }}>
                  {activeModalService.basePrice.toLocaleString('fa-IR')} تومان
                </span>
              </div>
            </div>

            <div style={{ fontSize: '0.8rem', color: 'var(--text-muted)', lineHeight: 1.5 }}>
              ⚠️ توجه: همراه داشتن کارت ملی و مدارک معتبر احراز سکونت یا مالکیت خودرو به منظور حفظ امنیت الزامی است.
            </div>

            <div style={{ display: 'flex', gap: '12px', justifyContent: 'flex-end', marginTop: '12px' }}>
              <Button variant="secondary" onClick={() => setActiveModalService(null)}>
                انصراف
              </Button>
              <Button variant="gold" onClick={handleBookingSubmit}>
                تایید نهایی و جستجوی کلیدساز
              </Button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
};
