// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

document.addEventListener('DOMContentLoaded', () => {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const mobileToggle  = document.getElementById('sidebarToggleMobile');
    const searchToggle  = document.getElementById('searchToggle');
    const sidebar       = document.getElementById('menu');
    const headerElement      = document.querySelector('header');

    const collapseButtons = [sidebarToggle, mobileToggle].filter(Boolean);
    if (collapseButtons.length === 0 || !sidebar) return;

    const isMobile = () => window.innerWidth <= 768;

    const overlay = document.createElement('div');
    overlay.id = 'mobOverlay';
    overlay.className = 'mob-overlay';
    document.body.appendChild(overlay);

    function openSidebar() {
        sidebar.classList.add('mob-open');
        overlay.classList.add('mob-visible');
        collapseButtons.forEach((button) => button.setAttribute('aria-expanded', 'true'));
    }

    function closeSidebar() {
        sidebar.classList.remove('mob-open');
        overlay.classList.remove('mob-visible');
        collapseButtons.forEach((button) => button.setAttribute('aria-expanded', 'false'));
    }

    function toggleDesktopCollapse() {
        sidebar.classList.toggle('collapsed');
        const collapsed = sidebar.classList.contains('collapsed');
        localStorage.setItem('menuCollapsed', collapsed);
        if (sidebarToggle) {
            sidebarToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
    }

    function restoreDesktopState() {
        const saved = localStorage.getItem('menuCollapsed');
        if (saved === 'true') sidebar.classList.add('collapsed');
        else sidebar.classList.remove('collapsed');
        if (sidebarToggle) {
            sidebarToggle.setAttribute('aria-expanded', saved === 'true' ? 'false' : 'true');
        }
    }

    collapseButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (isMobile()) {
                sidebar.classList.contains('mob-open') ? closeSidebar() : openSidebar();
                if (headerElement) headerElement.classList.remove('mob-search-open');
            } else {
                toggleDesktopCollapse();
            }
        });
    });

    if (searchToggle) {
        searchToggle.addEventListener('click', () => {
            if (headerElement) headerElement.classList.toggle('mob-search-open');
            closeSidebar();
        });
    }

    overlay.addEventListener('click', closeSidebar);

    sidebar.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            if (isMobile()) closeSidebar();
        });
    });

    if (!isMobile()) restoreDesktopState();

    window.addEventListener('resize', () => {
        if (!isMobile()) {
            closeSidebar();
            restoreDesktopState();
        }
    });

    const navTip = document.createElement('div');
    navTip.id = 'nav-tip';
    navTip.setAttribute('aria-hidden', 'true');
    document.body.appendChild(navTip);

    let tipTarget = null;

    function showNavTip(link) {
        if (!sidebar.classList.contains('collapsed') || isMobile()) return;
        const label = link.dataset.tooltip;
        if (!label) return;
        const rect = link.getBoundingClientRect();
        navTip.textContent = label;
        navTip.style.top  = (rect.top + rect.height / 2) + 'px';
        navTip.style.left = (rect.right + 10) + 'px';
        navTip.classList.add('nav-tip-visible');
    }

    function hideNavTip() {
        navTip.classList.remove('nav-tip-visible');
        tipTarget = null;
    }

    sidebar.addEventListener('mouseover', (event) => {
        const link = event.target.closest('.custom-nav-link');
        if (link === tipTarget) return;
        tipTarget = link;
        if (link) showNavTip(link);
        else hideNavTip();
    });

    sidebar.addEventListener('mouseleave', hideNavTip);
    sidebar.addEventListener('click', hideNavTip);

    sidebar.addEventListener('focusin', (event) => {
        const link = event.target.closest('.custom-nav-link');
        if (link === tipTarget) return;
        tipTarget = link;
        if (link) showNavTip(link);
        else hideNavTip();
    });

    sidebar.addEventListener('focusout', (event) => {
        if (!sidebar.contains(event.relatedTarget)) hideNavTip();
    });
});
