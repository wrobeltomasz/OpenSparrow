// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

document.addEventListener('DOMContentLoaded', () => {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const searchToggle  = document.getElementById('searchToggle');
    const sidebar       = document.getElementById('menu');
    const headerElement      = document.querySelector('header');
    if (!sidebarToggle || !sidebar) return;

    const isMobile = () => window.innerWidth <= 768;

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

    sidebarToggle.addEventListener('click', () => {
        if (isMobile()) {
            sidebar.classList.contains('mob-open') ? closeSidebar() : openSidebar();
            if (headerElement) headerElement.classList.remove('mob-search-open');
        } else {
            toggleDesktopCollapse();
        }
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

    sidebar.addEventListener('mouseover', (e) => {
        const link = e.target.closest('.custom-nav-link');
        if (link === tipTarget) return;
        tipTarget = link;
        if (link) showNavTip(link);
        else hideNavTip();
    });

    sidebar.addEventListener('mouseleave', hideNavTip);
    sidebar.addEventListener('click', hideNavTip);

    sidebar.addEventListener('focusin', (e) => {
        const link = e.target.closest('.custom-nav-link');
        if (link === tipTarget) return;
        tipTarget = link;
        if (link) showNavTip(link);
        else hideNavTip();
    });

    sidebar.addEventListener('focusout', (e) => {
        if (!sidebar.contains(e.relatedTarget)) hideNavTip();
    });
});
