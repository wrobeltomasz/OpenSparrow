// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

export function getCsrfToken() {
    return window.CSRF_TOKEN
        ?? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        ?? '';
}
