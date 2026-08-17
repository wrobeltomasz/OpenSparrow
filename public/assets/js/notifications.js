// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

document.addEventListener('DOMContentLoaded', () => {
    const badge    = document.getElementById('notif-badge');
    const dropdown = document.getElementById('notif-dropdown');
    const list     = document.getElementById('notif-list');
    const wrapper  = document.querySelector('.notifications-wrapper');

    if (!wrapper) return;

    async function checkNotifications() {
        try {
            const result  = await fetch('api/notifications.php?action=get_count', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await result.json();
            if (badge) {
                if (data.count > 0) {
                    badge.style.display = 'block';
                    badge.textContent   = data.count;
                } else {
                    badge.style.display = 'none';
                }
            }
        } catch (error) {
            console.error('Notification check failed:', error);
        }
    }

    function loadNotifications() {
        fetch('api/notifications.php?action=get_list', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            if (!list) return;
            list.innerHTML = '';
            const items = data.notifications;
            if (items && items.length > 0) {
                items.forEach(notification => {
                    const li = document.createElement('li');
                    li.style.cssText = 'padding:10px 15px;border-bottom:1px solid var(--border-light);font-weight:' +
                        (notification.is_read === 't' ? 'normal' : 'bold') + ';';
                    li.textContent = notification.title;
                    if (notification.link) {
                        li.style.cursor = 'pointer';
                        li.title = notification.link;
                        li.addEventListener('click', async () => {
                            await fetch('api/notifications.php?action=mark_read', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
                                },
                                body: JSON.stringify({ id: parseInt(notification.id) })
                            }).catch(() => {});
                            try {
                                const target = new URL(notification.link, window.location.origin);
                                if (target.origin === window.location.origin) {
                                    window.location.href = target.href;
                                }
                            } catch (_) {}
                        });
                    }
                    list.appendChild(li);
                });
            } else {
                const empty = document.createElement('li');
                empty.style.cssText = 'padding:15px;text-align:center;color:var(--muted);';
                empty.textContent = window.I18n ? window.I18n.t('notifications.none') : 'No new notifications';
                list.appendChild(empty);
            }
        })
        .catch(error => console.error('Notification load failed:', error));
    }

    wrapper.addEventListener('click', error => {
        error.stopPropagation();
        if (!dropdown) return;
        if (dropdown.style.display === 'none' || dropdown.style.display === '') {
            dropdown.style.display = 'block';
            loadNotifications();
        } else {
            dropdown.style.display = 'none';
        }
    });

    document.addEventListener('click', event => {
        if (dropdown && !event.target.closest('.notifications-wrapper')) {
            dropdown.style.display = 'none';
        }
    });

    checkNotifications();
    setInterval(checkNotifications, 120000);
});
