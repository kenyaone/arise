/**
 * Session Guard — Validates session and prevents unauthorized page access
 * Runs on every page load to ensure user is still logged in
 */

async function checkSessionValidity() {
  try {
    const response = await fetch('/arise/pages/api_session_check.php?t=' + Date.now(), {
      method: 'GET',
      credentials: 'include'
    });

    const data = await response.json();

    if (data.status !== 'ok') {
      // Session expired or invalid - redirect to login
      console.warn('Session invalid, redirecting to login');
      window.location.href = '/arise/login';
      return false;
    }

    return true;
  } catch (e) {
    // Network error - assume session might be ok, but log it
    console.warn('Could not validate session:', e);
    return true;
  }
}

// Validate session on page load if user should be logged in
document.addEventListener('DOMContentLoaded', function() {
  // Don't check on login, register, datapost, or public pages
  const publicPages = ['login', 'register', 'datapost', 'pwa_datapost', 'donor_report', 'certificate'];
  const urlParams = new URLSearchParams(window.location.search);
  const currentPage = urlParams.get('p') || '';

  if (!publicPages.includes(currentPage)) {
    // Page requires login - validate session
    checkSessionValidity().catch(() => {
      // If validation fails completely, go to login
      window.location.href = '/arise/login';
    });
  }
});

// Also check session periodically (every 5 minutes)
setInterval(() => {
  const urlParams = new URLSearchParams(window.location.search);
  const currentPage = urlParams.get('p') || '';
  const publicPages = ['login', 'register', 'datapost', 'pwa_datapost', 'donor_report', 'certificate'];

  if (!publicPages.includes(currentPage) && document.hidden !== true) {
    checkSessionValidity();
  }
}, 5 * 60 * 1000);
