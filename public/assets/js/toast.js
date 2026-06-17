// assets/js/toast.js — showToast(message, variant, duration): shared non-blocking toast used across FE modules.
// Stacks bottom-right, auto-dismisses (default 4s). Variants: error/success/info.
export function showToast(message, variant = 'error', duration = 4000) {
    const colors = {
        error:   { bg: '#fee2e2', fg: '#991b1b', border: '#fca5a5' },
        success: { bg: '#dcfce7', fg: '#166534', border: '#86efac' },
        info:    { bg: '#e0f2fe', fg: '#075985', border: '#7dd3fc' },
    }[variant] || { bg: '#e2e8f0', fg: '#0f172a', border: '#cbd5e1' };

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
