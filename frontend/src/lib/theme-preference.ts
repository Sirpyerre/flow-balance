type Theme = 'light' | 'dark';

export function readTheme(): Theme {
  return document.documentElement.dataset.theme === 'light' ? 'light' : 'dark';
}

export function applyTheme(theme: Theme) {
  document.documentElement.dataset.theme = theme;
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme === 'light' ? '#f1f2fa' : '#0b0b0f');
  try {
    localStorage.setItem('flow-balance-theme', theme);
  } catch {
    // The active theme still applies if browser storage is unavailable.
  }
}
