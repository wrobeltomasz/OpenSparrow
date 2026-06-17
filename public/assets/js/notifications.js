// This file is part of OpenSparrow - https://opensparrow.org
// Licensed under LGPL v3. See LICENCE file for details.
//
// notifications.js — Header notification bell. Polls api/notifications.php (get_count for the unread badge, get_list for the dropdown) and marks items read. Runs on DOMContentLoaded.

document.addEventListener('DOMContentLoaded', () => {
    const badge    = document.getElementById('notif-badge');
    const dropdown = document.getElementById('notif-dropdown');
    const list     = document.getElementById('notif-list');
    const wrapper  = document.querySelector('.notifications-wrapper');

    if (!wrapper) return;

    async function checkNotifications() {
        try {
            const res  = await fetch('api/notifications.php?action=get_count', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();
            if (badge) {
                if (data.count > 0) {
                    badge.style.display = 'block';
                    badge.textContent   = data.count;
                } else {
                    badge.style.display = 'none';
                }
            }
        } catch (e) {
            console.error('Notification check failed:', e);
        }
    }

    function loadNotifications() {
        fetch('api/notifications.php?action=get_list', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            if (!list) return;
            list.innerHTML = '';
            const items = data.notifications;
            if (items && items.length > 0) {
                items.forEach(n => {
                    const li = document.createElement('li');
                    li.style.cssText = 'padding:10px 15px;border-bottom:1px solid #f0f0f0;font-weight:' +
                        (n.is_read === 't' ? 'normal' : 'bold') + ';';
                    li.textContent = n.title;
                    if (n.link) {
                        li.style.cursor = 'pointer';
                        li.title = n.link;
                        li.addEventListener('click', async () => {
                            await fetch('api/notifications.php?action=mark_read', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
                                },
                                body: JSON.stringify({ id: parseInt(n.id) })
                            }).catch(() => {});
                            try {
                                const target = new URL(n.link, window.location.origin);
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
                empty.style.cssText = 'padding:15px;text-align:center;color:#777;';
                empty.textContent = window.I18n ? window.I18n.t('notifications.none') : 'No new notifications';
                list.appendChild(empty);
            }
        })
        .catch(e => console.error('Notification load failed:', e));
    }

    wrapper.addEventListener('click', e => {
        e.stopPropagation();
        if (!dropdown) return;
        if (dropdown.style.display === 'none' || dropdown.style.display === '') {
            dropdown.style.display = 'block';
            loadNotifications();
        } else {
            dropdown.style.display = 'none';
        }
    });

    document.addEventListener('click', e => {
        if (dropdown && !e.target.closest('.notifications-wrapper')) {
            dropdown.style.display = 'none';
        }
    });

    checkNotifications();
    setInterval(checkNotifications, 120000);
});
