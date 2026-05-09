// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.

document.addEventListener('DOMContentLoaded', () => {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const searchToggle  = document.getElementById('searchToggle');
    const sidebar       = document.getElementById('menu');
    const headerEl      = document.querySelector('header');
    if (!sidebarToggle || !sidebar) return;

    const isMobile = () => window.innerWidth <= 768;

    /* Overlay element injected once; toggled via mob-visible class */
    const overlay = document.createElement('div');
    overlay.id = 'mobOverlay';
    overlay.className = 'mob-overlay';
    document.body.appendChild(overlay);

    function openSidebar() {
        sidebar.classList.add('mob-open');
        overlay.classList.add('mob-visible');
        sidebarToggle.setAttribute('aria-expanded', 'true');
    }

    function closeSidebar() {
        sidebar.classList.remove('mob-open');
        overlay.classList.remove('mob-visible');
        sidebarToggle.setAttribute('aria-expanded', 'false');
    }

    function toggleDesktopCollapse() {
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('menuCollapsed', sidebar.classList.contains('collapsed'));
    }

    function restoreDesktopState() {
        const saved = localStorage.getItem('menuCollapsed');
        if (saved === 'true') sidebar.classList.add('collapsed');
        else sidebar.classList.remove('collapsed');
    }

    /* Sidebar hamburger: off-canvas on mobile, collapse on desktop */
    sidebarToggle.addEventListener('click', () => {
        if (isMobile()) {
            sidebar.classList.contains('mob-open') ? closeSidebar() : openSidebar();
            if (headerEl) headerEl.classList.remove('mob-search-open');
        } else {
            toggleDesktopCollapse();
        }
    });

    /* Search drawer toggle (button only visible on mobile) */
    if (searchToggle) {
        searchToggle.addEventListener('click', () => {
            if (headerEl) headerEl.classList.toggle('mob-search-open');
            closeSidebar();
        });
    }

    /* Tap overlay to close sidebar */
    overlay.addEventListener('click', closeSidebar);

    /* Close sidebar when a nav link is clicked (mobile navigation) */
    sidebar.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            if (isMobile()) closeSidebar();
        });
    });

    /* Desktop: restore saved collapsed state */
    if (!isMobile()) restoreDesktopState();

    /* Resize: clean up mobile state when switching back to desktop */
    window.addEventListener('resize', () => {
        if (!isMobile()) {
            closeSidebar();
            restoreDesktopState();
        }
    });
});
