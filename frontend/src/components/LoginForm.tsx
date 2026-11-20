import { useState, type FormEvent } from 'react';

import AuthField from './AuthField';
import { submitCredentials, type ApiError } from '../lib/auth-api';

export default function LoginForm() {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<ApiError>();

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (pending) return;
    const form = event.currentTarget;
    setPending(true);
    setError(undefined);
    try {
      const result = await submitCredentials('/api/auth/login', form);
      if (result.ok) {
        window.location.assign('/dashboard');
        return;
      }
      setError(result.error);
      const firstField = Object.keys(result.error?.fields ?? {})[0];
      if (firstField) (form.elements.namedItem(firstField) as HTMLInputElement | null)?.focus();
    } catch {
      setError({ message: 'No se pudo iniciar sesión. Intenta de nuevo.' });
    } finally {
      setPending(false);
    }
  }

  const fields = [
    { name: 'email', label: 'Correo electrónico', type: 'email', autoComplete: 'username', placeholder: 'tu@correo.com' },
    { name: 'password', label: 'Contraseña', type: 'password', autoComplete: 'current-password', placeholder: '••••••••' },
  ];

  return <form method="post" onSubmit={submit} aria-busy={pending} noValidate>
    {fields.map(field => <AuthField key={field.name} {...field} error={error?.fields?.[field.name]} />)}
    {error?.message && <p role="alert">{error.message}</p>}
    <button type="submit" className="btn btn-primary" aria-busy={pending} disabled={pending} aria-label={pending ? 'Procesando solicitud' : undefined}>
      {pending ? null : 'Iniciar sesión'}
    </button>
  </form>;
}
