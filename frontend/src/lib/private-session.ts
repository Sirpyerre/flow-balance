export function watchPrivateSession(clear: () => void, show: () => void, reset: () => void = clear) {
  const channel = typeof BroadcastChannel === 'undefined' ? undefined : new BroadcastChannel('flow-balance-session');
  let deadline = 0;
  let timer: ReturnType<typeof setTimeout> | undefined;
  let pending: AbortController | undefined;
  let userId: string | undefined;
  const listeners = new AbortController();
  const options = { signal: listeners.signal };

  function leave() {
    pending?.abort();
    clearTimeout(timer);
    reset();
    location.replace('/login');
  }

  function schedule(expiresAt: number) {
    deadline = expiresAt;
    clearTimeout(timer);
    timer = setTimeout(leave, Math.max(0, deadline - Date.now()));
  }

  function updateDeadline(response: Response) {
    const expiresAt = Date.parse(response.headers.get('X-Session-Idle-Expires-At') ?? '');
    if (Number.isFinite(expiresAt)) schedule(expiresAt);
  }

  async function verify() {
    pending?.abort();
    clear();
    const request = new AbortController();
    pending = request;
    try {
      const response = await fetch('/api/auth/session', { cache: 'no-store', signal: request.signal });
      if (request.signal.aborted) return;
      if (response.status === 401) return leave();
      if (!response.ok) throw new Error();
      const { session, user } = await response.json();
      if (request.signal.aborted) return;
      if (userId !== undefined && userId !== user?.id) reset();
      userId = user?.id;
      schedule(Math.min(Date.parse(session.idleExpiresAt), Date.parse(session.absoluteExpiresAt)));
      show();
    } catch {
      if (!request.signal.aborted) {
        const status = document.getElementById('status');
        if (status) status.textContent = 'No se pudo verificar tu sesión. Revisa tu conexión.';
      }
    }
  }

  channel?.addEventListener('message', (event) => {
    if (event.data === 'logged-out') leave();
  }, options);
  addEventListener('pagehide', () => { pending?.abort(); clear(); }, options);
  addEventListener('pageshow', verify, options);
  addEventListener('online', verify, options);
  addEventListener('offline', () => {
    pending?.abort();
    clear();
    const status = document.getElementById('status');
    if (status) status.textContent = 'Sin conexión. Vuelve a conectarte para continuar.';
  }, options);
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { pending?.abort(); clear(); }
    else void verify();
  }, options);

  return {
    verify,
    dispose() { listeners.abort(); pending?.abort(); clearTimeout(timer); channel?.close(); },
    updateDeadline,
    logoutConfirmed() { channel?.postMessage('logged-out'); leave(); },
  };
}
