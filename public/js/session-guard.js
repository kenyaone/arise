/**
 * Session Guard — Client-side session validation
 * Lightweight check that doesn't block page rendering
 * Server-side session checks happen first on all pages
 */

// Validate session in background without blocking page render
function validateSessionAsync() {
  // Don't check on login, register, datapost, or public pages
  const publicPages = ['login', 'register', 'datapost', 'pwa_datapost', 'donor_report', 'certificate'];
  const urlParams = new URLSearchParams(window.location.search);
  const currentPage = urlParams.get('p') || '';

  if (publicPages.includes(currentPage)) return;

  // Use a lightweight HEAD request to check if still authenticated
  // (much faster than JSON fetch)
  fetch('/arise/pages/api_session_check.php?t=' + Date.now(), {
    method: 'GET',
    credentials: 'include',
    cache: 'no-store'
  }).then(r => {
    if (!r.ok) {
      console.warn('Session check failed, redirecting to login');
      window.location.href = '/arise/login';
    }
  }).catch(() => {
    // Network error - don't redirect, assume session is ok
    // Server will handle actual session validation
  });
}

// Run async validation after page is fully rendered (don't block)
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    setTimeout(validateSessionAsync, 2000);
  });
} else {
  setTimeout(validateSessionAsync, 2000);
}

// Also validate every 10 minutes
setInterval(validateSessionAsync, 10 * 60 * 1000);
