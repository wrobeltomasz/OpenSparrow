/**
 * Returns first condition with op '=' from conditions array, or null.
 * Used by stat/kpi cards to navigate to pre-filtered table view.
 */
export function firstEqCondition(conditions) {
    if (!Array.isArray(conditions)) return null;
    return conditions.find(c => c.op === '=' && c.col && c.val !== undefined && c.val !== null) ?? null;
}

export function applyDrillDown(element, table, filterCol = null, filterVal = null) {
    element.style.cursor = 'pointer';
    element.title = 'Click to view details';
    element.addEventListener('click', () => {
        let url = `index.php?table=${encodeURIComponent(table)}`;
        if (filterCol) {
            const val = filterVal !== null && filterVal !== undefined ? filterVal : '';
            url += `&filter_col=${encodeURIComponent(filterCol)}&filter_val=${encodeURIComponent(val)}`;
        }
        window.location.href = url;
    });
    element.addEventListener('mouseenter', () => { element.style.opacity = '0.8'; });
    element.addEventListener('mouseleave', () => { element.style.opacity = '1'; });
}
