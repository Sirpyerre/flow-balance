export async function registerServiceWorker() {
  if (!window.isSecureContext || !('serviceWorker' in navigator)) return;
  try {
    await navigator.serviceWorker.register('/service-worker.js', { scope: '/', updateViaCache: 'none' });
  } catch (error) {
    console.warn('No se pudo activar la PWA de Flow Balance.', error);
  }
}
