import React, { ReactNode } from 'react';
import { OfflineBanner } from '../ui/SystemStates';

export interface AppShellProps {
  children: ReactNode;
  activeTab: string;
  onTabChange: (tab: string) => void;
  isOffline?: boolean;
}

export const AppShell: React.FC<AppShellProps> = ({
  children,
  activeTab,
  onTabChange,
  isOffline = false,
}) => {
  return (
    <div style={{ minHeight: '100vh', display: 'flex', flexDirection: 'column' }}>
      {isOffline && <OfflineBanner />}

      {/* Main Responsive Header */}
      <header className="kp-navbar">
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
          <span style={{ fontSize: '1.75rem' }}>🔑</span>
          <span style={{ fontSize: '1.25rem', fontWeight: 800, color: 'var(--accent-cyan)' }}>
            KeyPro | کلیدتو
          </span>
        </div>

        {/* Desktop Navigation */}
        <nav className="kp-nav-links">
          <button
            onClick={() => onTabChange('landing')}
            style={{
              background: 'transparent',
              color: activeTab === 'landing' ? 'var(--accent-cyan)' : 'var(--text-secondary)',
              fontWeight: activeTab === 'landing' ? 700 : 500,
            }}
          >
            معرفی و خدمات
          </button>
          <button
            onClick={() => onTabChange('booking')}
            style={{
              background: 'transparent',
              color: activeTab === 'booking' ? 'var(--accent-cyan)' : 'var(--text-secondary)',
              fontWeight: activeTab === 'booking' ? 700 : 500,
            }}
          >
            درخواست کلیدساز
          </button>
          <button
            onClick={() => onTabChange('panels')}
            style={{
              background: 'transparent',
              color: activeTab === 'panels' ? 'var(--accent-cyan)' : 'var(--text-secondary)',
              fontWeight: activeTab === 'panels' ? 700 : 500,
            }}
          >
            داشبورد نقش‌ها
          </button>
        </nav>

        <div>
          <button
            onClick={() => onTabChange('auth')}
            className="kp-btn kp-btn-gold"
            style={{ padding: '8px 16px', fontSize: '0.875rem' }}
          >
            ورود / ثبت‌نام
          </button>
        </div>
      </header>

      {/* Main Content Area */}
      <main style={{ flex: 1, padding: '24px 16px', maxWidth: '1200px', margin: '0 auto', width: '100%' }}>
        {children}
      </main>

      {/* Mobile Bottom Navigation Bar */}
      <footer
        style={{
          display: 'flex',
          justifyContent: 'space-around',
          alignItems: 'center',
          padding: '12px 16px',
          background: 'var(--bg-secondary)',
          borderTop: '1px solid var(--border-color)',
          position: 'sticky',
          bottom: 0,
          zIndex: 100,
        }}
      >
        <button
          onClick={() => onTabChange('landing')}
          style={{ background: 'transparent', color: activeTab === 'landing' ? 'var(--accent-cyan)' : 'var(--text-muted)' }}
        >
          خانه
        </button>
        <button
          onClick={() => onTabChange('booking')}
          style={{ background: 'transparent', color: activeTab === 'booking' ? 'var(--accent-cyan)' : 'var(--text-muted)' }}
        >
          درخواست خدمت
        </button>
        <button
          onClick={() => onTabChange('panels')}
          style={{ background: 'transparent', color: activeTab === 'panels' ? 'var(--accent-cyan)' : 'var(--text-muted)' }}
        >
          پنل‌ها
        </button>
        <button
          onClick={() => onTabChange('auth')}
          style={{ background: 'transparent', color: activeTab === 'auth' ? 'var(--accent-gold)' : 'var(--text-muted)' }}
        >
          حساب کاربری
        </button>
      </footer>
    </div>
  );
};
