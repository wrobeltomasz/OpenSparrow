// assets/js/grid/dom.js — small shared DOM-building helpers reused across grid renderers
// (row-action icon buttons, inline text-cell links).

export function makeIconButton({ cy, title, icon, className = 'btn-icon', onClick }) {
    const btn = document.createElement('button');
    btn.className = className;
    btn.dataset.cy = cy;
    btn.title = title;
    const img = document.createElement('img');
    img.src = icon;
    img.alt = title;
    btn.appendChild(img);
    btn.addEventListener('click', onClick);
    return btn;
}

export function makeInlineLink(href, text, { newTab = false, onClick } = {}) {
    const a = document.createElement('a');
    a.href = href;
    if (newTab) a.target = '_blank';
    a.className = 'cell-link';
    a.textContent = text;
    if (onClick) a.addEventListener('click', onClick);
    return a;
}
