import React, { ReactNode } from 'react';

export interface ToastProps {
  message: string;
  type?: 'success' | 'error' | 'info';
  onClose?: () => void;
}

export const Toast: React.FC<ToastProps> = ({ message, type = 'info', onClose }) => {
  return (
    <div className={`kp-toast kp-toast-${type}`} role="alert">
      <span>{type === 'success' ? '✓' : type === 'error' ? '⚠' : 'ℹ'}</span>
      <span style={{ flex: 1 }}>{message}</span>
      {onClose && (
        <button
          onClick={onClose}
          aria-label="بستن پیام"
          style={{ background: 'transparent', color: 'var(--text-muted)', border: 'none', cursor: 'pointer' }}
        >
          ✕
        </button>
      )}
    </div>
  );
};

export interface TableColumn<T> {
  header: string;
  render: (item: T) => ReactNode;
}

export interface TableProps<T> {
  columns: TableColumn<T>[];
  data: T[];
  emptyMessage?: string;
}

export function Table<T>({ columns, data, emptyMessage = 'موردی یافت نشد.' }: TableProps<T>) {
  if (data.length === 0) {
    return (
      <div style={{ textAlign: 'center', padding: '32px', color: 'var(--text-muted)' }}>
        {emptyMessage}
      </div>
    );
  }

  return (
    <div className="kp-table-wrapper">
      <table className="kp-table">
        <thead>
          <tr>
            {columns.map((col, idx) => (
              <th key={idx}>{col.header}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {data.map((row, rowIdx) => (
            <tr key={rowIdx}>
              {columns.map((col, colIdx) => (
                <td key={colIdx}>{col.render(row)}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export interface PaginationProps {
  currentPage: number;
  totalPages: number;
  onPageChange: (page: number) => void;
}

export const Pagination: React.FC<PaginationProps> = ({
  currentPage,
  totalPages,
  onPageChange,
}) => {
  return (
    <div className="kp-pagination">
      <button
        className="kp-btn kp-btn-secondary"
        onClick={() => onPageChange(currentPage - 1)}
        disabled={currentPage <= 1}
      >
        قبلی
      </button>
      <span style={{ fontSize: '0.9rem', color: 'var(--text-secondary)' }}>
        {`صفحه ${currentPage.toLocaleString('fa-IR')} از ${totalPages.toLocaleString('fa-IR')}`}
      </span>
      <button
        className="kp-btn kp-btn-secondary"
        onClick={() => onPageChange(currentPage + 1)}
        disabled={currentPage >= totalPages}
      >
        بعدی
      </button>
    </div>
  );
};
