import React, { InputHTMLAttributes } from 'react';

export interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  error?: string;
}

export const Input: React.FC<InputProps> = ({
  label,
  error,
  id,
  className = '',
  ...props
}) => {
  return (
    <div className="kp-input-group">
      {label && (
        <label htmlFor={id} className="kp-label">
          {label}
        </label>
      )}
      <input
        id={id}
        className={`kp-input ${error ? 'kp-input-error' : ''} ${className}`}
        aria-invalid={error ? 'true' : undefined}
        aria-describedby={error ? `${id}-error` : undefined}
        {...props}
      />
      {error && (
        <span id={`${id}-error`} className="kp-error-text">
          {error}
        </span>
      )}
    </div>
  );
};
