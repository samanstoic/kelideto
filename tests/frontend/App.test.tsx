import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import React from 'react';
import { App } from '../../resources/js/app';

describe('KeyPro Frontend App & Health View', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it('renders the main heading and Persian title correctly', () => {
    // Mock the fetch call to return a healthy payload
    vi.spyOn(global, 'fetch').mockImplementation(() =>
      Promise.resolve({
        ok: true,
        json: () =>
          Promise.resolve({
            status: 'healthy',
            timestamp: '2026-10-15T12:00:00Z',
            services: {
              database: { status: 'healthy', driver: 'pgsql' },
              cache: { status: 'healthy' },
            },
          }),
      } as Response)
    );

    render(<App />);

    expect(screen.getByText('سامانه جامع خدمات کلیدسازی KeyPro')).toBeInTheDocument();
    expect(screen.getByText('وضعیت سلامت سامانه‌ها (Live Health Check)')).toBeInTheDocument();
  });

  it('displays healthy server status when API responds', async () => {
    vi.spyOn(global, 'fetch').mockImplementation(() =>
      Promise.resolve({
        ok: true,
        json: () =>
          Promise.resolve({
            status: 'healthy',
            timestamp: '2026-10-15T12:00:00Z',
            services: {
              database: { status: 'healthy', driver: 'pgsql' },
              cache: { status: 'healthy' },
            },
          }),
      } as Response)
    );

    render(<App />);

    await waitFor(() => {
      expect(screen.getByText('فعال و سالم (Healthy)')).toBeInTheDocument();
      expect(screen.getByText('pgsql (healthy)')).toBeInTheDocument();
    });
  });

  it('displays error message when health API fails', async () => {
    vi.spyOn(global, 'fetch').mockImplementation(() =>
      Promise.reject(new Error('خطا در برقراری ارتباط با شبکه سرور'))
    );

    render(<App />);

    await waitFor(() => {
      expect(screen.getByText('خطا در برقراری ارتباط با شبکه سرور')).toBeInTheDocument();
    });
  });
});
