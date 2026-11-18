import { useState, type FormEvent } from 'react';

import { submitCredentials, type ApiError } from '../lib/auth-api';

export default function RegisterForm() {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<ApiError>();

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (pending) return;
    const form = event.currentTarget;
    setPending(true);
    setError(undefined);
    try {
      const result = await submitCredentials('/api/auth/register', form);
      if (result.ok) {
        window.location.assign('/dashboard');
        return;
      }
      setError(result.error);
      const firstField = Object.keys(result.error?.fields ?? {})[0];
      if (firstField) (form.elements.namedItem(firstField) as HTMLInputElement | null)?.focus();
    } catch {
      setError({ message: 'No se pudo enviar el registro. Intenta de nuevo.' });
    } finally {
      setPending(false);
    }
  }

  const fields = [
    { name: 'name', label: 'Nombre', type: 'text', autoComplete: 'name', placeholder: 'Tu nombre' },
    { name: 'email', label: 'Correo electrónico', type: 'email', autoComplete: 'email', placeholder: 'tu@correo.com' },
    { name: 'password', label: 'Contraseña', type: 'password', autoComplete: 'new-password', placeholder: '••••••••' },
    { name: 'passwordConfirmation', label: 'Confirma tu contraseña', type: 'password', autoComplete: 'new-password', placeholder: '••••••••' },
  ];

  return <form method="post" onSubmit={submit} aria-busy={pending} noValidate>
    {fields.map(({ name, label, type, autoComplete, placeholder }) => <label key={name}>
      {label}
      <input name={name} type={type} autoComplete={autoComplete} placeholder={placeholder} required
        aria-invalid={Boolean(error?.fields?.[name])}
        aria-describedby={error?.fields?.[name] ? `${name}-error` : undefined} />
      {error?.fields?.[name] && <small className="field-error" id={`${name}-error`}>{error.fields[name]}</small>}
    </label>)}
    {error?.message && <p role="alert">{error.message}</p>}
    <button type="submit" disabled={pending} aria-label={pending ? 'Procesando solicitud' : undefined}>
      {pending ? <span className="spinner" aria-hidden="true" /> : 'Crear cuenta'}
    </button>
  </form>;
}
