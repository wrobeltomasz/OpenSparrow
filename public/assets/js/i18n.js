// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

const I18n = (() => {
    let _bundle = {};
    let _locale = 'en';

    async function load() {
        _locale = document.documentElement.lang || 'en';
        try {
            const result = await fetch('/api.php?action=i18n_bundle', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (result.ok) {
                _bundle = await result.json();
            }
        } catch (error) {
            console.warn('i18n bundle load failed', error);
        }
    }

    function t(key, vars = {}, count = null) {
        let value = _bundle[key];

        if (value === undefined) {
            if (typeof APP_ENV !== 'undefined' && APP_ENV === 'development') {
                console.warn(`i18n missing: ${key}`);
            }
            return key;
        }

        if (count !== null) {
            let forms = value;
            if (typeof value === 'string') {
                try { forms = JSON.parse(value); } catch {  }
            }
            if (forms && typeof forms === 'object' && !Array.isArray(forms)) {
                const form = _pluralForm(_locale, count);
                value = forms[form] ?? forms.other ?? Object.values(forms)[0];
            }
        }

        return String(value).replace(/\{(\w+)\}/g, (_, k) =>
            Object.prototype.hasOwnProperty.call(vars, k) ? String(vars[k]) : `{${k}}`
        );
    }

    function locale() {
        return _locale;
    }

    function _pluralForm(loc, n) {
        const absoluteCount = Math.abs(n);
        if (loc === 'pl') {
            if (absoluteCount === 1) return 'one';
            const m10 = absoluteCount % 10, m100 = absoluteCount % 100;
            if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return 'few';
            return 'many';
        }
        if (loc === 'ru' || loc === 'uk') {
            const m10 = absoluteCount % 10, m100 = absoluteCount % 100;
            if (m10 === 1 && m100 !== 11) return 'one';
            if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return 'few';
            return 'many';
        }
        return absoluteCount === 1 ? 'one' : 'other';
    }

    return { load, t, locale };
})();

window.I18n = I18n;

export { I18n };
