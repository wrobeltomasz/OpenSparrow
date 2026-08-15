// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

export function showToast(message, variant = 'error', duration = 4000) {
    const colors = {
        error:   { bg: 'var(--error-light)', fg: 'var(--error)', border: 'var(--error)' },
        success: { bg: 'var(--ok-light)', fg: 'var(--ok)', border: 'var(--accent-mid)' },
        info:    { bg: 'var(--accent-light)', fg: 'var(--accent)', border: 'var(--accent-mid)' },
    }[variant] || { bg: 'var(--border-light)', fg: 'var(--text)', border: 'var(--border)' };

    const toast = document.createElement('div');
    toast.style.cssText = [
        'position:fixed', 'bottom:24px', 'right:24px', 'z-index:9999',
        `padding:12px 18px`, `background:${colors.bg}`, `color:${colors.fg}`,
        `border:1px solid ${colors.border}`, 'border-radius:8px',
        'font-size:14px', 'font-weight:500',
        'box-shadow:0 4px 12px rgba(0,0,0,0.12)',
        'max-width:360px', 'line-height:1.4', 'transition:opacity .3s',
    ].join(';');
    toast.textContent = message;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}
