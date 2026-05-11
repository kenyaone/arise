/**
 * Session Guard — only redirects when the server explicitly confirms the session is gone.
 * Uses retry logic to prevent false logouts from transient server errors.
 */

let _sessionFailCount = 0;

function validateSessionAsync() {
  const publicPages = ['login', 'register', 'datapost', 'pwa_datapost', 'donor_report', 'certificate'];
  const currentPage = new URLSearchParams(window.location.search).get('p') || '';
  if (publicPages.includes(currentPage)) return;

  fetch('/arise/pages/api_session_check.php?t=' + Date.now(), {
    method: 'GET',
    credentials: 'include',
    cache: 'no-store'
  })
  .then(r => {
    if (!r.ok) throw new Error('http_' + r.status);
    return r.json();
  })
  .then(data => {
    if (data.status === 'not_logged_in') {
      // Server confirms session is gone — redirect
      window.location.href = '/arise/login';
    } else {
      _sessionFailCount = 0;
    }
  })
  .catch(() => {
    // Network/server error — only redirect after 3 consecutive failures
    _sessionFailCount++;
    if (_sessionFailCount >= 3) {
      console.warn('Session check failed 3 times in a row, redirecting to login');
      window.location.href = '/arise/login';
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => setTimeout(validateSessionAsync, 3000));
} else {
  setTimeout(validateSessionAsync, 3000);
}

// Re-check every 15 minutes
setInterval(validateSessionAsync, 15 * 60 * 1000);
