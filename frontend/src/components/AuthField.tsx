import { useState } from 'react';

interface AuthFieldProps {
  name: string;
  label: string;
  type: string;
  autoComplete: string;
  placeholder: string;
  error?: string;
}

export default function AuthField({ name, label, type, autoComplete, placeholder, error }: AuthFieldProps) {
  const [visible, setVisible] = useState(false);
  const isPassword = type === 'password';
  const controlLabel = name === 'passwordConfirmation' ? 'confirmación de contraseña' : 'contraseña';
  const action = visible ? 'Ocultar' : 'Mostrar';

  return <div className="field">
    <label htmlFor={name}>{label}</label>
    <div className={isPassword ? 'password-control' : undefined}>
      <input id={name} name={name} type={isPassword && visible ? 'text' : type}
        autoComplete={autoComplete} placeholder={placeholder} required
        aria-invalid={Boolean(error)} aria-describedby={error ? `${name}-error` : undefined} />
      {isPassword && <button type="button" aria-controls={name} aria-label={`${action} ${controlLabel}`}
        onClick={() => setVisible(!visible)}>
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
          strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
          <path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6S2 12 2 12Z" />
          <circle cx="12" cy="12" r="2.5" />
          {!visible && <path d="m4 4 16 16" />}
        </svg>
      </button>}
    </div>
    {error && <small className="field-error" id={`${name}-error`}>{error}</small>}
  </div>;
}
