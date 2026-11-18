export type ApiError = { message?: string; fields?: Record<string, string> };

export async function submitCredentials(path: '/api/auth/login' | '/api/auth/register', form: HTMLFormElement) {
  const data = Object.fromEntries(new FormData(form));
  const challenge = await fetch('/api/auth/csrf', { credentials: 'same-origin' });
  if (!challenge.ok) throw new Error('CSRF challenge unavailable');
  const { csrfToken } = await challenge.json();
  const response = await fetch(path, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify(data),
  });
  const result = await response.json();
  return { ok: response.ok, error: result.error as ApiError | undefined };
}
