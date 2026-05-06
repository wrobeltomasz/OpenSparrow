import { applyDrillDown, firstEqCondition } from '../drill-down.js';
import { WidgetRegistry } from '../registry.js';

function renderKPICard(widget) {
    const wrapper = document.createElement('div');
    wrapper.className = 'kpi-card';
    wrapper.style.borderLeft = `4px solid ${widget.color || '#333'}`;

    const info = document.createElement('div');
    const title = document.createElement('div');
    title.className = 'kpi-title';
    title.textContent = widget.title;
    const value = document.createElement('div');
    value.className = 'kpi-value';
    value.textContent = widget.data ?? 0;
    info.append(title, value);

    const iconContainer = document.createElement('div');
    iconContainer.className = 'kpi-icon';
    if (widget.icon?.trim()) {
        const img = document.createElement('img');
        img.src = widget.icon;
        img.alt = '';
        iconContainer.appendChild(img);
    }

    wrapper.append(info, iconContainer);
    const fc = firstEqCondition(widget.query?.conditions);
    applyDrillDown(wrapper, widget.table, fc?.col ?? null, fc?.val ?? null);
    return wrapper;
}

WidgetRegistry.register('kpi_card', renderKPICard);
export { renderKPICard };
