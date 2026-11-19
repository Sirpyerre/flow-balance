export type ApiError = { message?: string; fields?: Record<string, string> };

export async function csrfToken(signal?: AbortSignal) {
  const challenge = await fetch('/api/auth/csrf', { credentials: 'same-origin', cache: 'no-store', signal });
  if (!challenge.ok) throw new Error('CSRF challenge unavailable');
  const result = await challenge.json();
  return result.csrfToken as string;
}

export async function submitCredentials(path: '/api/auth/login' | '/api/auth/register', form: HTMLFormElement) {
  const data = Object.fromEntries(new FormData(form));
  const response = await fetch(path, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': await csrfToken() },
    body: JSON.stringify(data),
  });
  const result = await response.json();
  return { ok: response.ok, error: result.error as ApiError | undefined };
}
