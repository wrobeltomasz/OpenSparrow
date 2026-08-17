// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

document.querySelectorAll('.nav-section-header').forEach(function(header) {
    header.addEventListener('click', function() {
        header.closest('.nav-section').classList.toggle('open');
    });
});

const navEdgeToggle = document.getElementById('navEdgeToggle');
const adminNav      = document.getElementById('adminNav');
const adminLayout   = document.querySelector('.admin-layout');

function toggleNav() {
    const collapsed = adminNav.classList.toggle('collapsed');
    adminLayout.classList.toggle('nav-collapsed', collapsed);
    navEdgeToggle.innerHTML = collapsed ? '&#8250;' : '&#8249;';
}
navEdgeToggle.addEventListener('click', toggleNav);

const buttonLogout = document.getElementById('btnLogout');
if (buttonLogout) {
    buttonLogout.addEventListener('click', function() {
        window.location.href = window.LOGOUT_URL;
    });
}

const breadcrumbCurrent = document.getElementById('breadcrumbCurrent');
document.querySelectorAll('.admin-tab[data-file]').forEach(function(tab) {
    tab.addEventListener('click', function() {
        breadcrumbCurrent.textContent = window.BREADCRUMB_LABELS[tab.dataset.file] || tab.dataset.file;
    });
});
