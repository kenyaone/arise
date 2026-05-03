// ARISE Interactive Lesson Video Player Helper
// Include this in interactive HTML modules to display videos consistently at the top

(function() {
  'use strict';

  // Wait for DOM to be ready
  function initVideoPlayer() {
    // Check if ARISE_VIDEO_URL is set (injected by index.php for interactive lessons)
    if (typeof window.ARISE_VIDEO_URL === 'undefined' || !window.ARISE_VIDEO_URL) {
      return; // No video for this lesson
    }

    // Find or create video container at the top of the page
    let videoContainer = document.querySelector('[data-arise-video-container]');

    if (!videoContainer) {
      videoContainer = document.createElement('div');
      videoContainer.setAttribute('data-arise-video-container', '');
      videoContainer.style.cssText = `
        background: #0a0f1e;
        padding: 20px;
        margin: 0 0 30px 0;
        border-radius: 10px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
      `;

      // Insert at top of body
      if (document.body.firstChild) {
        document.body.insertBefore(videoContainer, document.body.firstChild);
      } else {
        document.body.appendChild(videoContainer);
      }
    }

    // Create video player HTML
    const videoHtml = `
      <div style="max-width: 100%; background: #1f2937; border-radius: 8px; overflow: hidden;">
        <video
          style="width: 100%; height: auto; display: block; background: #000;"
          controls
          controlsList="nodownload"
          crossorigin="anonymous"
        >
          <source src="${escapeHtml(window.ARISE_VIDEO_URL)}" type="video/mp4">
          Your browser does not support HTML5 video.
        </video>
        <div style="padding: 12px 16px; background: #111827; color: #e2e8f0; font-size: 0.9rem;">
          💡 <strong>Tip:</strong> Watch the video above, then complete the activities and quiz below.
        </div>
      </div>
    `;

    videoContainer.innerHTML = videoHtml;
  }

  // Helper to escape HTML
  function escapeHtml(text) {
    const map = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
  }

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initVideoPlayer);
  } else {
    initVideoPlayer();
  }
})();
