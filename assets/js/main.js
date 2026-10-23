/**
 * Main JavaScript Application
 * Handles theme toggle (Light / Dark mode), responsive interactions, and utilities.
 */

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initMobileNav();
});

/**
 * Theme initialization and toggle (Light / Dark)
 */
function initTheme() {
    const themeToggleBtn = document.getElementById('theme-toggle');
    const storedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

    // Apply stored theme or system preference
    if (storedTheme === 'dark' || (!storedTheme && prefersDark)) {
        document.documentElement.classList.add('dark');
        updateToggleIcon(true);
    } else {
        document.documentElement.classList.remove('dark');
        updateToggleIcon(false);
    }

    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            updateToggleIcon(isDark);
        });
    }
}

function updateToggleIcon(isDark) {
    const iconSpan = document.getElementById('theme-toggle-icon');
    if (!iconSpan) return;

    if (isDark) {
        // Sun icon (when currently in dark mode, click to switch to light)
        iconSpan.innerHTML = `
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="5"></circle>
                <line x1="12" y1="1" x2="12" y2="3"></line>
                <line x1="12" y1="21" x2="12" y2="23"></line>
                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                <line x1="1" y1="12" x2="3" y2="12"></line>
                <line x1="21" y1="12" x2="23" y2="12"></line>
                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
            </svg>
        `;
    } else {
        // Moon icon (when in light mode, click to switch to dark)
        iconSpan.innerHTML = `
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
            </svg>
        `;
    }
}

/**
 * Mobile Navigation (hamburger)
 */
function initMobileNav() {
    const menuBtn = document.getElementById('mobile-menu-btn');
    const navLinks = document.getElementById('nav-links');

    if (menuBtn && navLinks) {
        menuBtn.addEventListener('click', () => {
            navLinks.classList.toggle('active');
        });
    }
}
