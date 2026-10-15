import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import '../css/app.css';

interface HealthData {
  status: string;
  timestamp: string;
  services: {
    database: { status: string; driver: string };
    cache: { status: string };
  };
}

export const App: React.FC = () => {
  const [health, setHealth] = useState<HealthData | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetch('/api/health')
      .then((res) => {
        if (!res.ok) throw new Error('خطا در دریافت وضعیت سلامت سرور');
        return res.json();
      })
      .then((data: HealthData) => {
        setHealth(data);
        setLoading(false);
      })
      .catch((err: Error) => {
        setError(err.message);
        setLoading(false);
      });
  }, []);

  return (
    <div style={{ maxWidth: '800px', margin: '40px auto', padding: '24px' }}>
      <header style={{ textAlign: 'center', marginBottom: '32px' }}>
        <h1 style={{ color: 'var(--accent-cyan)', fontSize: '2rem', marginBottom: '8px' }}>
          سامانه جامع خدمات کلیدسازی KeyPro
        </h1>
        <p style={{ color: 'var(--text-secondary)' }}>
          پلتفرم آنلاین خدمات قفل، کلید و امنیت در محل (فاز یک: زیرساخت و سلامت)
        </p>
      </header>

      <div
        id="health-card"
        style={{
          background: 'var(--bg-card)',
          backdropFilter: 'blur(12px)',
          border: '1px solid var(--border-color)',
          borderRadius: 'var(--radius-lg)',
          padding: '24px',
          boxShadow: 'var(--shadow-glass)',
        }}
      >
        <h2 style={{ fontSize: '1.25rem', marginBottom: '16px', color: 'var(--accent-gold)' }}>
          وضعیت سلامت سامانه‌ها (Live Health Check)
        </h2>

        {loading && <p style={{ color: 'var(--text-muted)' }}>در حال بررسی اتصال به سرور...</p>}

        {error && (
          <div style={{ color: 'var(--accent-rose)', padding: '12px', background: 'rgba(244,63,94,0.1)', borderRadius: 'var(--radius-sm)' }}>
            {error}
          </div>
        )}

        {health && (
          <div style={{ display: 'grid', gap: '16px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', padding: '12px', background: 'var(--bg-secondary)', borderRadius: 'var(--radius-sm)' }}>
              <span>وضعیت کلی سیستم:</span>
              <span id="overall-status" style={{ color: health.status === 'healthy' ? 'var(--accent-emerald)' : 'var(--accent-rose)', fontWeight: 'bold' }}>
                {health.status === 'healthy' ? 'فعال و سالم (Healthy)' : 'خطا'}
              </span>
            </div>

            <div style={{ display: 'flex', justifyContent: 'space-between', padding: '12px', background: 'var(--bg-secondary)', borderRadius: 'var(--radius-sm)' }}>
              <span>پایگاه داده:</span>
              <span id="db-status" style={{ color: 'var(--accent-cyan)' }}>
                {health.services.database.driver} ({health.services.database.status})
              </span>
            </div>

            <div style={{ display: 'flex', justifyContent: 'space-between', padding: '12px', background: 'var(--bg-secondary)', borderRadius: 'var(--radius-sm)' }}>
              <span>حافظه کش:</span>
              <span id="cache-status" style={{ color: 'var(--accent-cyan)' }}>
                {health.services.cache.status}
              </span>
            </div>

            <div style={{ fontSize: '0.875rem', color: 'var(--text-muted)', textAlign: 'left', direction: 'ltr' }}>
              Timestamp: {health.timestamp}
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

const container = document.getElementById('app');
if (container) {
  const root = createRoot(container);
  root.render(<App />);
}
