import { applyDrillDown, firstEqCondition } from '../drill-down.js';
import { WidgetRegistry } from '../registry.js';

function renderStatCard(widget) {
    const wrapper = document.createElement('div');
    wrapper.className = 'dash-stat-card';
    if (widget.color) {
        wrapper.style.backgroundColor = widget.color;
        wrapper.style.color = '#ffffff';
    }

    const value = document.createElement('div');
    value.className = 'stat-value';
    value.textContent = widget.data ?? 0;

    const title = document.createElement('div');
    title.className = 'stat-title';
    title.textContent = widget.title;

    wrapper.append(value, title);
    const fc = firstEqCondition(widget.query?.conditions);
    applyDrillDown(wrapper, widget.table, fc?.col ?? null, fc?.val ?? null);
    wrapper.addEventListener('mouseenter', () => { wrapper.style.transform = 'translateY(-2px)'; });
    wrapper.addEventListener('mouseleave', () => { wrapper.style.transform = 'none'; });
    return wrapper;
}

WidgetRegistry.register('stat_card', renderStatCard);
export { renderStatCard };
