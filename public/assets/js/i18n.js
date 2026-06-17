// assets/js/i18n.js — Client-side i18n bridge (I18n.load/t with pluralisation + {var} interpolation); also sets window.I18n for non-module scripts.

/**
 * i18n JS bridge — mirrors includes/i18n.php for client-side translation.
 * Loads the flat bundle from /api.php?action=i18n_bundle (auth required).
 * Sets window.I18n for non-module scripts.
 *
 * Usage:
 *   import { I18n } from './i18n.js';
 *   await I18n.load();
 *   I18n.t('common.save')
 *   I18n.t('grid.showing', { from: 1, to: 10, total: 42 })
 *   I18n.t('files.count', { count: 3 }, 3)
 */
const I18n = (() => {
    let _bundle = {};
    let _locale = 'en';

    async function load() {
        _locale = document.documentElement.lang || 'en';
        try {
            const res = await fetch('/api.php?action=i18n_bundle', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (res.ok) {
                _bundle = await res.json();
            }
        } catch (err) {
            console.warn('i18n bundle load failed', err);
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
                try { forms = JSON.parse(value); } catch { /* scalar string, not plural */ }
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
        const abs = Math.abs(n);
        if (loc === 'pl') {
            if (abs === 1) return 'one';
            const m10 = abs % 10, m100 = abs % 100;
            if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return 'few';
            return 'many';
        }
        if (loc === 'ru' || loc === 'uk') {
            const m10 = abs % 10, m100 = abs % 100;
            if (m10 === 1 && m100 !== 11) return 'one';
            if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return 'few';
            return 'many';
        }
        return abs === 1 ? 'one' : 'other';
    }

    return { load, t, locale };
})();

window.I18n = I18n;

export { I18n };
