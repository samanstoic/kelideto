import React, { ReactNode } from 'react';
import { Button } from '../ui/Button';

export interface StateViewProps {
  title: string;
  description?: string;
  actionText?: string;
  onAction?: () => void;
  icon?: ReactNode;
}

export const LoadingState: React.FC<{ message?: string }> = ({
  message = 'در حال بارگذاری اطلاعات...',
}) => (
  <div style={{ textAlign: 'center', padding: '48px 24px' }}>
    <div style={{ fontSize: '2rem', marginBottom: '16px', animation: 'pulse 1.5s infinite' }}>⏳</div>
    <p style={{ color: 'var(--text-secondary)' }}>{message}</p>
  </div>
);

export const ErrorState: React.FC<StateViewProps> = ({
  title,
  description,
  actionText = 'تلاش مجدد',
  onAction,
}) => (
  <div style={{ textAlign: 'center', padding: '48px 24px', background: 'rgba(244, 63, 94, 0.05)', borderRadius: 'var(--radius-md)' }}>
    <div style={{ fontSize: '2.5rem', marginBottom: '12px' }}>⚠️</div>
    <h3 style={{ color: 'var(--accent-rose)', marginBottom: '8px' }}>{title}</h3>
    {description && <p style={{ color: 'var(--text-muted)', marginBottom: '20px' }}>{description}</p>}
    {onAction && (
      <Button variant="danger" onClick={onAction}>
        {actionText}
      </Button>
    )}
  </div>
);

export const EmptyState: React.FC<StateViewProps> = ({
  title,
  description,
  actionText,
  onAction,
}) => (
  <div style={{ textAlign: 'center', padding: '48px 24px' }}>
    <div style={{ fontSize: '2.5rem', marginBottom: '12px', opacity: 0.6 }}>📦</div>
    <h3 style={{ color: 'var(--text-secondary)', marginBottom: '8px' }}>{title}</h3>
    {description && <p style={{ color: 'var(--text-muted)', marginBottom: '20px' }}>{description}</p>}
    {actionText && onAction && (
      <Button variant="primary" onClick={onAction}>
        {actionText}
      </Button>
    )}
  </div>
);

export const OfflineBanner: React.FC = () => (
  <div
    style={{
      background: 'linear-gradient(90deg, #991b1b, #7f1d1d)',
      color: '#fef2f2',
      padding: '10px 16px',
      textAlign: 'center',
      fontSize: '0.875rem',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      gap: '8px',
    }}
  >
    <span>📡</span>
    <span>اتصال اینترنت قطع است — حالت آفلاین فعال است (امکان ثبت سفارش نیازمند تماس با مرکز پشتیبانی است).</span>
  </div>
);
