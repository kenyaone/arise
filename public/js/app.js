/**
 * ARISE - App JavaScript
 */

// Mobile menu toggle
document.addEventListener('DOMContentLoaded', function() {
    // Close mobile menu when clicking a link
    document.querySelectorAll('.nav-links a').forEach(function(link) {
        link.addEventListener('click', function() {
            document.querySelector('.nav-links').classList.remove('open');
        });
    });
});
